<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CashExpensePaymentService
//
//  A direct expense payment with no Supplier Bill behind it — see
//  migration 000177. Structural sibling of OtherPaymentService: ONE
//  ATOMIC ACTION (record()), posts immediately, Set to Draft is the
//  only way back to an editable state, no allocation/settlement
//  concept.
//
//  MULTI-LINE (August 2026, see migration 000181) — one payment (one
//  payee, one date, one money type, one source account) carries
//  MULTIPLE expense lines, each with its own Expense Account, Cost
//  Center, subtotal, VAT rate (+ is_deductible), and WHT rate.
//  syncLines() deletes and recreates every CashExpensePaymentLine row
//  on every record()/update() — same "replace everything" pattern
//  already used for syncAllocations(). This model's own subtotal/
//  vat_amount/withholding_tax_amount/net_amount columns become
//  AGGREGATE TOTALS (sum of every line) for fast list-page display —
//  same convention Supplier Bill already uses for its own bill-level
//  totals alongside line-level detail.
//
//  SOURCE RESOLUTION — identical branching to OtherPaymentService/
//  SupplierPaymentService:
//    cash_payment       → safe_account_id (131xxx sub-account)
//    outgoing_transfer  → a BankAccount or a CreditFacility — when
//      it's a CreditFacility, this also records a real drawdown
//      against it via FacilitySettlementEngine (Bible Rule 34), same
//      as Other Payment/Supplier Payment. 2026-09 fix — previously
//      the GL entry posted correctly but the facility's own
//      sub-ledger never learned about it.
//    payable_cheque     → always 222001 Notes Payable at issue time;
//      the cheque's own onward journey (Mark as Paid / Reset) lives
//      in ChequePayableService, shared across all three payable-
//      cheque issuers (see migration 000178).
//
//  TAX HANDLING — mirrors SupplierBillService exactly, per LINE:
//    Deductible (default)     → this line's vat_amount posts to VAT
//                                Input (137).
//    Non-deductible           → this line's vat_amount folds into
//                                THIS SAME line's own expense_account_id
//                                instead — see addLineVat().
//    Withholding Tax (226002) → unaffected by deductibility either
//                                way, always computed on this line's
//                                own subtotal, before VAT.
//  net_amount (aggregate) = sum of every line's own
//  (subtotal + vat_amount - withholding_tax_amount) — the actual cash
//  that leaves via the one source line.
//
//  COST CENTER — only enforced when a given LINE's own expense_account_id
//  is flagged requires_cost_center = true (same rule as everywhere
//  else in this app that posts to a cost-center-flagged account).
//  Never enforced unconditionally, and independent per line.
//
//  ALLOCATING WITH CUSTOMER CONTRACTS (August 2026) — syncAllocations()
//  fully replaces this payment's CashExpensePaymentAllocation rows on
//  every record()/update(). Confirmed with Mahmoud: reporting-only,
//  zero effect on the journal entry above, never required to add up
//  to the full payment — feeds the future Project P&L and Project
//  Cash Flow reports (see migration 000179). Each allocation row also
//  carries WHICH line it was drawn from (migration 000183) — the
//  incoming payload references a line by its 0-based position in the
//  submitted `lines` array (line_index), since on create the real
//  line IDs don't exist yet; syncLines() runs first and hands back an
//  index→id map that syncAllocations() then resolves against.
//
//  @layer    Service
//  @depends  CashExpensePayment, CashExpensePaymentLine, CashExpensePaymentAllocation,
//            ChequePayable, ChartOfAccount, FinancialInstitutions\BankAccount,
//            FinancialInstitutions\CreditFacility, FinancialInstitutions\FacilityWithdrawal,
//            JournalEntryService, FiscalYearService, ChequePayableService,
//            FacilitySettlementEngine, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\CashExpensePayment;
use App\Models\CashExpensePaymentAllocation;
use App\Models\CashExpensePaymentLine;
use App\Models\ChartOfAccount;
use App\Models\ChequePayable;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityWithdrawal;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\FinancialInstitutions\FacilitySettlementEngine;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CashExpensePaymentService
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
        return CashExpensePayment::with(['partner:id,name_en,name_ar', 'lines.expenseAccount:id,code,name_en', 'currency:id,code', 'cheque', 'allocations'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('payment_code', 'like', "%{$s}%"))
            ->when($filters['expense_account_id'] ?? null, fn ($q, $a) => $q->whereHas('lines', fn ($lq) => $lq->where('expense_account_id', $a)))
            ->when($filters['money_type'] ?? null, fn ($q, $t) => $q->where('money_type', $t))
            ->when($filters['partner_id'] ?? null, fn ($q, $p) => $q->where('partner_id', $p))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Record — the one atomic action ───────────────────────────

    public function record(int $companyId, array $data, User $actor): CashExpensePayment
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $data['exchange_rate'] = app(FxService::class)->resolveRate(
                $companyId,
                ($data['currency_id'] ?? null) ? (int) ($data['currency_id'] ?? null) : null,
                substr((string) ($data['payment_date'] ?? now()->toDateString()), 0, 10),
                $data['exchange_rate'] ?? null
            ); // Audit C8: foreign currency never silently falls back to a rate of 1

            $linesInput = $data['lines'] ?? [];
            unset($data['lines']);
            $allocationsInput = $data['allocations'] ?? [];
            unset($data['allocations']);

            if (! $linesInput) {
                throw new \DomainException('At least one expense line is required.');
            }

            $computedLines = array_map(fn ($l) => $this->computeLineAmounts($l, (string) $data['exchange_rate']), $linesInput);
            foreach ($computedLines as $cl) {
                $this->assertCostCenterSatisfied($companyId, $cl);
            }

            $data = array_merge($data, $this->aggregateTotals($computedLines));

            $data['company_id']   = $companyId;
            $data['created_by']   = $actor->id;
            $data['payment_code'] = $this->generatePaymentCode($companyId, $data['payment_date'] ?? null);
            $data['status']       = CashExpensePayment::STATUS_POSTED;
            $data['posted_by']    = $actor->id;
            $data['posted_at']    = now();

            $chequeData = $data['cheque'] ?? null;
            unset($data['cheque']);

            $payment = CashExpensePayment::create($data);

            $lineIdsByIndex = $this->syncLines($payment, $computedLines);
            $this->syncAllocations($payment, $allocationsInput, $lineIdsByIndex);

            $this->postPaymentTransaction($payment, $chequeData, $actor);

            AuditLog::record($actor, 'cash_expense_payment.recorded', $payment, null, $payment->fresh()->toArray());

            return $payment->fresh(['partner', 'lines.expenseAccount', 'lines.costCenter', 'currency', 'cheque', 'allocations.project', 'journalEntry.lines']);
        });
    }

    /**
     * Edits a REOPENED (draft) payment and immediately re-posts it —
     * mirrors record() exactly, just on an existing row. Only ever
     * callable on a draft (setToDraft() is the only way a posted
     * payment becomes one again).
     */
    public function update(CashExpensePayment $payment, array $data, User $actor): CashExpensePayment
    {
        if ($payment->status !== CashExpensePayment::STATUS_DRAFT) {
            throw new \DomainException('Only a draft payment can be edited — use "Set to Draft" first.');
        }

        return DB::transaction(function () use ($payment, $data, $actor) {
            $before = $payment->toArray();

            $data['exchange_rate'] = app(FxService::class)->resolveRate(
                $payment->company_id,
                ($data['currency_id'] ?? $payment->currency_id) ? (int) ($data['currency_id'] ?? $payment->currency_id) : null,
                substr((string) ($data['payment_date'] ?? $payment->payment_date ?? now()->toDateString()), 0, 10),
                $data['exchange_rate'] ?? null
            ); // Audit C8: foreign currency never silently falls back to a rate of 1

            $linesInput = $data['lines'] ?? [];
            unset($data['lines']);
            $allocationsInput = $data['allocations'] ?? [];
            unset($data['allocations']);

            if (! $linesInput) {
                throw new \DomainException('At least one expense line is required.');
            }

            $computedLines = array_map(fn ($l) => $this->computeLineAmounts($l, (string) $data['exchange_rate']), $linesInput);
            foreach ($computedLines as $cl) {
                $this->assertCostCenterSatisfied($payment->company_id, $cl);
            }

            $data = array_merge($data, $this->aggregateTotals($computedLines));

            $data['status']      = CashExpensePayment::STATUS_POSTED;
            $data['posted_by']   = $actor->id;
            $data['posted_at']   = now();
            $data['updated_by']  = $actor->id;

            $chequeData = $data['cheque'] ?? null;
            unset($data['cheque']);

            $payment->update($data);

            $lineIdsByIndex = $this->syncLines($payment, $computedLines);
            $this->syncAllocations($payment, $allocationsInput, $lineIdsByIndex);

            $this->postPaymentTransaction($payment, $chequeData, $actor);

            AuditLog::record($actor, 'cash_expense_payment.reposted', $payment, $before, $payment->fresh()->toArray());

            return $payment->fresh(['partner', 'lines.expenseAccount', 'lines.costCenter', 'currency', 'cheque', 'allocations.project', 'journalEntry.lines']);
        });
    }

    /**
     * Reopens a posted payment back to draft — the ONLY way a posted
     * CashExpensePayment becomes editable again. Automatically
     * reverses its journal entry and, if a cheque was ever marked
     * paid, that too — same automatic-cascade philosophy as
     * OtherPaymentService::setToDraft().
     */
    public function setToDraft(CashExpensePayment $payment, User $actor): CashExpensePayment
    {
        if ($payment->status !== CashExpensePayment::STATUS_POSTED) {
            throw new \DomainException('Only a posted payment can be set back to draft.');
        }
        if ($this->fiscalYearService->isPeriodClosed($payment->company_id, (string) $payment->payment_date)) {
            throw new \DomainException('This payment\'s accounting period is closed — it cannot be reopened.');
        }

        return DB::transaction(function () use ($payment, $actor) {
            $before = $payment->toArray();

            // Facility Settlement Engine — undo the drawdown this
            // payment created, BEFORE reversing the journal entry,
            // same ordering as SupplierPaymentService/OtherPaymentService.
            if ($payment->source_type === CashExpensePayment::SOURCE_CREDIT_FACILITY) {
                $withdrawal = FacilityWithdrawal::where('source_type', FacilityWithdrawal::SOURCE_CASH_EXPENSE_PAYMENT)
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
                'status'           => CashExpensePayment::STATUS_DRAFT,
                'journal_entry_id' => null,
                'posted_by'        => null,
                'posted_at'        => null,
                'updated_by'       => $actor->id,
            ]);

            AuditLog::record($actor, 'cash_expense_payment.set_to_draft', $payment, $before, $payment->fresh()->toArray());

            return $payment->fresh();
        });
    }

    /**
     * Deletes a payment outright — only ever on a draft, and the only
     * way a payment becomes a draft is via setToDraft(), which has
     * already reversed everything real it did. Lines and allocations
     * cascade-delete at the DB level.
     */
    public function delete(CashExpensePayment $payment, User $actor): void
    {
        if ($payment->status !== CashExpensePayment::STATUS_DRAFT) {
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

            AuditLog::record($actor, 'cash_expense_payment.deleted', $payment, $before, null);
        });
    }

    /**
     * Cancels a posted payment outright — a dead end, unlike
     * setToDraft() which leaves it editable.
     */
    public function cancel(CashExpensePayment $payment, User $actor): CashExpensePayment
    {
        if ($payment->status !== CashExpensePayment::STATUS_POSTED) {
            throw new \DomainException('Only a posted payment can be cancelled.');
        }
        if ($this->fiscalYearService->isPeriodClosed($payment->company_id, (string) $payment->payment_date)) {
            throw new \DomainException('This payment\'s accounting period is closed — it cannot be cancelled.');
        }

        return DB::transaction(function () use ($payment, $actor) {
            $before = $payment->toArray();

            // Facility Settlement Engine — same reversal, same
            // ordering, as setToDraft() above.
            if ($payment->source_type === CashExpensePayment::SOURCE_CREDIT_FACILITY) {
                $withdrawal = FacilityWithdrawal::where('source_type', FacilityWithdrawal::SOURCE_CASH_EXPENSE_PAYMENT)
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
                'status'           => CashExpensePayment::STATUS_CANCELLED,
                'journal_entry_id' => null,
            ]);

            AuditLog::record($actor, 'cash_expense_payment.cancelled', $payment, $before, $payment->fresh()->toArray());

            return $payment->fresh();
        });
    }

    // ── Private — per-line amount + tax computation ──────────────

    /**
     * Computes one line's vat_amount / withholding_tax_amount /
     * net_amount and their functional-currency equivalents from its
     * own subtotal + rates — same arithmetic SupplierBillLine uses
     * (percentage of the pre-tax subtotal). is_deductible defaults
     * true when omitted.
     */
    private function computeLineAmounts(array $line, string $exchangeRate): array
    {
        $subtotal = (string) ($line['subtotal'] ?? '0');
        $vatRate  = (string) ($line['vat_rate'] ?? '0');
        $whtRate  = (string) ($line['withholding_tax_rate'] ?? '0');

        $vatAmount = bcdiv(bcmul($subtotal, $vatRate, 4), '100', 2);
        $whtAmount = bcdiv(bcmul($subtotal, $whtRate, 4), '100', 2);
        $netAmount = bcsub(bcadd($subtotal, $vatAmount, 2), $whtAmount, 2);

        return [
            'description'                       => $line['description'] ?? null,
            'expense_account_id'                => $line['expense_account_id'] ?? null,
            'cost_center_id'                     => $line['cost_center_id'] ?? null,
            'subtotal'                           => $subtotal,
            'vat_rate'                           => $vatRate,
            'is_deductible'                      => array_key_exists('is_deductible', $line) ? (bool) $line['is_deductible'] : true,
            'vat_amount'                         => $vatAmount,
            'withholding_tax_rate'               => $whtRate,
            'withholding_tax_amount'             => $whtAmount,
            'net_amount'                         => $netAmount,
            'subtotal_functional'                => bcmul($subtotal, $exchangeRate, 2),
            'vat_amount_functional'              => bcmul($vatAmount, $exchangeRate, 2),
            'withholding_tax_amount_functional'  => bcmul($whtAmount, $exchangeRate, 2),
            'net_amount_functional'              => bcmul($netAmount, $exchangeRate, 2),
        ];
    }

    /** Sums every computed line into the payment-level aggregate totals — display only, see class header. */
    private function aggregateTotals(array $computedLines): array
    {
        $sum = fn (string $key) => array_reduce($computedLines, fn ($carry, $l) => bcadd($carry, $l[$key], 2), '0');

        return [
            'subtotal'                          => $sum('subtotal'),
            'vat_amount'                         => $sum('vat_amount'),
            'withholding_tax_amount'             => $sum('withholding_tax_amount'),
            'net_amount'                         => $sum('net_amount'),
            'subtotal_functional'                => $sum('subtotal_functional'),
            'vat_amount_functional'              => $sum('vat_amount_functional'),
            'withholding_tax_amount_functional'  => $sum('withholding_tax_amount_functional'),
            'net_amount_functional'              => $sum('net_amount_functional'),
        ];
    }

    /**
     * Only actually requires a Cost Center when THIS line's own
     * expense account is flagged requires_cost_center — never
     * enforced unconditionally, and independent per line.
     */
    private function assertCostCenterSatisfied(int $companyId, array $computedLine): void
    {
        $account = ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->where('id', $computedLine['expense_account_id'] ?? null)->first();

        if (! $account) {
            throw new \DomainException('Select a valid Expense account on every line.');
        }

        if ($account->requires_cost_center && empty($computedLine['cost_center_id'])) {
            throw new \DomainException("The account \"{$account->code} — {$account->name_en}\" requires a Cost Center.");
        }
    }

    /**
     * Deletes and recreates every line for this payment — same
     * "replace everything" pattern already used for syncAllocations().
     * Returns a 0-based-index → new line ID map so syncAllocations()
     * can resolve which real line each allocation row was drawn from.
     */
    private function syncLines(CashExpensePayment $payment, array $computedLines): array
    {
        $payment->lines()->delete();

        $lineIdsByIndex = [];

        foreach (array_values($computedLines) as $i => $lineData) {
            $line = CashExpensePaymentLine::create(array_merge($lineData, [
                'company_id'              => $payment->company_id,
                'cash_expense_payment_id' => $payment->id,
                'sort_order'              => $i,
            ]));

            $lineIdsByIndex[$i] = $line->id;
        }

        return $lineIdsByIndex;
    }

    // ── Private — the actual posting mechanics ───────────────────

    /**
     * Builds one debit-buckets array across ALL lines (same "signed
     * amount per account, split into debit/credit at the end"
     * approach as SupplierBillService::postBillJournalEntry()), then
     * posts ONE journal entry. Each line contributes:
     *   Dr expense_account_id           subtotal_functional
     *   Dr/fold VAT (see addLineVat)    vat_amount_functional (if any)
     *   Cr WHT Payable (226002)         withholding_tax_amount_functional (if any)
     * plus ONE final credit to the source account for the payment's
     * total net_amount_functional.
     */
    private function postPaymentTransaction(CashExpensePayment $payment, ?array $chequeData, User $actor): void
    {
        $sourceAccount = $this->resolveSourceAccount($payment);

        // Insufficient-funds guard — only for money that leaves a real
        // bank current account or the cash safe right now. A payable
        // cheque doesn't debit cash until it clears (a separate flow),
        // and a credit-facility draw already has its own room check
        // inside FacilitySettlementEngine::recordWithdrawal() — this
        // is specifically the check that was missing (see class
        // header on CashAvailabilityService).
        if ($payment->money_type === CashExpensePayment::MONEY_CASH_PAYMENT) {
            // Audit C8: compare the EGP value with the EGP ledger balance.
            $this->cashAvailability->assertSafeCanCover($payment->company_id, $sourceAccount, (string) $payment->net_amount_functional);
        } elseif ($payment->money_type === CashExpensePayment::MONEY_OUTGOING_TRANSFER
            && $payment->source_type === CashExpensePayment::SOURCE_BANK_ACCOUNT) {
            $bankAccount = BankAccount::findOrFail($payment->source_bank_account_id);
            $this->cashAvailability->assertBankAccountCanCover($payment->company_id, $bankAccount, (string) $payment->net_amount_functional);
        }

        $debitBuckets = [];

        foreach ($payment->lines()->get() as $line) {
            $this->addToBucket($debitBuckets, $line->expense_account_id, (string) $line->subtotal_functional);

            $this->addLineVat($payment, $line, $debitBuckets);

            if (bccomp((string) $line->withholding_tax_amount_functional, '0', 2) > 0) {
                $whtAccount = ChartOfAccount::findByCode($payment->company_id, '226002');
                if (! $whtAccount) {
                    throw new \DomainException('Withholding Tax Payable account (226002) is not seeded for this company — cannot post.');
                }
                $this->addToBucket($debitBuckets, $whtAccount->id, bcmul((string) $line->withholding_tax_amount_functional, '-1', 2));
            }
        }

        $this->addToBucket($debitBuckets, $sourceAccount->id, bcmul((string) $payment->net_amount_functional, '-1', 2));

        $lines = [];
        foreach ($debitBuckets as $accountId => $amount) {
            if (bccomp($amount, '0', 2) === 0) {
                continue;
            }
            $lines[] = bccomp($amount, '0', 2) > 0
                ? ['chart_of_account_id' => $accountId, 'debit' => $amount, 'credit' => '0', 'description' => 'Cash Expense Payment '.$payment->payment_code]
                : ['chart_of_account_id' => $accountId, 'debit' => '0', 'credit' => bcmul($amount, '-1', 2), 'description' => 'Cash Expense Payment '.$payment->payment_code];
        }

        $journalEntry = $this->journalEntryService->post(
            companyId: $payment->company_id,
            entryDate: $payment->payment_date,
            lines: app(FxService::class)->tagLines($lines, $payment->currency_id ? (int) $payment->currency_id : null, $payment->exchange_rate), // Audit H12/H15
            sourceType: 'cash_expense_payment',
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
        // OtherPaymentService::postPaymentTransaction() exactly.
        // net_amount (not net_amount_functional) is the correct
        // figure here — the facility's own outstanding_balance is
        // tracked in the FACILITY's own currency, same as every other
        // caller of recordWithdrawal().
        if ($payment->source_type === CashExpensePayment::SOURCE_CREDIT_FACILITY) {
            $facility = CreditFacility::lockForUpdate()->findOrFail($payment->source_credit_facility_id);

            if (! $facility->bank_account_id) {
                throw new \DomainException('This facility has no linked bank account — cannot record a drawdown against it.');
            }

            if ((int) $payment->currency_id !== (int) $facility->currency_id) {
                throw new \DomainException(
                    "This payment's currency doesn't match the facility's own currency — ".
                    'convert to the facility currency first, then draw on it.'
                );
            }

            $this->settlementEngine->recordWithdrawal($facility, [
                'bank_account_id'  => $facility->bank_account_id,
                'withdrawal_date'  => $payment->payment_date,
                'amount'           => $payment->net_amount,
                'source_type'      => FacilityWithdrawal::SOURCE_CASH_EXPENSE_PAYMENT,
                'source_id'        => $payment->id,
                'journal_entry_id' => $journalEntry->id,
                'description'      => 'Cash Expense Payment '.$payment->payment_code,
            ], $actor);
        }

        if ($payment->isCheque()) {
            $this->createChequePayable($payment, $chequeData, $actor);
        }
    }

    /**
     * Deductible (default): posts to 137 VAT Input. Non-deductible
     * (confirmed with Mahmoud, August 2026 — same rule as Supplier
     * Bill migration 000180): folds into THIS SAME line's own
     * expense_account_id instead — the VAT becomes part of the
     * expense's own cost.
     */
    private function addLineVat(CashExpensePayment $payment, CashExpensePaymentLine $line, array &$debitBuckets): void
    {
        if (bccomp((string) $line->vat_amount_functional, '0', 2) <= 0) {
            return;
        }

        if ($line->is_deductible) {
            $vatAccount = ChartOfAccount::findByCode($payment->company_id, '137');
            if (! $vatAccount) {
                throw new \DomainException('VAT Input account (137) is not seeded for this company — cannot post.');
            }
            $this->addToBucket($debitBuckets, $vatAccount->id, (string) $line->vat_amount_functional);
            return;
        }

        $this->addToBucket($debitBuckets, $line->expense_account_id, (string) $line->vat_amount_functional);
    }

    private function addToBucket(array &$buckets, int $accountId, string $amount): void
    {
        $buckets[$accountId] = bcadd($buckets[$accountId] ?? '0', $amount, 2);
    }

    // ── Private — source resolution (mirrors OtherPaymentService) ──

    private function resolveSourceAccount(CashExpensePayment $payment): ChartOfAccount
    {
        return match ($payment->money_type) {
            CashExpensePayment::MONEY_OUTGOING_TRANSFER => $this->resolveBankOrFacilityAccount($payment),
            CashExpensePayment::MONEY_CASH_PAYMENT      => $this->resolveSafeAccount($payment),
            CashExpensePayment::MONEY_PAYABLE_CHEQUE    => $this->resolveNotesPayableAccount($payment->company_id),
            default => throw new \DomainException("Unknown money type: {$payment->money_type}"),
        };
    }

    private function resolveBankOrFacilityAccount(CashExpensePayment $payment): ChartOfAccount
    {
        if ($payment->source_type === CashExpensePayment::SOURCE_CREDIT_FACILITY) {
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

    private function resolveSafeAccount(CashExpensePayment $payment): ChartOfAccount
    {
        $account = ChartOfAccount::withoutGlobalScopes()->forCompany($payment->company_id)
            ->where('id', $payment->safe_account_id)->first();

        return $account ?? throw new \DomainException('The selected Safe account does not belong to this company.');
    }

    // Payable Cheque credits 222001 at issue time — same as Supplier Payment / Other Payment.
    private function resolveNotesPayableAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '222001')
            ?? throw new \DomainException('Chart of Accounts is missing system account 222001 (Notes Payable — Suppliers) — check the seeder.');
    }

    private function narrationFor(CashExpensePayment $payment): string
    {
        return "Cash Expense Payment {$payment->payment_code} — {$payment->payee_name}";
    }

    private function createChequePayable(CashExpensePayment $payment, ?array $chequeData, User $actor): ChequePayable
    {
        if (! $chequeData) {
            throw new \DomainException('Cheque details are required for a Payable Cheque payment.');
        }

        // cheques_payable.partner_id is NOT NULL — a physical cheque
        // needs a named payee (same real-world reasoning as everywhere
        // else this table is written to). Partner is optional on Cash
        // Expense Payment generally, but not for this money type.
        if (! $payment->partner_id) {
            throw new \DomainException('A Partner must be selected to issue a Payable Cheque — a cheque needs a named payee.');
        }

        return ChequePayable::create([
            'company_id'              => $payment->company_id,
            'cash_expense_payment_id' => $payment->id,
            'partner_id'              => $payment->partner_id,
            'cheque_amount'           => $payment->net_amount,
            // Audit C8: remember the cheque's currency and the EGP value booked, so clearing it can value any FX difference.
            'currency_id'             => $payment->currency_id,
            'exchange_rate'           => $payment->exchange_rate ?: 1,
            'amount_functional'       => $payment->net_amount_functional,
            'cheque_number'           => $chequeData['cheque_number'],
            'due_date'                => $chequeData['due_date'],
            'status'                  => ChequePayable::STATUS_ISSUED,
            'created_by'              => $actor->id,
        ]);
    }

    /**
     * Replaces every allocation row for this payment — "Allocating with
     * Customer Contracts" (migration 000179). Reporting-only: never
     * touches the journal entry, never required to add up to the full
     * payment. The FormRequest already checked sum <= subtotal and no
     * duplicate projects per line before this runs.
     *
     * Each row's 'line_index' (0-based position in the submitted
     * `lines` array) is resolved against $lineIdsByIndex — produced by
     * syncLines() moments earlier in the same transaction — into a
     * real cash_expense_payment_line_id. Omitted/unresolvable index
     * stores null (not tied to a specific line).
     */
    private function syncAllocations(CashExpensePayment $payment, array $allocations, array $lineIdsByIndex): void
    {
        $payment->allocations()->delete();

        $rate = (string) $payment->exchange_rate;

        foreach ($allocations as $row) {
            if (empty($row['project_id']) || empty($row['allocated_amount'])) {
                continue;
            }

            $lineIndex = $row['line_index'] ?? null;

            CashExpensePaymentAllocation::create([
                'company_id'                    => $payment->company_id,
                'cash_expense_payment_id'        => $payment->id,
                'cash_expense_payment_line_id'   => $lineIndex !== null ? ($lineIdsByIndex[$lineIndex] ?? null) : null,
                'project_id'                     => $row['project_id'],
                'allocated_amount'               => (string) $row['allocated_amount'],
                'allocated_amount_functional'    => bcmul((string) $row['allocated_amount'], $rate, 2),
            ]);
        }
    }

    private function generatePaymentCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\CashExpensePayment::class, 'payment_code', 'CEP', $date);
    }
}
