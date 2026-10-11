<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  YearEnd
//
//  Opening the next fiscal year, and closing the one that just
//  finished — in the order a real company does it.
//
//  THE ORDER MATTERS AND IT IS NOT THE OBVIOUS ONE. A company does
//  not close its books on 31 December. It opens January, keeps
//  trading, and formally closes the previous year some weeks later
//  once everything has been reconciled. So the cycle does the same:
//
//      open fiscal year N+1
//        ↓
//      check the balance sheet while N has ENDED but is still OPEN
//        ↓
//      close every period of N, then close N itself
//        ↓
//      check the balance sheet again
//
//  That middle step is deliberate. Vero CFO computes equity live
//  rather than posting closing entries, and Retained Earnings only
//  accumulates prior years marked 'closed' — so a year that has ended
//  but is not yet closed contributes its profit to NEITHER equity
//  line. Audit finding ACC-001. A multi-year cycle passes through
//  that state naturally every single year, which makes it the right
//  place to measure the gap rather than assume it.
//
//  The cycle does not avoid the window, does not close early to make
//  the numbers look right, and does not adjust anything. It measures,
//  records, and carries on.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Verify;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\IncomeStatementService;
use App\Services\Accounting\TrialBalanceService;
use App\Services\FiscalYearService;

class YearEnd
{
    public function __construct(
        private CycleLog $log,
        private FormGate $gate,
    ) {
    }

    /**
     * Opens the fiscal year the cycle is about to trade in. Nothing
     * can be posted into a year that does not exist.
     */
    public function openNextYear(CycleContext $ctx, int $year): bool
    {
        $this->log->enterPeriod("open FY{$year}");

        $existing = FiscalYear::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->whereYear('start_date', $year)
            ->first();

        if ($existing) {
            return true;
        }

        $fy = $this->gate->submit(
            null,
            [],
            fn () => app(FiscalYearService::class)->createNext($ctx->companyId, $ctx->actor),
            "Open fiscal year {$year}",
            'fiscal year'
        );

        if (! $fy) {
            return false;
        }

        $verify = new Verify($this->log, $ctx->companyId);
        $verify->truth(
            $fy->periods()->count() === 12,
            "Fiscal year {$year} was opened without a full set of accounting periods",
            "Open fiscal year {$year}",
            ['periods' => $fy->periods()->count()],
            prefix: 'FY'
        );

        $this->log->note("Fiscal year {$year} opened ({$fy->start_date->toDateString()} → {$fy->end_date->toDateString()}).");

        return true;
    }

    /**
     * Closes a year that has finished trading, checking the books on
     * both sides of the close.
     */
    public function close(CycleContext $ctx, int $year, int $yearIndex, int $totalYears): void
    {
        $this->log->enterPeriod("close FY{$year}");

        $verify = new Verify($this->log, $ctx->companyId);

        $fy = FiscalYear::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->whereYear('start_date', $year)
            ->first();

        if (! $fy) {
            return;
        }

        $asOf = $fy->end_date->toDateString();

        // ── The trial balance must balance. Always. ───────────────
        $this->checkTrialBalance($ctx, $verify, $fy->start_date->toDateString(), $asOf, $year);

        $netIncome = app(IncomeStatementService::class)
            ->netIncomeForRange($ctx->companyId, $fy->start_date->toDateString(), $asOf);

        // ── Close every period, in sequence ───────────────────────
        $periods = AccountingPeriod::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('fiscal_year_id', $fy->id)
            ->orderBy('start_date')
            ->get();

        $closedCount = 0;
        foreach ($periods as $period) {
            if ($period->status === AccountingPeriod::STATUS_CLOSED) {
                $closedCount++;

                continue;
            }

            try {
                app(FiscalYearService::class)->closePeriod($period, $ctx->actor);
                $closedCount++;
                $this->log->operation('accounting period close');
            } catch (\Throwable $e) {
                $this->log->finding(
                    prefix: 'FY',
                    severity: Finding::SEVERITY_HIGH,
                    confidence: Finding::CONFIDENCE_CONFIRMED,
                    title: "Closing accounting period [{$period->name}] failed",
                    operation: "Close fiscal year {$year}",
                    expected: 'the period to close',
                    actual: get_class($e).': '.$e->getMessage(),
                );
                break;
            }
        }

        if ($closedCount < $periods->count()) {
            return;
        }

        // ── Close the year ────────────────────────────────────────
        $closed = $this->gate->submit(
            null,
            [],
            fn () => app(FiscalYearService::class)->closeFiscalYear($fy->fresh(), $ctx->actor),
            "Close fiscal year {$year}",
            'fiscal year close'
        );

        if (! $closed) {
            return;
        }

        // ── Idempotency: closing again must be refused ────────────
        $this->gate->expectRefused(
            fn () => app(FiscalYearService::class)->closeFiscalYear($fy->fresh(), $ctx->actor),
            '/already closed/i',
            "Close fiscal year {$year} a second time",
            'a year that is already closed cannot be closed again'
        );

        // ── The accounting identity, AFTER closing ────────────────
        $this->checkBalanceSheet($ctx, $verify, $asOf, "Close fiscal year {$year}", expectBalanced: true);

        // ── Nothing may be posted into the closed year ────────────
        $this->checkClosedYearIsSealed($ctx, $year, $asOf);

        $this->log->note("Fiscal year {$year} closed. Net income: ".number_format((float) $netIncome, 2).' EGP.');
    }

