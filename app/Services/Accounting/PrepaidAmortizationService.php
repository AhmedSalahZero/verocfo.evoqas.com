<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  PrepaidAmortizationService
//
//  Posts the monthly amortization for a Prepaid Expense Bill line:
//  Dr [line's own chart_of_account_id] / Cr 136 Prepaid Expenses.
//  Deliberate mirror of
//  App\Services\FinancialInstitutions\LeaseAmortizationService::
//  postMonthlyWearDown() / reverseWearDown() — same click-per-period
//  shape (one PrepaidAmortizationEntry row created per click, amount
//  computed live, never a pre-built 12-row schedule table and never a
//  scheduled job), same reverse-never-delete rule. Chosen deliberately
//  to reuse a pattern already proven in production rather than invent
//  a second, more complex mechanism for a conceptually identical
//  problem (a non-cash, auto-postable-but-reversible monthly entry).
//
//  MID-MONTH CONVENTION (confirmed by Mahmoud, worked example: EGP
//  120,000 / 12 months / Bill Date 15 Jan 2026):
//    - Bill Date day <= 15  → the bill's own calendar month counts as
//      ONE FULL period. Total periods = amortization_months.
//    - Bill Date day >= 16  → the bill's own calendar month AND the
//      final month both count as HALF a period each. Total periods =
//      amortization_months + 1, the first and last carrying half the
//      normal monthly amount.
//  This is the standard mid-month convention used in straight-line
//  depreciation/amortization schedules generally (matches US MACRS'
//  own day-15 cutoff) — not an improvised shortcut.
//
//  Every period's amount is computed FRESH at post time from
//  (line_subtotal_functional ÷ amortization_months) and the period's
//  weight (1 or 0.5) — never read from a stored schedule — with the
//  LAST period always absorbing whatever bcmath rounding residue is
//  left, so the sum of every posted period always reconciles exactly
//  to what was originally debited to 136 for that line. This is the
//  same last-period-absorbs-the-remainder rule already used by
//  LeaseAmortizationService::postMonthlyWearDown().
//
//  @layer    Service
//  @depends  SupplierBillLine, PrepaidAmortizationEntry, ChartOfAccount,
//            JournalEntryService, FiscalYearService, User
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\PrepaidAmortizationEntry;
use App\Models\SupplierBillLine;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Support\Facades\DB;

