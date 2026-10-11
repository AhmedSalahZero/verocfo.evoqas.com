<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ChequePayableService
//
//  Supplier Payment (August 2026) — a payable cheque's own lifecycle
//  from 'issued' onward. Issuing it already settled AP (or 144
//  Advances) — SupplierPaymentService::record(), Dr AP / Cr 222001 —
//  so everything here concerns only the physical instrument's
//  eventual clearing.
//
//  Deliberately simpler than ChequeReceivableService (confirmed by
//  Mahmoud — Mark as Paid + Reset only, no send-for-collection or
//  bounce handling):
//
//    markAsPaid() — Dr 222001 Notes Payable — Suppliers / Cr [the
//      source account already fixed on the parent SupplierPayment at
//      issue time]. This is where real cash actually leaves; nothing
//      moved at 'issued'. Real-world rule, same as the receivable
//      side: cannot be marked paid before its own due_date.
//    resetPayment() — undoes a mistaken Mark as Paid. Reverses the
//      real journal entry it posted (never a delete — same rule as
//      everywhere else in this app) and puts the cheque back to
//      'issued'.
//
//  THREE POSSIBLE ISSUERS (August 2026) — Supplier Payment,
//  Other Payment, and now Cash Expense Payment all share this same
//  cheque lifecycle. resolveSourceAccount() resolves whichever of the
//  three actually issued a given cheque before reading its fixed
//  source_* fields — see ChequePayable::source().
//
//  FOURTH ISSUER (August 2026) — Reverse Factoring. Resolved on its
//  own branch in resolveSourceAccount() since it always funds from a
//  plain bank account (source_bank_account_id on the
//  ReverseFactoringTransaction itself) — no source_type/credit-
//  facility option like the other three.
//
//  AUDIT C8 FIX (Sept 2026) — foreign-currency cheques. markAsPaid()
//  used to post cheque_amount (the cheque's own currency) 1:1 into
//  the EGP ledger while 222 had been credited at the EGP value, so
//  the difference was stuck in Notes Payable. Now: Dr 222 at the
//  booked value (ChequePayable::bookedFunctional()), Cr the bank at
//  cheque amount × the rate on the payment date (FxService),
//  difference to 431 / 571.
//
//  AUDIT C7 (Sept 2026) — a cheque created from Opening Balances has
//  no parent payment; the bank it is drawn on is its own
//  ob_source_account_id.
//
//  @layer    Service
//  @depends  ChequePayable, SupplierPayment, OtherPayment, FxService,
//            CashExpensePayment, FinancialInstitutions\ReverseFactoringTransaction,
//            ChartOfAccount, FinancialInstitutions\BankAccount,
//            FinancialInstitutions\CreditFacility, JournalEntryService,
//            FiscalYearService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\CashExpensePayment;
use App\Models\ChartOfAccount;
use App\Models\ChequePayable;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\ReverseFactoringTransaction;
use App\Models\JournalEntry;
use App\Models\OtherPayment;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Support\Facades\DB;