    // ══════════════════════════════════════════════════════════════

    private function checkTrialBalance(CycleContext $ctx, Verify $verify, string $from, string $to, int $year): void
    {
        try {
            $report = app(TrialBalanceService::class)->build($ctx->companyId, [[
                'key' => 'p', 'label' => (string) $year, 'start_date' => $from, 'end_date' => $to,
            ]]);
        } catch (\Throwable $e) {
            $this->log->finding(
                prefix: 'RPT',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'The trial balance could not be produced',
                operation: "Year-end reporting {$year}",
                expected: 'a trial balance for the year',
                actual: get_class($e).': '.$e->getMessage(),
            );

            return;
        }

        $verify->money(
            (string) $report['totals']['p']['debit'],
            (string) $report['totals']['p']['credit'],
            'The trial balance does not balance',
            "Year-end reporting {$year}",
            ['debit' => $report['totals']['p']['debit'], 'credit' => $report['totals']['p']['credit']],
            Finding::SEVERITY_CRITICAL,
            prefix: 'RPT'
        );
    }

    /**
     * Measures the ACC-001 window: a year that has ended but is not
     * yet closed. Its profit belongs to neither equity line, so the
     * balance sheet should be out by exactly that figure.
     *
     * Recorded as a MEDIUM/CONFIRMED reproduction rather than a new
     * finding — the defect is already known; what this adds is that a
     * normal multi-year business passes through the window every year
     * without doing anything unusual.
     */
    /**
     * THE ACC-001 WINDOW, measured at the only moment it is visible.
     *
     * Viewed as at 31 December, a year that has just ended still
     * balances: it is the CURRENT year, so its profit sits in Current
     * Year Profit and equity is complete. The defect only appears once
     * you look from INSIDE the following year — now the previous year
     * is neither "current" nor "closed", and its profit belongs to no
     * equity line at all.
     *
     * So this is called after the new year has been opened and before
     * the old one has been closed: exactly where a real finance team
     * sits every January, and exactly the state company 27 was found
     * in on live data.
     */
    public function measureOpenPriorYears(CycleContext $ctx, int $newYear): void
    {
        $this->log->enterPeriod("FY{$newYear} opening review");

        $asOf = "{$newYear}-01-31";

        $openPriorYears = FiscalYear::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('end_date', '<', "{$newYear}-01-01")
            ->where('status', '!=', FiscalYear::STATUS_CLOSED)
            ->orderBy('start_date')
            ->get();

        if ($openPriorYears->isEmpty()) {
            return;
        }

        // What those unclosed years earned — computed independently
        // from the income statement, not read off the balance sheet.
        $strandedProfit = '0.00';
        $names = [];
        foreach ($openPriorYears as $priorYear) {
            $strandedProfit = bcadd($strandedProfit, app(IncomeStatementService::class)->netIncomeForRange(
                $ctx->companyId,
                $priorYear->start_date->toDateString(),
                $priorYear->end_date->toDateString()
            ), 2);
            $names[] = $priorYear->name;
        }

        if (bccomp($strandedProfit, '0', 2) === 0) {
            return;
        }

        $report = $this->buildBalanceSheet($ctx, $asOf);

        if ($report === null) {
            return;
        }

        if ((bool) $report['is_balanced']['p']) {
            // The balance sheet balances despite an unclosed prior
            // year — the defect is not present here.
            $this->log->checkPassed();

            return;
        }

        $difference = (string) $report['difference']['p'];
        $exact      = bccomp($difference, $strandedProfit, 2) === 0;

        $this->log->finding(
            prefix: 'ACC-001-REPRO',
            severity: Finding::SEVERITY_HIGH,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: 'Balance sheet does not balance while a prior year has ended but is not yet closed',
            operation: "Opening review, FY{$newYear}",
            expected: 'Assets = Liabilities + Equity',
            actual: "out by {$difference}",
            difference: $difference,
            evidence: $exact
                ? 'The gap is EXACTLY the net income of the unclosed prior year(s) ('
                    .implode(', ', $names).' = '.$strandedProfit.'), which identifies the cause beyond doubt.'
                : 'Unclosed prior year(s) '.implode(', ', $names).' earned '.$strandedProfit
                    .'; the balance sheet gap is '.$difference.'. They differ, so something else contributes too.',
            source: 'BalanceSheetService::computeEquityRollForward()',
            rootCause: "Retained Earnings only accumulates prior fiscal years with status 'closed'. "
                .'A year that has ended but is still open contributes to neither Retained Earnings nor '
                .'Current Year Profit, so its profit disappears from equity entirely.',
            context: [
                'balance sheet as of'   => $asOf,
                'unclosed prior years'  => implode(', ', $names),
                'their net income'      => $strandedProfit,
                'balance sheet gap'     => $difference,
            ],
        );
    }

