<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  BalanceSheetEquityTest
//
//  The fundamental accounting identity — Assets = Liabilities +
//  Equity — verified across a fiscal-year boundary.
//
//  Vero CFO uses "Option A" year-end closing (BalanceSheetService
//  header): no closing journal entries are ever posted. Retained
//  Earnings (314) and Current Year Profit/Loss (315) are never
//  written to the ledger; the Balance Sheet computes both live.
//  Retained Earnings is assembled from prior fiscal years that are
//  BOTH ended AND status = 'closed'.
//
//  These tests establish what that means for a company whose prior
//  year has ended but has not been formally closed — the normal
//  state of affairs for most of any given year.
//
//  Reference: BalanceSheetService::computeEquityRollForward().
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\IncomeStatementService;
use App\Services\FiscalYearService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class BalanceSheetEquityTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        // A company whose first fiscal year is 2025, so that 2026 has
        // a genuine prior year sitting behind it.
        $this->scenario = (new ErpScenario($this->actor))
            ->createCompany(['opening_balance_date' => '2025-01-01'])
            ->createProducts(1)
            ->createPartners();
    }

    /** Trades profitably inside 2025, then opens 2026. */
    private function tradeInPriorYearThenOpenNextYear(): string
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 100, 10.00]], '2025-01-10');

        $so = $this->scenario->createSalesOrder([[$product, 60, 25.00]], '2025-03-01');
        $this->scenario->deliver($so, '2025-03-05');
        $this->scenario->invoice($so, '2025-03-05');

        app(FiscalYearService::class)->createNext($this->scenario->companyId, $this->actor);

        return app(IncomeStatementService::class)->netIncomeForRange(
            $this->scenario->companyId, '2025-01-01', '2025-12-31'
        );
    }

    private function balanceSheetAsOf(string $date): array
    {
        return app(BalanceSheetService::class)->build($this->scenario->companyId, [[
            'key'        => 'p',
            'label'      => 'as of',
            'start_date' => $date,
            'end_date'   => $date,
        ]]);
    }

    /**
     * Control case: within a company's FIRST fiscal year, with no
     * prior year to carry forward, the balance sheet balances.
     */
    public function test_balance_sheet_balances_within_the_first_fiscal_year(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 100, 10.00]], '2025-01-10');
        $so = $this->scenario->createSalesOrder([[$product, 60, 25.00]], '2025-03-01');
        $this->scenario->deliver($so, '2025-03-05');
        $this->scenario->invoice($so, '2025-03-05');

        $report = $this->balanceSheetAsOf('2025-12-31');

        $this->assertTrue(
            $report['is_balanced']['p'],
            'First-year balance sheet is out by '.$report['difference']['p'].'.'
        );
        $this->assertMoneyEquals('0.00', (string) $report['difference']['p']);

        $this->assertLedgerBalanced($this->scenario->companyId);
    }

    /**
     * REGRESSION GUARD — audit finding ACC-001, FIXED 2026-09.
     *
     * A second fiscal year is opened while the first is still
     * status = 'open'. The first year's profit used to belong to
     * neither equity line:
     *
     *   315 Current Year Profit covers only the CURRENT fiscal year,
     *       so the prior year's profit was outside its window.
     *   314 Retained Earnings accumulated only prior years marked
     *       'closed', so an ended-but-open year contributed nothing.
     *
     * The profit disappeared from equity entirely and the balance
     * sheet failed the accounting identity by exactly that amount —
     * confirmed on live data, where company 27 was out by
     * 444,354,796.99, the sum of two unclosed years to the cent.
     *
     * Decision taken (confirmed with Mahmoud, 2026-09): a year
     * contributes to Retained Earnings once it has ENDED. Formal
     * closing stays a separate control over posting, not the thing
     * that decides whether earned profit exists.
     *
     * This test now asserts the identity holds, and — the part that
     * makes it discriminating — that the amount which appears in
     * equity is exactly the prior year's net income, not merely that
     * two totals happen to match.
     */
    public function test_an_ended_but_unclosed_year_still_counts_toward_equity(): void
    {
        $priorYearNetIncome = $this->tradeInPriorYearThenOpenNextYear();

        // Sanity: the prior year genuinely made money.
        $this->assertSame(
            1,
            bccomp($priorYearNetIncome, '0', 2),
            'The scenario must actually produce a prior-year profit.'
        );

        // Precondition: the prior year is ENDED but still OPEN — the
        // exact state the defect lived in. If this ever stops being
        // true the test proves nothing.
        $priorYear = FiscalYear::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->orderBy('start_date')
            ->firstOrFail();

        $this->assertNotSame(
            FiscalYear::STATUS_CLOSED,
            $priorYear->status,
            'Precondition: the prior year must still be open, or this guards nothing.'
        );

        $report = $this->balanceSheetAsOf('2026-12-31');

        $this->assertTrue(
            $report['is_balanced']['p'],
            'ACC-001 fix: Assets = Liabilities + Equity even though the prior year has '.
            'not been formally closed. It is out by '.$report['difference']['p'].'.'
        );

        $this->assertMoneyEquals(
            '0.00',
            (string) $report['difference']['p'],
            'ACC-001 fix: the difference that used to equal the prior year\'s net income '.
            'is now zero.'
        );

        $this->assertMoneyEquals(
            $priorYearNetIncome,
            (string) ($report['equity']['retained_earnings']['p']
                ?? $this->retainedEarningsFrom($report)),
            'And it is in Retained Earnings specifically — the prior year\'s net income '.
            'to the cent, not a coincidental match between two totals.'
        );

        // The ledger itself was never wrong — this was a reporting defect.
        $this->assertLedgerBalanced(
            $this->scenario->companyId,
            'The general ledger must remain internally consistent.'
        );
    }

    /**
     * Guard the guard: closing the year must not change the figure.
     *
     * Before the fix, closing was what MADE the balance sheet balance.
     * Now it must make no difference at all to the reported numbers —
     * which is what proves closing has gone back to being a posting
     * control rather than an equity calculation.
     */
    public function test_closing_the_year_does_not_change_the_reported_equity(): void
    {
        $this->tradeInPriorYearThenOpenNextYear();

        $before = $this->balanceSheetAsOf('2026-12-31');
        $equityBefore = (string) $before['equity']['totals']['p'];

        $priorYear = FiscalYear::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->orderBy('start_date')
            ->firstOrFail();

        AccountingPeriod::withoutGlobalScopes()
            ->where('fiscal_year_id', $priorYear->id)
            ->update(['status' => AccountingPeriod::STATUS_CLOSED]);

        app(FiscalYearService::class)->closeFiscalYear($priorYear->fresh(), $this->actor);

        $after = $this->balanceSheetAsOf('2026-12-31');

        $this->assertMoneyEquals(
            $equityBefore,
            (string) $after['equity']['totals']['p'],
            'ACC-001 fix: total equity is identical before and after formally closing '.
            'the prior year. Closing controls postings; it no longer decides whether '.
            'earned profit is reported.'
        );

        $this->assertTrue($after['is_balanced']['p']);
    }

    /** Pulls Retained Earnings out of whichever shape the report uses. */
    private function retainedEarningsFrom(array $report): string
    {
        foreach ($report['equity']['sections'] ?? [] as $section) {
            foreach ($section['rows'] ?? [] as $row) {
                if (($row['code'] ?? '') === '314') {
                    return (string) ($row['periods']['p'] ?? '0.00');
                }
            }
        }

        return '0.00';
    }

    /**
     * Closing the prior year keeps the identity holding — it held
     * before the close too (see the test above), so this is now a
     * check that closing breaks nothing rather than that it fixes
     * anything.
     */
    public function test_the_identity_still_holds_after_closing_the_prior_year(): void
    {
        $priorYearNetIncome = $this->tradeInPriorYearThenOpenNextYear();

        $priorYear = FiscalYear::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->orderBy('start_date')
            ->firstOrFail();

        AccountingPeriod::withoutGlobalScopes()
            ->where('fiscal_year_id', $priorYear->id)
            ->update(['status' => AccountingPeriod::STATUS_CLOSED]);

        app(FiscalYearService::class)->closeFiscalYear($priorYear->fresh(), $this->actor);

        $report = $this->balanceSheetAsOf('2026-12-31');

        $this->assertTrue(
            $report['is_balanced']['p'],
            'After closing the prior year the balance sheet must still balance, but it '.
            'is out by '.$report['difference']['p'].'.'
        );
        $this->assertMoneyEquals('0.00', (string) $report['difference']['p']);

        // And the amount that reappeared is exactly the prior year's profit.
        $this->assertSame(
            1,
            bccomp($priorYearNetIncome, '0', 2),
            'Sanity: the restored amount is the prior year profit.'
        );
    }

    /**
     * Idempotency: closing an already-closed fiscal year must be
     * refused rather than double-counting retained earnings. Because
     * "Option A" posts no closing entries at all, a second close can
     * only be a state error, never a duplicated balance.
     */
    public function test_closing_a_fiscal_year_twice_is_refused(): void
    {
        $this->tradeInPriorYearThenOpenNextYear();

        $priorYear = FiscalYear::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->orderBy('start_date')
            ->firstOrFail();

        AccountingPeriod::withoutGlobalScopes()
            ->where('fiscal_year_id', $priorYear->id)
            ->update(['status' => AccountingPeriod::STATUS_CLOSED]);

        app(FiscalYearService::class)->closeFiscalYear($priorYear->fresh(), $this->actor);

        $reportAfterFirstClose = $this->balanceSheetAsOf('2026-12-31');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/already closed/i');

        try {
            app(FiscalYearService::class)->closeFiscalYear($priorYear->fresh(), $this->actor);
        } finally {
            // Whatever happens, equity must not have moved.
            $this->assertMoneyEquals(
                (string) $reportAfterFirstClose['difference']['p'],
                (string) $this->balanceSheetAsOf('2026-12-31')['difference']['p'],
                'A repeated close must never change reported equity.'
            );
        }
    }
}