class PrepaidAmortizationService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
    ) {}

    /**
     * How many periods this line's schedule has in total, given its
     * Amortization Months and the parent bill's Bill Date. See class
     * header for the mid-month convention.
     */
    public function totalPeriods(SupplierBillLine $line): int
    {
        $months = (int) $line->amortization_months;
        $day = (int) $line->supplierBill->bill_date->format('d');

        return $day >= 16 ? $months + 1 : $months;
    }

    /**
     * This specific period's weight — '1' for a full period, '0.5' for
     * a half period (only the first and last periods of a day>=16
     * schedule are ever half periods).
     */
    private function periodWeight(SupplierBillLine $line, int $periodNumber, int $totalPeriods): string
    {
        $day = (int) $line->supplierBill->bill_date->format('d');
        $isHalfMonthSchedule = $day >= 16;

        if (! $isHalfMonthSchedule) {
            return '1';
        }

        $isFirstOrLast = $periodNumber === 1 || $periodNumber === $totalPeriods;

        return $isFirstOrLast ? '0.5' : '1';
    }

    /**
     * Posts the next unposted period for this line — the "Post Next
     * Amortization" button's action. Mirrors postMonthlyWearDown()
     * exactly: computes the period fresh, throws once every period is
     * already posted, respects closed periods rather than forcing
     * through them.
     */
    public function postNextPeriod(SupplierBillLine $line, User $actor, ?\Carbon\Carbon $forDate = null): PrepaidAmortizationEntry
    {
        $line->loadMissing('supplierBill');
        $bill = $line->supplierBill;

        if (! $bill->isPrepaidBill()) {
            throw new \DomainException('This line does not belong to a Prepaid Expense Bill.');
        }
        if (! $bill->isPosted()) {
            throw new \DomainException('Only a line on a posted Prepaid Expense Bill can have amortization posted.');
        }
        if (! $line->amortization_months || (int) $line->amortization_months < 1) {
            throw new \DomainException("Line \"{$line->description}\" has no Amortization Months set — cannot post.");
        }
        if (! $line->chart_of_account_id) {
            throw new \DomainException("Line \"{$line->description}\" has no Expense Account selected — cannot post.");
        }

        $totalPeriods = $this->totalPeriods($line);
        $alreadyPosted = $line->amortizationEntries()->posted()->count();
        $nextPeriod = $alreadyPosted + 1;

        if ($alreadyPosted >= $totalPeriods) {
            throw new \DomainException("Line \"{$line->description}\" is already fully amortized — nothing left to post.");
        }

        // Audit M-19 — each period belongs to its own calendar month.
        // Unless the caller names a date, the entry is dated at the end
        // of that month (never later than today), so catching up several
        // months gives each its own month instead of piling onto today.
        $periodStart = $bill->bill_date->copy()->startOfMonth()->addMonths($nextPeriod - 1);
        $forDate = $forDate ?? ($periodStart->copy()->endOfMonth()->greaterThan(now()) ? now() : $periodStart->copy()->endOfMonth());

        if ($forDate->lt($periodStart)) {
            throw new \DomainException('Period '.$nextPeriod.' of this schedule covers '.$periodStart->format('M Y').' — it cannot be posted with an earlier date.');
        }
        if ($line->amortizationEntries()->posted()
            ->whereYear('entry_date', $forDate->year)->whereMonth('entry_date', $forDate->month)->exists()) {
            throw new \DomainException('An amortization entry is already posted for this line in '.$forDate->format('M Y').' — one period per month.');
        }

        if ($this->fiscalYearService->isPeriodClosed($line->company_id, $forDate->toDateString())) {
            throw new \DomainException('The accounting period for this date is closed — post to an open period instead.');
        }

        $periodNumber = $alreadyPosted + 1;

        // Every posted-so-far amount already reconciles to the line's
        // own subtotal by construction (see below), so the remaining
        // balance is simply what hasn't been posted yet — read live,
        // never assumed. Guarantees the final period always closes 136
        // to exactly zero for this line regardless of any earlier
        // rounding, matching LeaseAmortizationService's own
        // last-period rule.
        $lineTotal = $this->lineSubtotalFunctional($line);
        $postedSoFar = (string) $line->amortizationEntries()->posted()->sum('amount');
        $remaining = bcsub($lineTotal, $postedSoFar, 2);

        if ($periodNumber === $totalPeriods) {
            $periodAmount = $remaining;
        } else {
            $monthlyAmount = bcdiv($lineTotal, (string) $line->amortization_months, 2);
            $weight = $this->periodWeight($line, $periodNumber, $totalPeriods);
            $periodAmount = bcmul($monthlyAmount, $weight, 2);
        }

        if (bccomp($periodAmount, '0', 2) === 0) {
            throw new \DomainException("Line \"{$line->description}\" has nothing left to amortize for this period.");
        }

        $prepaidAccount = ChartOfAccount::findByCode($line->company_id, '136');
        if (! $prepaidAccount) {
            throw new \DomainException('Prepaid Expenses account (136) is not seeded for this company — cannot post.');
        }

        return DB::transaction(function () use ($line, $bill, $actor, $forDate, $periodNumber, $periodAmount, $prepaidAccount) {
            $entry = $this->journalEntryService->post(
                companyId: $line->company_id,
                entryDate: $forDate,
                lines: [
                    ['chart_of_account_id' => $line->chart_of_account_id, 'debit' => $periodAmount, 'credit' => '0', 'description' => "Period {$periodNumber} amortization — {$line->description}"],
                    ['chart_of_account_id' => $prepaidAccount->id, 'debit' => '0', 'credit' => $periodAmount, 'description' => "Period {$periodNumber} amortization — {$line->description}"],
                ],
                sourceType: 'supplier_bill_line',
                sourceId: $line->id,
                narration: "Prepaid amortization — {$bill->bill_code} — {$line->description} (period {$periodNumber})",
                actor: $actor
            );

            $amortizationEntry = PrepaidAmortizationEntry::create([
                'company_id'            => $line->company_id,
                'supplier_bill_line_id' => $line->id,
                'period_number'         => $periodNumber,
                'entry_date'            => $forDate,
                'amount'                => $periodAmount,
                'journal_entry_id'      => $entry->id,
                'status'                => 'posted',
                'posted_by'             => $actor->id,
            ]);

            AuditLog::record($actor, 'prepaid_amortization_entry.posted', $amortizationEntry, null, $amortizationEntry->fresh()->toArray());

            return $amortizationEntry->fresh();
        });
    }

    /**
     * Reverses a posted period — never a delete, mirrors
     * LeaseAmortizationService::reverseWearDown() exactly. The user
     * decides afterward whether to post a corrected version.
     */
    public function reverseEntry(PrepaidAmortizationEntry $entry, User $actor): PrepaidAmortizationEntry
    {
        if ($entry->status === 'reversed') {
            throw new \DomainException('This amortization entry is already reversed.');
        }

        return DB::transaction(function () use ($entry, $actor) {
            $reversal = $this->journalEntryService->reverse($entry->journalEntry, $actor, 'Prepaid amortization entry reversed by user');

            $entry->update([
                'status'                    => 'reversed',
                'reversal_journal_entry_id' => $reversal->id,
                'reversed_by'               => $actor->id,
                'reversed_at'               => now(),
            ]);

            AuditLog::record($actor, 'prepaid_amortization_entry.reversed', $entry, null, $entry->fresh()->toArray());

            return $entry->fresh();
        });
    }

    /**
     * The functional-currency amount originally debited to 136 for
     * this line — the total the amortization schedule must sum to.
     * Mirrors exactly what SupplierBillService::postPrepaidExpenseLine()
     * + addLineVat() actually posted: the line's own subtotal, PLUS
     * its VAT if that VAT was non-deductible (folded into 136 rather
     * than 137 VAT Input — see addLineVat()'s prepaid branch). Getting
     * this wrong would leave a permanent residual balance in 136 for
     * any prepaid line with non-deductible VAT.
     */
    private function lineSubtotalFunctional(SupplierBillLine $line): string
    {
        $bill = $line->supplierBill;
        $total = bcmul((string) $line->line_subtotal, (string) $bill->exchange_rate, 2);

        if (! $line->is_deductible && bccomp((string) $line->vat_amount, '0', 2) > 0) {
            $total = bcadd($total, bcmul((string) $line->vat_amount, (string) $bill->exchange_rate, 2), 2);
        }

        return $total;
    }
}