    private function measureUnclosedYearGap(CycleContext $ctx, Verify $verify, int $year, string $asOf, string $netIncome): void
    {
        $report = $this->buildBalanceSheet($ctx, $asOf);

        if ($report === null) {
            return;
        }

        $difference = (string) $report['difference']['p'];
        $balanced   = (bool) $report['is_balanced']['p'];

        if ($balanced) {
            // The window did not open — either the defect has been
            // fixed, or this year made no profit.
            $this->log->checkPassed();

            return;
        }

        $matchesNetIncome = bccomp(
            $difference,
            $netIncome,
            2
        ) === 0;

        $this->log->finding(
            prefix: 'ACC-001-REPRO',
            severity: Finding::SEVERITY_HIGH,
            confidence: Finding::CONFIDENCE_CONFIRMED,
            title: "Balance sheet does not balance while FY{$year} has ended but is not yet closed",
            operation: "Year-end reporting {$year}",
            expected: 'Assets = Liabilities + Equity',
            actual: "out by {$difference}",
            difference: $difference,
            evidence: $matchesNetIncome
                ? "The gap is EXACTLY FY{$year}'s net income ({$netIncome}), which identifies the cause beyond doubt."
                : "FY{$year} net income is {$netIncome}; the gap is {$difference}. They differ, so more than one year may be contributing.",
            source: 'BalanceSheetService::computeEquityRollForward()',
            rootCause: "Retained Earnings only accumulates prior years with status 'closed'. "
                .'A year that has ended but is still open contributes to neither Retained Earnings nor Current Year Profit.',
            context: [
                'as of'                    => $asOf,
                "FY{$year} net income"     => $netIncome,
                'balance sheet difference' => $difference,
            ],
        );
    }

    private function checkBalanceSheet(CycleContext $ctx, Verify $verify, string $asOf, string $operation, bool $expectBalanced): void
    {
        $report = $this->buildBalanceSheet($ctx, $asOf);

        if ($report === null) {
            return;
        }

        if (! $expectBalanced) {
            return;
        }

        $verify->truth(
            (bool) $report['is_balanced']['p'],
            'The balance sheet does not balance after the year was formally closed',
            $operation,
            [
                'as of'      => $asOf,
                'difference' => (string) $report['difference']['p'],
            ],
            Finding::SEVERITY_CRITICAL,
            prefix: 'RPT'
        );
    }

    private function buildBalanceSheet(CycleContext $ctx, string $asOf): ?array
    {
        try {
            return app(BalanceSheetService::class)->build($ctx->companyId, [[
                'key' => 'p', 'label' => 'as of', 'start_date' => $asOf, 'end_date' => $asOf,
            ]]);
        } catch (\Throwable $e) {
            $this->log->finding(
                prefix: 'RPT',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'The balance sheet could not be produced',
                operation: 'Year-end reporting',
                expected: 'a balance sheet as of '.$asOf,
                actual: get_class($e).': '.$e->getMessage(),
            );

            return null;
        }
    }

    /**
     * A closed year must be sealed. The cycle proves it by trying to
     * post an ordinary expense into it and requiring a refusal.
     */
    private function checkClosedYearIsSealed(CycleContext $ctx, int $year, string $asOf): void
    {
        $this->gate->expectRefused(
            fn () => app(\App\Services\Accounting\JournalEntryService::class)->post(
                companyId: $ctx->companyId,
                entryDate: $asOf,
                lines: [
                    ['chart_of_account_id' => $ctx->expenseAccounts['Office Rent'], 'debit' => '1.00', 'credit' => '0', 'description' => 'seal probe'],
                    ['chart_of_account_id' => (int) $ctx->bankAccounts[0]->coa_account_id, 'debit' => '0', 'credit' => '1.00', 'description' => 'seal probe'],
                ],
                sourceType: 'manual_journal_entry',
                sourceId: null,
                narration: 'Closed-period probe',
                actor: $ctx->actor,
            ),
            '/closed/i',
            "Post into closed fiscal year {$year}",
            'a closed accounting period must refuse new postings'
        );
    }
}