class ChequePayableService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
        private FxService $fx,
    ) {
    }

    public function paginate(int $companyId, array $filters = [])
    {
        return ChequePayable::with([
            'partner:id,name_en,name_ar',
            'factoringCompany:id,name_en,name_ar',
            'supplierPayment:id,payment_code,source_type,source_bank_account_id,source_credit_facility_id',
            'otherPayment:id,payment_code,partner_type,source_type,source_bank_account_id,source_credit_facility_id',
            'cashExpensePayment:id,payment_code,source_type,source_bank_account_id,source_credit_facility_id',
            'reverseFactoringTransaction:id,source_bank_account_id',
        ])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('due_date')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Mark as Paid — real cash (or OD paydown) leaves ─────────────

    public function markAsPaid(ChequePayable $cheque, string $paymentDate, User $actor): ChequePayable
    {
        if (! $cheque->isIssued()) {
            throw new \DomainException('Only an issued cheque can be marked paid.');
        }

        // Real-world rule (confirmed with Mahmoud, same as the
        // receivable side): a cheque cannot legally clear before its
        // own due date.
        if ($paymentDate < $cheque->due_date->format('Y-m-d')) {
            throw new \DomainException(
                "This cheque cannot be marked paid before its due date ({$cheque->due_date->format('d-m-Y')})."
            );
        }

        return DB::transaction(function () use ($cheque, $paymentDate, $actor) {
            $before = $cheque->toArray();

            $sourceAccount = $this->resolveSourceAccount($cheque);
            $notesPayableAccount = $this->resolveNotesPayableAccount($cheque->company_id);

            // C8: 222 cleared at the booked value; the bank at today's
            // value of the cheque; difference to realised FX.
            $booked  = $cheque->bookedFunctional();
            $rate    = $this->fx->rateOn($cheque->company_id, $cheque->currencyIdResolved(), $paymentDate);
            $settled = $this->fx->toFunctional($cheque->cheque_amount, $rate);

            $lines = [
                ['chart_of_account_id' => $notesPayableAccount->id, 'debit' => $booked, 'credit' => '0', 'description' => 'Cheque paid — '.$cheque->cheque_number],
                ['chart_of_account_id' => $sourceAccount->id, 'debit' => '0', 'credit' => $settled, 'description' => 'Cheque paid — '.$cheque->cheque_number],
            ];
            if ($fxLine = $this->fx->fxDifferenceLine($cheque->company_id, $booked, $settled, 'credit', 'cheque '.$cheque->cheque_number)) {
                $lines[] = $fxLine;
            }

            $journalEntry = $this->journalEntryService->post(
                companyId: $cheque->company_id,
                entryDate: $paymentDate,
                lines: $lines,
                sourceType: 'cheque_payable',
                sourceId: $cheque->id,
                narration: "Cheque {$cheque->cheque_number} paid — {$cheque->partner?->name_en}",
                actor: $actor,
            );

            $cheque->update([
                'status'                => ChequePayable::STATUS_PAID,
                'paid_journal_entry_id' => $journalEntry->id,
                'paid_at'               => $paymentDate,
            ]);

            AuditLog::record($actor, 'cheque_payable.paid', $cheque, $before, $cheque->fresh()->toArray());

            return $cheque->fresh(['paidJournalEntry.lines']);
        });
    }

    /**
     * Undoes a mistaken Mark as Paid — reverses the real journal entry
     * it posted (a genuine reversal, never a delete) and puts the
     * cheque back to 'issued'. Blocked if the payment's own accounting
     * period is closed.
     */
    public function resetPayment(ChequePayable $cheque, User $actor): ChequePayable
    {
        if (! $cheque->isPaid()) {
            throw new \DomainException('Only a paid cheque can be reset.');
        }
        if ($this->fiscalYearService->isPeriodClosed($cheque->company_id, (string) $cheque->paid_at)) {
            throw new \DomainException('This cheque\'s accounting period is closed — its payment cannot be reset.');
        }

        return DB::transaction(function () use ($cheque, $actor) {
            $before = $cheque->toArray();

            if ($cheque->paid_journal_entry_id) {
                $entry = JournalEntry::findOrFail($cheque->paid_journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Payment reset — '.$cheque->cheque_number);
            }

            $cheque->update([
                'status'                 => ChequePayable::STATUS_ISSUED,
                'paid_journal_entry_id'  => null,
                'paid_at'                => null,
            ]);

            AuditLog::record($actor, 'cheque_payable.reset', $cheque, $before, $cheque->fresh()->toArray());

            return $cheque->fresh();
        });
    }

    // ── Void / Stop (M-31) ───────────────────────────────────────────

    /**
     * Marks an ISSUED cheque as void (torn up, never presented) or
     * stopped (payment stopped at the bank). A written reason is
     * required. The cheque can then never be marked paid.
     *
     * No journal entry is posted here on purpose: the supplier is still
     * owed the money, so Notes Payable (222) stays as booked until the
     * payment that issued it is reopened or cancelled (that reverses
     * the whole payment and reopens the bill). Voided cheques are
     * therefore left out of the aging list but still counted by the
     * month-end FX revaluation, which follows what is on the books.
     */
    public function voidCheque(ChequePayable $cheque, string $kind, string $reason, string $date, User $actor): ChequePayable
    {
        if (! $cheque->isIssued()) {
            throw new \DomainException('Only an issued cheque can be voided or stopped.');
        }
        if (! in_array($kind, [ChequePayable::STATUS_VOID, ChequePayable::STATUS_STOPPED], true)) {
            throw new \DomainException('Choose either Void or Stopped.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw new \DomainException('Please write the reason (at least a few words) — it stays on the cheque record.');
        }

        return DB::transaction(function () use ($cheque, $kind, $reason, $date, $actor) {
            $before = $cheque->toArray();
            $cheque->update([
                'status'      => $kind,
                'void_reason' => trim($reason),
                'voided_at'   => $date,
                'voided_by'   => $actor->id,
            ]);
            AuditLog::record($actor, 'cheque_payable.'.$kind, $cheque, $before, $cheque->fresh()->toArray());

            return $cheque->fresh();
        });
    }

    // ── Private — source resolution (mirrors SupplierPaymentService/
    //    OtherPaymentService, deliberately duplicated — small, explicit,
    //    financial logic, same tolerance for controlled duplication
    //    already established elsewhere in this codebase) ───────────────

    /** Resolves whichever of the four parent records actually issued this cheque, then reads its fixed source_* fields. */
    private function resolveSourceAccount(ChequePayable $cheque): ChartOfAccount
    {
        // C7: an opening-balance cheque carries its own bank GL account.
        if ($cheque->isFromOpeningBalance()) {
            if (! $cheque->ob_source_account_id) {
                throw new \DomainException('This opening-balance cheque has no bank account it is drawn on — it cannot be marked paid.');
            }

            return ChartOfAccount::withoutGlobalScopes()->forCompany($cheque->company_id)
                ->whereKey($cheque->ob_source_account_id)->firstOrFail();
        }

        // Reverse Factoring always funds from a plain bank account —
        // no source_type/credit-facility option, unlike the other three
        // sources — so it's resolved on its own, not through the
        // source_type branch below.
        if ($cheque->isFromReverseFactoring()) {
            $reverseFactoring = ReverseFactoringTransaction::findOrFail($cheque->reverse_factoring_transaction_id);

            $bankAccount = BankAccount::where('id', $reverseFactoring->source_bank_account_id)
                ->where('company_id', $cheque->company_id)->first();

            if (! $bankAccount) {
                throw new \DomainException('The source bank account no longer belongs to this company.');
            }

            return ChartOfAccount::findOrFail($bankAccount->coa_account_id);
        }

        $source = match (true) {
            $cheque->isFromCashExpensePayment() => CashExpensePayment::findOrFail($cheque->cash_expense_payment_id),
            $cheque->isFromOtherPayment()       => OtherPayment::findOrFail($cheque->other_payment_id),
            default                              => SupplierPayment::findOrFail($cheque->supplier_payment_id),
        };

        if ($source->source_type === 'credit_facility') {
            $facility = CreditFacility::where('id', $source->source_credit_facility_id)
                ->where('company_id', $cheque->company_id)->first();

            if (! $facility || ! $facility->coa_account_id) {
                throw new \DomainException('The selected Overdraft Facility is missing its Chart of Accounts link.');
            }

            return ChartOfAccount::findOrFail($facility->coa_account_id);
        }

        $bankAccount = BankAccount::where('id', $source->source_bank_account_id)
            ->where('company_id', $cheque->company_id)->first();

        if (! $bankAccount) {
            throw new \DomainException('The source bank account no longer belongs to this company.');
        }

        return ChartOfAccount::findOrFail($bankAccount->coa_account_id);
    }

    private function resolveNotesPayableAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '222001')
            ?? throw new \DomainException('Chart of Accounts is missing system account 222001 (Notes Payable — Suppliers) — check the seeder.');
    }
}
