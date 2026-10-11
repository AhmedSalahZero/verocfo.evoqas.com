<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  OtherPaymentService
//
//  Other Payment — money paid to a Shareholder, Employee, Sister
//  Company, Customer (refund), or Other partner. Payable-side mirror
//  of OtherCollectionService, itself the same shape as
//  SupplierPaymentService with settlement/allocation removed. See
//  VeroCFO_Other_Payment_Design_v1_0.md, the authoritative reference.
//
//  ONE ATOMIC ACTION — record(). Posts immediately, no draft-then-
//  post two-step, same posture as Supplier Payment / Other Collection.
//
//  SOURCE RESOLUTION — identical branching to SupplierPaymentService:
//    cash_payment       → safe_account_id (131xxx sub-account)
//    outgoing_transfer  → a BankAccount or a CreditFacility (paying
//      an OD down directly) — when it's a CreditFacility, this also
//      records a real drawdown against it via FacilitySettlementEngine
//      (Bible Rule 34), the same way SupplierPaymentService already
//      does. 2026-09 fix — previously the GL entry posted correctly
//      but the facility's own sub-ledger never learned about it.
//    payable_cheque     → always 222001 Notes Payable — Suppliers at
//      issue; the source account is fixed HERE, at issue time (same
//      asymmetry as Supplier Payment vs. Customer Collection's
//      cheque — see migration 000174 header). The cheque's own
//      onward journey (Mark as Paid / Reset) lives in
//      ChequePayableService, shared with Supplier Payment cheques
//      (see migration 000175) — not duplicated for Other Payment.
//
//  DEBIT SIDE — the one place this genuinely differs from Supplier
//  Payment: there is no single system-resolved debit account (no AP).
//  The user picks debit_account_id directly from the Chart of
//  Accounts (the frontend suggests a default based on Partner Type —
//  see OtherPayment::DEFAULT_DEBIT_ACCOUNT_CODE — but never enforces
//  it). The posted entry is always exactly two lines:
//  Dr debit_account_id / Cr [source], in full.
//
//  @layer    Service
//  @depends  OtherPayment, ChequePayable, ChartOfAccount,
//            FinancialInstitutions\BankAccount, FinancialInstitutions\CreditFacility,
//            FinancialInstitutions\FacilityWithdrawal,
//            JournalEntryService, FiscalYearService, ChequePayableService,
//            FacilitySettlementEngine, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\ChequePayable;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityWithdrawal;
use App\Models\JournalEntry;
use App\Models\OtherPayment;
use App\Models\User;
use App\Services\FinancialInstitutions\FacilitySettlementEngine;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OtherPaymentService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
        private ChequePayableService $chequePayableService,
        private FacilitySettlementEngine $settlementEngine,
        private CashAvailabilityService $cashAvailability,
    ) {
    }

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return OtherPayment::with(['partner:id,name_en,name_ar', 'debitAccount:id,code,name_en', 'currency:id,code', 'cheque'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('payment_code', 'like', "%{$s}%"))
            ->when($filters['partner_type'] ?? null, fn ($q, $t) => $q->where('partner_type', $t))
            ->when($filters['money_type'] ?? null, fn ($q, $t) => $q->where('money_type', $t))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Record — the one atomic action ───────────────────────────

    public function record(int $companyId, array $data, User $actor): OtherPayment
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $data['company_id']        = $companyId;
            $data['created_by']        = $actor->id;
            $data['payment_code']      = $this->generatePaymentCode($companyId, $data['payment_date'] ?? null);
            $data['status']            = OtherPayment::STATUS_POSTED;
            $data['exchange_rate']     = app(FxService::class)->resolveRate(
                $companyId,
                ($data['currency_id'] ?? null) ? (int) ($data['currency_id'] ?? null) : null,
                substr((string) ($data['payment_date'] ?? now()->toDateString()), 0, 10),
                $data['exchange_rate'] ?? null
            ); // Audit C8: foreign currency never silently falls back to a rate of 1
            $data['amount_functional'] = bcmul((string) $data['amount'], (string) $data['exchange_rate'], 2);
            $data['posted_by']         = $actor->id;
            $data['posted_at']         = now();

            $chequeData = $data['cheque'] ?? null;
            unset($data['cheque']);

            $payment = OtherPayment::create($data);

            $this->postPaymentTransaction($payment, $chequeData, $actor);

            AuditLog::record($actor, 'other_payment.recorded', $payment, null, $payment->fresh()->toArray());

            return $payment->fresh(['partner', 'currency', 'debitAccount', 'cheque', 'journalEntry.lines']);
        });
    }

    /**
     * Edits a REOPENED (draft) payment and immediately re-posts it —
     * mirrors record() exactly, just on an existing row. Only ever
     * callable on a draft (setToDraft() is the only way a posted
     * payment becomes one again).
     */
    public function update(OtherPayment $payment, array $data, User $actor): OtherPayment
    {
        if ($payment->status !== OtherPayment::STATUS_DRAFT) {
            throw new \DomainException('Only a draft payment can be edited — use "Set to Draft" first.');
        }

        return DB::transaction(function () use ($payment, $data, $actor) {
            $before = $payment->toArray();

            $data['exchange_rate']     = app(FxService::class)->resolveRate(
                $payment->company_id,
                ($data['currency_id'] ?? $payment->currency_id) ? (int) ($data['currency_id'] ?? $payment->currency_id) : null,
                substr((string) ($data['payment_date'] ?? $payment->payment_date ?? now()->toDateString()), 0, 10),
                $data['exchange_rate'] ?? null
            ); // Audit C8: foreign currency never silently falls back to a rate of 1
            $data['amount_functional'] = bcmul((string) $data['amount'], (string) $data['exchange_rate'], 2);
            $data['status']            = OtherPayment::STATUS_POSTED;
            $data['posted_by']         = $actor->id;
            $data['posted_at']         = now();
            $data['updated_by']        = $actor->id;

            $chequeData = $data['cheque'] ?? null;
            unset($data['cheque']);

            $payment->update($data);

            $this->postPaymentTransaction($payment, $chequeData, $actor);

            AuditLog::record($actor, 'other_payment.reposted', $payment, $before, $payment->fresh()->toArray());

            return $payment->fresh(['partner', 'currency', 'debitAccount', 'cheque', 'journalEntry.lines']);
        });
    }

    /**
     * The actual posting mechanics — shared between record() and
     * update(). Always exactly two lines: Dr debit_account_id / Cr
     * [source].
     */
    private function postPaymentTransaction(OtherPayment $payment, ?array $chequeData, User $actor): void
    {
        $sourceAccount = $this->resolveSourceAccount($payment);

        // Insufficient-funds guard (see CashAvailabilityService header).
        if ($payment->money_type === OtherPayment::MONEY_CASH_PAYMENT) {
            // Audit C8: compare the EGP value with the EGP ledger balance.
            $this->cashAvailability->assertSafeCanCover($payment->company_id, $sourceAccount, (string) $payment->amount_functional);
        } elseif ($payment->money_type === OtherPayment::MONEY_OUTGOING_TRANSFER
            && $payment->source_type === OtherPayment::SOURCE_BANK_ACCOUNT) {
            $bankAccount = BankAccount::findOrFail($payment->source_bank_account_id);
            $this->cashAvailability->assertBankAccountCanCover($payment->company_id, $bankAccount, (string) $payment->amount_functional);
        }

        $debitAccount = ChartOfAccount::withoutGlobalScopes()->forCompany($payment->company_id)
            ->where('id', $payment->debit_account_id)->first();

        if (! $debitAccount) {
            throw new \DomainException('Select the Chart of Accounts account this payment should debit.');
        }

        $amount = (string) $payment->amount_functional;

        $journalEntry = $this->journalEntryService->post(
            companyId: $payment->company_id,
            entryDate: $payment->payment_date,
            // Audit H12/H15: stamp currency + rate so a foreign bank/safe keeps its exact foreign balance.
            lines: app(FxService::class)->tagLines([
                ['chart_of_account_id' => $debitAccount->id, 'debit' => $amount, 'credit' => '0', 'description' => 'Other Payment '.$payment->payment_code],
                ['chart_of_account_id' => $sourceAccount->id, 'debit' => '0', 'credit' => $amount, 'description' => 'Other Payment '.$payment->payment_code],
            ], $payment->currency_id ? (int) $payment->currency_id : null, $payment->exchange_rate),
            sourceType: 'other_payment',
            sourceId: $payment->id,
            narration: $this->narrationFor($payment),
            actor: $actor,
        );

        $payment->update(['journal_entry_id' => $journalEntry->id]);

        // Facility Settlement Engine (Bible Rule 34) — 2026-09 fix.
        // The journal entry above already moved the real money into
        // the facility's own COA account (via resolveSourceAccount()/
        // resolveBankOrFacilityAccount()). This only records the
        // sub-ledger drawdown on top of that already-posted entry —
        // never a second journal entry for the same cash. Mirrors
        // SupplierPaymentService::postPaymentTransaction() exactly.
        if ($payment->source_type === OtherPayment::SOURCE_CREDIT_FACILITY) {
            $facility = CreditFacility::lockForUpdate()->findOrFail($payment->source_credit_facility_id);

            if (! $facility->bank_account_id) {
                throw new \DomainException('This facility has no linked bank account — cannot record a drawdown against it.');
            }

            // facility_limit/outstanding_balance are tracked in the
            // FACILITY's own currency, never functional — same
            // currency-consistency rule as Supplier Payment/Customer
            // Payment. A mismatch means an FX conversion should have
            // happened first, not a silent wrong-currency post.
            if ((int) $payment->currency_id !== (int) $facility->currency_id) {
                throw new \DomainException(
                    "This payment's currency doesn't match the facility's own currency — ".
                    'convert to the facility currency first, then draw on it.'
                );
            }

            $this->settlementEngine->recordWithdrawal($facility, [
                'bank_account_id'  => $facility->bank_account_id,
                'withdrawal_date'  => $payment->payment_date,
                'amount'           => $payment->amount,
                'source_type'      => FacilityWithdrawal::SOURCE_OTHER_PAYMENT,
                'source_id'        => $payment->id,
                'journal_entry_id' => $journalEntry->id,
                'description'      => 'Other Payment '.$payment->payment_code,
            ], $actor);
        }

        if ($payment->isCheque()) {
            $this->createChequePayable($payment, $chequeData, $actor);
        }
    }

    /**
     * Reopens a posted payment back to draft — the ONLY way a posted
     * OtherPayment becomes editable again. Automatically reverses its
     * journal entry and, if a cheque was ever marked paid, that too —
     * same automatic-cascade philosophy as SupplierPaymentService::
     * setToDraft() / OtherCollectionService::setToDraft().
     */
    public function setToDraft(OtherPayment $payment, User $actor): OtherPayment
    {
        if ($payment->status !== OtherPayment::STATUS_POSTED) {
            throw new \DomainException('Only a posted payment can be set back to draft.');
        }
        if ($this->fiscalYearService->isPeriodClosed($payment->company_id, (string) $payment->payment_date)) {
            throw new \DomainException('This payment\'s accounting period is closed — it cannot be reopened.');
        }

        return DB::transaction(function () use ($payment, $actor) {
            $before = $payment->toArray();

            // Facility Settlement Engine — undo the drawdown this
            // payment created, BEFORE reversing the journal entry,
            // same ordering as SupplierPaymentService::reverseEverything().
            // A locked (already-settled) withdrawal blocks the whole
            // reopen with a clear message rather than leaving the
            // journal reversed but the facility sub-ledger still
            // showing the debt.
            if ($payment->source_type === OtherPayment::SOURCE_CREDIT_FACILITY) {
                $withdrawal = FacilityWithdrawal::where('source_type', FacilityWithdrawal::SOURCE_OTHER_PAYMENT)
                    ->where('source_id', $payment->id)
                    ->first();

                if ($withdrawal) {
                    $this->settlementEngine->reverseWithdrawal($withdrawal, $actor);
                }
            }

            if ($payment->journal_entry_id) {
                $entry = JournalEntry::findOrFail($payment->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — '.$payment->payment_code);
            }

            if ($payment->isCheque() && $payment->cheque?->isPaid()) {
                $this->chequePayableService->resetPayment($payment->cheque, $actor);
            }
            if ($payment->isCheque() && $payment->cheque) {
                $payment->cheque->delete();
            }

            $payment->update([
                'status'           => OtherPayment::STATUS_DRAFT,
                'journal_entry_id' => null,
                'posted_by'        => null,
                'posted_at'        => null,
                'updated_by'       => $actor->id,
            ]);

            AuditLog::record($actor, 'other_payment.set_to_draft', $payment, $before, $payment->fresh()->toArray());

            return $payment->fresh();
        });
    }

    /**
     * Deletes a payment outright — only ever on a draft, and the only
     * way a payment becomes a draft is via setToDraft(), which has
     * already reversed everything real it did.
     */
    public function delete(OtherPayment $payment, User $actor): void
    {
        if ($payment->status !== OtherPayment::STATUS_DRAFT) {
            throw new \DomainException('Only a draft payment can be deleted — use "Set to Draft" first.');
        }
        if ($payment->journal_entry_id) {
            throw new \DomainException('This payment still has a journal entry attached — it cannot be deleted.');
        }

        DB::transaction(function () use ($payment, $actor) {
            $before = $payment->toArray();

            if ($payment->isCheque() && $payment->cheque) {
                $payment->cheque->delete();
            }

            $payment->delete();

            AuditLog::record($actor, 'other_payment.deleted', $payment, $before, null);
        });
    }

    /**
     * Cancels a posted payment outright — a dead end, unlike
     * setToDraft() which leaves it editable.
     */
    public function cancel(OtherPayment $payment, User $actor): OtherPayment
    {
        if ($payment->status !== OtherPayment::STATUS_POSTED) {
            throw new \DomainException('Only a posted payment can be cancelled.');
        }
        if ($this->fiscalYearService->isPeriodClosed($payment->company_id, (string) $payment->payment_date)) {
            throw new \DomainException('This payment\'s accounting period is closed — it cannot be cancelled.');
        }

        return DB::transaction(function () use ($payment, $actor) {
            $before = $payment->toArray();

            // Facility Settlement Engine — same reversal, same
            // ordering, as setToDraft() above.
            if ($payment->source_type === OtherPayment::SOURCE_CREDIT_FACILITY) {
                $withdrawal = FacilityWithdrawal::where('source_type', FacilityWithdrawal::SOURCE_OTHER_PAYMENT)
                    ->where('source_id', $payment->id)
                    ->first();

                if ($withdrawal) {
                    $this->settlementEngine->reverseWithdrawal($withdrawal, $actor);
                }
            }

            if ($payment->journal_entry_id) {
                $entry = JournalEntry::findOrFail($payment->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Cancelled — '.$payment->payment_code);
            }

            if ($payment->isCheque() && $payment->cheque?->isPaid()) {
                $this->chequePayableService->resetPayment($payment->cheque, $actor);
            }
            if ($payment->isCheque() && $payment->cheque) {
                $payment->cheque->delete();
            }

            $payment->update([
                'status'           => OtherPayment::STATUS_CANCELLED,
                'journal_entry_id' => null,
            ]);

            AuditLog::record($actor, 'other_payment.cancelled', $payment, $before, $payment->fresh()->toArray());

            return $payment->fresh();
        });
    }

    // ── Private — source resolution (mirrors SupplierPaymentService) ──

    private function resolveSourceAccount(OtherPayment $payment): ChartOfAccount
    {
        return match ($payment->money_type) {
            OtherPayment::MONEY_OUTGOING_TRANSFER => $this->resolveBankOrFacilityAccount($payment),
            OtherPayment::MONEY_CASH_PAYMENT      => $this->resolveSafeAccount($payment),
            OtherPayment::MONEY_PAYABLE_CHEQUE    => $this->resolveNotesPayableAccount($payment->company_id),
            default => throw new \DomainException("Unknown money type: {$payment->money_type}"),
        };
    }

    private function resolveBankOrFacilityAccount(OtherPayment $payment): ChartOfAccount
    {
        if ($payment->source_type === OtherPayment::SOURCE_CREDIT_FACILITY) {
            $facility = CreditFacility::where('id', $payment->source_credit_facility_id)
                ->where('company_id', $payment->company_id)->first();

            if (! $facility || ! $facility->coa_account_id) {
                throw new \DomainException('The selected Overdraft Facility is missing its Chart of Accounts link.');
            }

            return ChartOfAccount::findOrFail($facility->coa_account_id);
        }

        $bankAccount = BankAccount::where('id', $payment->source_bank_account_id)
            ->where('company_id', $payment->company_id)->first();

        if (! $bankAccount) {
            throw new \DomainException('The selected bank account does not belong to this company.');
        }

        return ChartOfAccount::findOrFail($bankAccount->coa_account_id);
    }

    private function resolveSafeAccount(OtherPayment $payment): ChartOfAccount
    {
        $account = ChartOfAccount::withoutGlobalScopes()->forCompany($payment->company_id)
            ->where('id', $payment->safe_account_id)->first();

        return $account ?? throw new \DomainException('The selected Safe account does not belong to this company.');
    }

    // Payable Cheque credits 222001 at issue time — same as Supplier Payment.
    private function resolveNotesPayableAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '222001')
            ?? throw new \DomainException('Chart of Accounts is missing system account 222001 (Notes Payable — Suppliers) — check the seeder.');
    }

    private function narrationFor(OtherPayment $payment): string
    {
        $typeLabel = OtherPayment::partnerTypeLabel($payment->partner_type);
        return "Other Payment {$payment->payment_code} — {$typeLabel}: {$payment->partner->name_en}";
    }

    private function createChequePayable(OtherPayment $payment, ?array $chequeData, User $actor): ChequePayable
    {
        if (! $chequeData) {
            throw new \DomainException('Cheque details are required for a Payable Cheque payment.');
        }

        return ChequePayable::create([
            'company_id'          => $payment->company_id,
            'other_payment_id'    => $payment->id,
            'partner_id'          => $payment->partner_id,
            'cheque_amount'       => $payment->amount,
            // Audit C8: remember the cheque's currency and the EGP value booked, so clearing it can value any FX difference.
            'currency_id'         => $payment->currency_id,
            'exchange_rate'       => $payment->exchange_rate ?: 1,
            'amount_functional'   => $payment->amount_functional,
            'cheque_number'       => $chequeData['cheque_number'],
            'due_date'            => $chequeData['due_date'],
            'status'              => ChequePayable::STATUS_ISSUED,
            'created_by'          => $actor->id,
        ]);
    }

    private function generatePaymentCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\OtherPayment::class, 'payment_code', 'OP', $date);
    }
}
