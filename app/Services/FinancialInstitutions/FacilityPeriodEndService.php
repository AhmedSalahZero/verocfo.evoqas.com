<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FacilityPeriodEndService
//
//  AUDIT M21, M22, M23 (Oct 2026) — the month-end entries that
//  facilities and leases were missing. Before this, three things only
//  reached the ledger on the day a payment or a bank charge was
//  posted, so any month-end Balance Sheet / P&L was wrong in between:
//
//    1. Overdraft interest (M23) — earned every day, but booked only
//       when the user typed in the bank's statement charge.
//    2. Medium-term loan interest (M22) — expensed only when an
//       instalment was paid.
//    3. Current portion (M21, M22) — the principal due within 12
//       months stayed inside the long-term liability (213 Lease /
//       211 Loans), so "current liabilities" were understated.
//
//  HOW IT WORKS — the same pattern as the month-end FX revaluation
//  (FxRevaluationService, Audit H12): each entry is posted on the
//  last day of the month and automatically reversed on the 1st of
//  the next month. Nothing the user does afterwards (posting the bank
//  statement, paying an instalment) needs to change — the payment
//  paths keep booking the full real amount, and the reversal removes
//  the estimate, so nothing is ever counted twice.
//
//    od_interest_accrual   Dr 555 Overdraft Interest      Cr 228 Accrued Expenses
//    mtl_interest_accrual  Dr 551 Interest on Loans       Cr 228 Accrued Expenses
//    mtl_current_portion   Dr 211 Long-Term Loans         Cr 225 Current Portion of LT Loans
//    lease_current_portion Dr 213 Lease Liab. — Long-Term Cr 234 Lease Liab. — Current
//
//  AMOUNTS
//    OD interest   = this month's theoretical interest (sum of the
//                    daily rows, 360-day convention) minus interest
//                    already posted from a bank statement for the month.
//    MTL interest  = interest earned on the schedule up to month-end
//                    that has not been paid yet (MtlService).
//    Current part  = unpaid principal due within 12 months.
//
//  Each entry is posted in the functional currency, converted at the
//  month-end rate. One row in facility_period_end_entries keeps the
//  audit trail; a run for the same item and month is skipped if one is
//  already posted (cancel it first to run again).
//
//  Also here: ledgerReconciliation() — M23's "second source of
//  truth". The facility's outstanding_balance field and the general
//  ledger should agree; this compares them and lists any difference.
//
//  Triggered by the scheduled FacilityMonthEndJob on the last day of
//  each month, or by hand with `php artisan facilities:month-end`.
//
//  @layer    Service
//  @depends  FacilityPeriodEndEntry, CreditFacility, LeasingContract,
//            FacilityDailyBalance, FacilityLiabilityCharge, MtlService,
//            FacilitySettlementEngine, JournalEntryService, FxService
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityDailyBalance;
use App\Models\FinancialInstitutions\FacilityLiabilityCharge;
use App\Models\FinancialInstitutions\FacilityPeriodEndEntry;
use App\Models\FinancialInstitutions\LeasingContract;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\AccountBalanceCalculator;
use App\Services\Accounting\FxService;
use App\Services\Accounting\JournalEntryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FacilityPeriodEndService
{
    const SOURCE_TYPE          = 'facility_period_end';
    const SOURCE_TYPE_REVERSAL = 'facility_period_end_reversal';

    private const CODE_ACCRUED_EXPENSES = '228';
    private const CODE_ACCRUED_REVENUE  = '143';   // M-33: accrued deposit interest receivable
    private const CODE_INTEREST_INCOME  = '423';
    private const CODE_OD_INTEREST      = '555';
    private const CODE_LOAN_INTEREST    = '551';
    private const CODE_LOAN_LONG_TERM   = '211';
    private const CODE_LOAN_CURRENT     = '225';
    private const CODE_LEASE_LONG_TERM  = '213';
    private const CODE_LEASE_CURRENT    = '234';

    public function __construct(
        private JournalEntryService $journalEntryService,
        private FacilitySettlementEngine $settlementEngine,
        private MtlService $mtlService,
        private FxService $fx,
    ) {}

    // ── Run for a whole company ───────────────────────────────────

    /**
     * Posts every month-end entry that applies to this company for
     * $periodEnd (must be a month-end). One item failing (a closed
     * period, a missing exchange rate…) is reported and skipped — it
     * never stops the others.
     *
     * @return array{period_end:string, posted:array, skipped:array, failed:array}
     */
    public function runForCompany(int $companyId, string $periodEnd, User $actor): array
    {
        $periodEnd = $this->assertMonthEnd($periodEnd);

        $result = ['period_end' => $periodEnd, 'posted' => [], 'skipped' => [], 'failed' => []];

        // Make sure every overdraft has its daily rows up to month-end
        // (Audit M25 catch-up), so the interest total is complete.
        CreditFacility::withoutGlobalScopes()->where('company_id', $companyId)
            ->odFamily()->where('status', 'active')->get()
            ->each(function (CreditFacility $f) use ($periodEnd) {
                try {
                    $this->settlementEngine->catchUpDailyBalances($f, $periodEnd);
                } catch (\Throwable $e) {
                    Log::error("Month-end catch-up failed for facility #{$f->id}: ".$e->getMessage());
                }
            });

        $jobs = [];

        foreach (CreditFacility::withoutGlobalScopes()->where('company_id', $companyId)->odFamily()->where('status', 'active')->get() as $f) {
            $jobs[] = ['OD interest — '.$f->facility_reference, fn () => $this->postOdInterestAccrual($f, $periodEnd, $actor)];
        }
        foreach (CreditFacility::withoutGlobalScopes()->where('company_id', $companyId)->where('facility_type', 'mtl')->where('status', 'active')->get() as $f) {
            $jobs[] = ['Loan interest — '.$f->name, fn () => $this->postMtlInterestAccrual($f, $periodEnd, $actor)];
            $jobs[] = ['Loan current portion — '.$f->name, fn () => $this->postMtlCurrentPortion($f, $periodEnd, $actor)];
        }
        foreach (\App\Models\FinancialInstitutions\BankAccount::withoutGlobalScopes()->where('company_id', $companyId)->where('account_type', 'time_deposit')->where('is_active', true)->get() as $td) {
            $jobs[] = ['Deposit interest — '.$td->account_name, fn () => $this->postTdInterestAccrual($td, $periodEnd, $actor)];
        }
        foreach (LeasingContract::withoutGlobalScopes()->where('company_id', $companyId)->where('status', 'active')->get() as $c) {
            $jobs[] = ['Lease current portion — '.$c->contract_reference, fn () => $this->postLeaseCurrentPortion($c, $periodEnd, $actor)];
        }

        foreach ($jobs as [$label, $run]) {
            try {
                $entry = $run();
                if ($entry) {
                    $result['posted'][] = $label.' — '.$entry->amount;
                } else {
                    $result['skipped'][] = $label;
                }
            } catch (\Throwable $e) {
                Log::error("Month-end entry failed ({$label}, company #{$companyId}): ".$e->getMessage());
                $result['failed'][] = $label.' — '.$e->getMessage();
            }
        }

        return $result;
    }

    // ── M23 — overdraft interest ──────────────────────────────────

    /** Returns the posted row, or null when there is nothing to accrue / already done. */
    public function postOdInterestAccrual(CreditFacility $facility, string $periodEnd, User $actor): ?FacilityPeriodEndEntry
    {
        $periodEnd = $this->assertMonthEnd($periodEnd);

        $monthStart  = Carbon::parse($periodEnd)->startOfMonth()->toDateString();
        $theoretical = (string) FacilityDailyBalance::withoutGlobalScopes()
            ->where('credit_facility_id', $facility->id)
            ->whereBetween('balance_date', [$monthStart, $periodEnd])
            ->sum('daily_interest_amount');
        $posted      = FacilityLiabilityCharge::postedInterestForMonth($facility->id, substr($periodEnd, 0, 7));

        $amount = $this->round2Positive(bcsub($theoretical, $posted, 6));
        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        return $this->postEntry(
            kind: FacilityPeriodEndEntry::KIND_OD_INTEREST,
            companyId: (int) $facility->company_id,
            facility: $facility,
            contract: null,
            currencyId: $facility->currency_id ? (int) $facility->currency_id : null,
            periodEnd: $periodEnd,
            amount: $amount,
            debitCode: self::CODE_OD_INTEREST,
            creditCode: self::CODE_ACCRUED_EXPENSES,
            label: "Overdraft interest accrued — {$facility->facility_reference}",
            details: ['theoretical' => $theoretical, 'already_posted' => $posted],
            actor: $actor,
        );
    }

    // ── M33 — time-deposit interest earned but not yet received ───

    /**
     * Interest earned on an open time deposit from its last interest
     * date (or start) to month-end: Dr 143 Accrued Revenue / Cr 423
     * Interest Income, reversed automatically on day one. Gross of the
     * tax the bank will withhold (that is recognised when the interest
     * is actually received).
     */
    public function postTdInterestAccrual(\App\Models\FinancialInstitutions\BankAccount $td, string $periodEnd, User $actor): ?FacilityPeriodEndEntry
    {
        $periodEnd = $this->assertMonthEnd($periodEnd);
        if (! $td->td_start_date || ! $td->td_interest_rate || bccomp((string) $td->td_amount, '0', 2) <= 0) {
            return null;
        }

        $last = \App\Models\FinancialInstitutions\BankAccountTdEvent::withoutGlobalScopes()
            ->where('bank_account_id', $td->id)->whereIn('event_type', ['interest', 'renewal'])
            ->max('event_date');
        $from = $last ? Carbon::parse($last) : Carbon::parse($td->td_start_date);
        $to   = $td->td_maturity_date && Carbon::parse($td->td_maturity_date)->lt(Carbon::parse($periodEnd))
            ? Carbon::parse($td->td_maturity_date) : Carbon::parse($periodEnd);
        $days = (int) abs($from->diffInDays($to, false) > 0 ? $from->diffInDays($to) : 0);
        if ($days <= 0) {
            return null;
        }

        // 360-day Egyptian convention, bcmath only.
        $amount = bcdiv(bcmul(bcmul((string) $td->td_amount, bcdiv((string) $td->td_interest_rate, '100', 10), 10), (string) $days, 10), '360', 2);
        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        return $this->postEntry(
            kind: FacilityPeriodEndEntry::KIND_TD_INTEREST,
            companyId: (int) $td->company_id,
            facility: null,
            contract: null,
            currencyId: $td->currency_id ? (int) $td->currency_id : null,
            periodEnd: $periodEnd,
            amount: $amount,
            debitCode: self::CODE_ACCRUED_REVENUE,
            creditCode: self::CODE_INTEREST_INCOME,
            label: "Deposit interest accrued — {$td->account_name}",
            details: ['days' => $days, 'from' => $from->toDateString()],
            actor: $actor,
            bankAccount: $td,
        );
    }

    // ── M22 — medium-term loan interest + current portion ─────────

    public function postMtlInterestAccrual(CreditFacility $facility, string $periodEnd, User $actor): ?FacilityPeriodEndEntry
    {
        $periodEnd = $this->assertMonthEnd($periodEnd);

        $amount = $this->mtlService->accruedInterestAsOf($facility, $periodEnd);
        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        return $this->postEntry(
            kind: FacilityPeriodEndEntry::KIND_MTL_INTEREST,
            companyId: (int) $facility->company_id,
            facility: $facility,
            contract: null,
            currencyId: $facility->currency_id ? (int) $facility->currency_id : null,
            periodEnd: $periodEnd,
            amount: $amount,
            debitCode: self::CODE_LOAN_INTEREST,
            creditCode: self::CODE_ACCRUED_EXPENSES,
            label: "Loan interest accrued — {$facility->name}",
            details: [],
            actor: $actor,
        );
    }

    public function postMtlCurrentPortion(CreditFacility $facility, string $periodEnd, User $actor): ?FacilityPeriodEndEntry
    {
        $periodEnd = $this->assertMonthEnd($periodEnd);

        $amount = $this->mtlService->currentPortionAsOf($facility, $periodEnd);
        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        return $this->postEntry(
            kind: FacilityPeriodEndEntry::KIND_MTL_CURRENT,
            companyId: (int) $facility->company_id,
            facility: $facility,
            contract: null,
            currencyId: $facility->currency_id ? (int) $facility->currency_id : null,
            periodEnd: $periodEnd,
            amount: $amount,
            debitCode: self::CODE_LOAN_LONG_TERM,
            creditCode: self::CODE_LOAN_CURRENT,
            label: "Current portion of loan — {$facility->name}",
            details: [],
            actor: $actor,
        );
    }

    // ── M21 — lease current portion ───────────────────────────────

    public function postLeaseCurrentPortion(LeasingContract $contract, string $periodEnd, User $actor): ?FacilityPeriodEndEntry
    {
        $periodEnd = $this->assertMonthEnd($periodEnd);

        $limit = Carbon::parse($periodEnd)->addMonths(12)->toDateString();
        $total = '0.00';

        $lines = $contract->amortizationLines()->withoutGlobalScopes()->get();
        foreach ($lines as $line) {
            if ($line->payment_date->toDateString() > $limit) {
                continue;
            }
            if ($this->linePaidBy($line, $periodEnd)) {
                continue;
            }
            $total = bcadd($total, (string) $line->principal_amount, 2);
        }

        if (bccomp($total, '0', 2) <= 0) {
            return null;
        }

        return $this->postEntry(
            kind: FacilityPeriodEndEntry::KIND_LEASE_CURRENT,
            companyId: (int) $contract->company_id,
            facility: null,
            contract: $contract,
            currencyId: $contract->currency_id ? (int) $contract->currency_id : null,
            periodEnd: $periodEnd,
            amount: $total,
            debitCode: self::CODE_LEASE_LONG_TERM,
            creditCode: self::CODE_LEASE_CURRENT,
            label: "Current portion of lease liability — {$contract->contract_reference}",
            details: [],
            actor: $actor,
        );
    }

    /** Was this instalment already paid on or before $date? (Uses the payment entry's own date.) */
    private function linePaidBy($line, string $date): bool
    {
        if (! $line->is_paid) {
            return false;
        }
        if (! $line->journal_entry_id) {
            return true;
        }
        $paidOn = JournalEntry::withoutGlobalScopes()->whereKey($line->journal_entry_id)->value('entry_date');

        return $paidOn === null || substr((string) $paidOn, 0, 10) <= $date;
    }

    // ── The shared poster ─────────────────────────────────────────

    private function postEntry(
        string $kind,
        int $companyId,
        ?CreditFacility $facility,
        ?LeasingContract $contract,
        ?int $currencyId,
        string $periodEnd,
        string $amount,
        string $debitCode,
        string $creditCode,
        string $label,
        array $details,
        User $actor,
        ?\App\Models\FinancialInstitutions\BankAccount $bankAccount = null,
    ): ?FacilityPeriodEndEntry {
        $reversalDate = Carbon::parse($periodEnd)->addDay()->toDateString();

        $debitAccount = ChartOfAccount::findByCode($companyId, $debitCode)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$debitCode}.");
        $creditAccount = ChartOfAccount::findByCode($companyId, $creditCode)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$creditCode}.");

        $rate       = $this->fx->rateOn($companyId, $currencyId, $periodEnd);
        $functional = $this->fx->toFunctional($amount, $rate);
        if (bccomp($functional, '0', 2) <= 0) {
            return null;
        }

        return DB::transaction(function () use ($bankAccount, $kind, $companyId, $facility, $contract, $periodEnd, $reversalDate, $amount, $functional, $debitAccount, $creditAccount, $label, $details, $actor) {
            // One run per item per month — serialised per company.
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();

            $exists = FacilityPeriodEndEntry::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('kind', $kind)
                ->when($facility, fn ($q) => $q->where('credit_facility_id', $facility->id), fn ($q) => $q->whereNull('credit_facility_id'))
                ->when($contract, fn ($q) => $q->where('leasing_contract_id', $contract->id), fn ($q) => $q->whereNull('leasing_contract_id'))
                ->when($bankAccount, fn ($q) => $q->where('bank_account_id', $bankAccount->id), fn ($q) => $q->whereNull('bank_account_id'))
                ->whereDate('period_end', $periodEnd)
                ->where('status', FacilityPeriodEndEntry::STATUS_POSTED)
                ->exists();
            if ($exists) {
                return null;
            }

            $row = FacilityPeriodEndEntry::create([
                'company_id'          => $companyId,
                'credit_facility_id'  => $facility?->id,
                'leasing_contract_id' => $contract?->id,
                'bank_account_id'     => $bankAccount?->id,
                'kind'                => $kind,
                'period_end'          => $periodEnd,
                'reversal_date'       => $reversalDate,
                'amount'              => $amount,
                'amount_functional'   => $functional,
                'status'              => FacilityPeriodEndEntry::STATUS_POSTED,
                'details'             => $details ?: null,
                'created_by'          => $actor->id,
            ]);

            $lines = [
                ['chart_of_account_id' => $debitAccount->id,  'debit' => $functional, 'credit' => '0',         'description' => $label],
                ['chart_of_account_id' => $creditAccount->id, 'debit' => '0',         'credit' => $functional, 'description' => $label],
            ];
            $mirror = [
                ['chart_of_account_id' => $debitAccount->id,  'debit' => '0',         'credit' => $functional, 'description' => 'Reversal — '.$label],
                ['chart_of_account_id' => $creditAccount->id, 'debit' => $functional, 'credit' => '0',         'description' => 'Reversal — '.$label],
            ];

            $entry = $this->journalEntryService->post(
                companyId: $companyId,
                entryDate: $periodEnd,
                lines: $lines,
                sourceType: self::SOURCE_TYPE,
                sourceId: $row->id,
                narration: "Month-end entry as at {$periodEnd} — {$label}",
                actor: $actor,
            );
            $reversal = $this->journalEntryService->post(
                companyId: $companyId,
                entryDate: $reversalDate,
                lines: $mirror,
                sourceType: self::SOURCE_TYPE_REVERSAL,
                sourceId: $row->id,
                narration: "Automatic reversal of month-end entry — {$label}",
                actor: $actor,
            );

            $row->update(['journal_entry_id' => $entry->id, 'reversal_journal_entry_id' => $reversal->id]);

            AuditLog::record($actor, 'facility_period_end.posted', $row, null, $row->fresh()->toArray());

            return $row->fresh();
        });
    }

    // ── Cancel ────────────────────────────────────────────────────

    public function cancel(FacilityPeriodEndEntry $row, string $reason, User $actor): FacilityPeriodEndEntry
    {
        if (! $row->isPosted()) {
            throw new \DomainException('Only a posted month-end entry can be cancelled.');
        }
        if (trim($reason) === '') {
            throw new \DomainException('Give a reason for cancelling this month-end entry.');
        }

        return DB::transaction(function () use ($row, $reason, $actor) {
            $row = FacilityPeriodEndEntry::withoutGlobalScopes()->lockForUpdate()->findOrFail($row->id);
            if (! $row->isPosted()) {
                throw new \DomainException('This entry was just cancelled by someone else — refresh the page.');
            }
            $before = $row->toArray();

            foreach ([$row->reversalJournalEntry, $row->journalEntry] as $entry) {
                if ($entry && $entry->status === JournalEntry::STATUS_POSTED) {
                    $this->journalEntryService->reverse($entry, $actor, "Cancelled month-end entry — {$reason}");
                }
            }

            $row->update([
                'status'        => FacilityPeriodEndEntry::STATUS_CANCELLED,
                'cancelled_by'  => $actor->id,
                'cancelled_at'  => now(),
                'cancel_reason' => $reason,
            ]);

            AuditLog::record($actor, 'facility_period_end.cancelled', $row, $before, $row->fresh()->toArray());

            return $row->fresh();
        });
    }

    // ── M23 — outstanding_balance vs the ledger ───────────────────

    /**
     * Compares each active overdraft's outstanding_balance field with
     * the balance of its own account in the general ledger as at
     * $asOf. The two should always be equal; any row returned has a
     * difference worth investigating. Facilities in a foreign currency
     * are listed as 'not compared' (their ledger balance is in the
     * functional currency, at the rates of the day each movement was
     * booked).
     *
     * @return array<int, array<string, mixed>>
     */
    public function ledgerReconciliation(int $companyId, string $asOf): array
    {
        $rows = [];
        $calc = app(AccountBalanceCalculator::class);

        $facilities = CreditFacility::withoutGlobalScopes()->where('company_id', $companyId)
            ->odFamily()->where('status', 'active')->whereNotNull('coa_account_id')->get();

        foreach ($facilities as $f) {
            $row = [
                'facility_id' => $f->id,
                'reference'   => $f->facility_reference,
                'outstanding' => (string) $f->outstanding_balance,
                'ledger'      => null,
                'difference'  => null,
                'compared'    => false,
            ];

            if (! $this->fx->isFunctional($companyId, $f->currency_id ? (int) $f->currency_id : null)) {
                $rows[] = $row;
                continue;
            }

            $account = ChartOfAccount::withoutGlobalScopes()->find($f->coa_account_id);
            if (! $account) {
                $rows[] = $row;
                continue;
            }

            $ledger = $calc->balancesAsOf($companyId, $asOf, collect([$account->id => $account]))[$account->id] ?? '0.00';
            $diff   = bcsub((string) $f->outstanding_balance, (string) $ledger, 2);

            $row['ledger']     = (string) $ledger;
            $row['difference'] = $diff;
            $row['compared']   = true;

            if (bccomp($diff, '0', 2) !== 0) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function assertMonthEnd(string $date): string
    {
        $d = Carbon::parse($date)->startOfDay();
        if (! $d->isSameDay($d->copy()->endOfMonth())) {
            throw new \DomainException('Month-end entries are posted on the last day of a month.');
        }
        if ($d->gt(now()->startOfDay())) {
            throw new \DomainException('A month-end entry cannot be posted before that month has ended.');
        }

        return $d->toDateString();
    }

    /** Positive amount rounded half-up to 2 decimals; anything ≤ 0 becomes '0.00'. */
    private function round2Positive(string $value): string
    {
        if (bccomp($value, '0', 6) <= 0) {
            return '0.00';
        }

        return bcadd(bcadd($value, '0.005', 6), '0', 2);
    }
}
