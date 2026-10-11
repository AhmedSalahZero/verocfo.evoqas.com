<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanFinancialStatementBuilder
//
//  Step 6 of the calculation engine — assembles the outputs of the
//  five calculators (Revenue/COGS/Manpower/Expenses/FixedAssets) into
//  a monthly P&L, Cash Flow, and Balance Sheet that ACTUALLY TIES OUT
//  (Assets = Liabilities + Equity, every month, exactly), given the
//  Phase 1 simplifications those calculators already flag:
//
//    - No VAT/WHT on the Balance Sheet (memo only — PlanRevenueCalculator)
//    - No Accounts Payable (COGS/Expenses paid in cash the same month
//      they're incurred, EXCEPT one-time expenses — see below)
//    - No trading Inventory line (memo only — PlanCogsCalculator)
//    - No debt / no Liabilities line at all — every asset the plan
//      shows is either self-funded from operating cash or funded by
//      the one Equity injection this builder computes (see Cash Flow
//      below). Total Liabilities is always 0.00.
//
//  ONE REAL ACCRUAL LINE, KEPT (not simplified away, because dropping
//  it would silently break the tie-out): one-time expenses are PAID
//  in cash in full up front but EXPENSED in P&L over
//  amortization_months (PlanExpenseCalculator). The gap between the
//  two is a genuine Prepaid Expense asset, computed here as the
//  running total of (expenses cash − expenses P&L) — no new tracking
//  needed, it falls straight out of PlanExpenseCalculator's existing
//  two arrays.
//
//  CASH FLOW — two-pass equity injection, same concept as CFO Tools'
//  buildCashFlow(), computed more directly: Pass 1 runs cash with no
//  injection and finds the single lowest cumulative cash point across
//  the whole plan. If it's negative, that exact shortfall is the
//  Required Equity Injection — added once, at month 0, so cumulative
//  cash never goes negative anywhere in the plan (mathematically
//  equivalent to re-running the whole calculation a second time with
//  the injection folded in, since adding a constant to every month's
//  cumulative total is what a month-0 injection does).
//
//  Corporate tax is booked (and, in this simplified model, paid) at
//  the end of every 12-month block of the plan — no deferred tax, no
//  WHT-as-prepaid-CIT mechanic (see PlanRevenueCalculator's header).
//
//  bcmath throughout (Rule 4). Read-only — pure function of its
//  inputs, no DB writes.
//
//  @layer    Service
//  @depends  Plan
// ══════════════════════════════════════════════════════════════════

use App\Models\Plan;

class PlanFinancialStatementBuilder
{
    /**
     * $inputs = ['revenue'=>.., 'cogs'=>.., 'manpower'=>.., 'expenses'=>.., 'fixedAssets'=>..]
     * — the raw output arrays of the five calculators, plus
     * $openingFigures (PlanOpeningBalanceService::openingFigures() shape,
     * or a zeroed array for a brand-new company).
     *
     * Returns ['pl' => [...monthly...], 'cf' => [...], 'bs' => [...],
     * 'required_equity_injection' => '...'].
     */
    public function build(Plan $plan, array $inputs, array $openingFigures): array
    {
        $pl = $this->buildProfitAndLoss($plan, $inputs);
        [$cf, $requiredInjection] = $this->buildCashFlow($plan, $inputs, $pl, $openingFigures);
        $bs = $this->buildBalanceSheet($plan, $inputs, $pl, $cf, $openingFigures, $requiredInjection);

        return ['pl' => $pl, 'cf' => $cf, 'bs' => $bs, 'required_equity_injection' => $requiredInjection];
    }

    private function buildProfitAndLoss(Plan $plan, array $in): array
    {
        $months = $plan->totalMonths();
        $taxRate = bcdiv((string) $plan->corporate_tax_rate, '100', 6);
        $pl = [];

        $ebtByMonth = [];
        for ($m = 0; $m < $months; $m++) {
            $revenue = $in['revenue']['revenue_by_month'][$m];
            $cogs = bcadd($in['cogs']['cogs_by_month'][$m], $in['manpower']['cogs_labor_by_month'][$m], 2);
            $grossProfit = bcsub($revenue, $cogs, 2);
            $opex = bcadd($in['expenses']['total_by_month'][$m], $in['manpower']['opex_by_month'][$m], 2);
            $ebitda = bcsub($grossProfit, $opex, 2);
            $depreciation = $in['fixedAssets']['depreciation_by_month'][$m];
            $ebit = bcsub($ebitda, $depreciation, 2);

            $ebtByMonth[$m] = [
                'revenue' => $revenue, 'cogs' => $cogs, 'grossProfit' => $grossProfit,
                'opex' => $opex, 'ebitda' => $ebitda, 'depreciation' => $depreciation, 'ebit' => $ebit,
            ];
        }

        for ($m = 0; $m < $months; $m++) {
            $e = $ebtByMonth[$m];
            $tax = '0.00';

            if (($m + 1) % 12 === 0) {
                $yearStart = $m - 11;
                $annualEbt = '0.00';
                for ($y = $yearStart; $y <= $m; $y++) {
                    $annualEbt = bcadd($annualEbt, $ebtByMonth[$y]['ebit'], 2);
                }
                $tax = bccomp($annualEbt, '0', 2) > 0 ? bcmul($annualEbt, $taxRate, 2) : '0.00';
            }

            $netProfit = bcsub($e['ebit'], $tax, 2);

            $pl[$m] = array_merge($e, [
                'tax' => $tax,
                'netProfit' => $netProfit,
                'grossMarginPct' => $this->pct($e['grossProfit'], $e['revenue']),
                'ebitdaMarginPct' => $this->pct($e['ebitda'], $e['revenue']),
                'netMarginPct' => $this->pct($netProfit, $e['revenue']),
            ]);
        }

        return $pl;
    }

    private function buildCashFlow(Plan $plan, array $in, array $pl, array $openingFigures): array
    {
        $months = $plan->totalMonths();
        $openingCash = $openingFigures['cash_and_bank'] ?? '0.00';

        $net = [];
        for ($m = 0; $m < $months; $m++) {
            $cashIn = $in['revenue']['receipts_by_month'][$m];
            $cashOut = bcadd(
                bcadd($in['cogs']['cash_by_month'][$m], $in['manpower']['cost_by_month'][$m], 2),
                bcadd($in['expenses']['cash_by_month'][$m], bcadd($in['fixedAssets']['capex_cash_by_month'][$m], $pl[$m]['tax'], 2), 2),
                2
            );
            $net[$m] = bcsub($cashIn, $cashOut, 2);
        }

        // Pass 1 — cumulative cash with no injection, find the lowest point.
        $cumulative = [];
        $running = $openingCash;
        $lowest = $openingCash;
        for ($m = 0; $m < $months; $m++) {
            $running = bcadd($running, $net[$m], 2);
            $cumulative[$m] = $running;
            if (bccomp($running, $lowest, 2) < 0) {
                $lowest = $running;
            }
        }

        $requiredInjection = bccomp($lowest, '0', 2) < 0 ? bcmul($lowest, '-1', 2) : '0.00';

        // Pass 2 — adding a constant at month 0 shifts every cumulative
        // figure by the same amount, which is exactly what re-running
        // with the injection folded into month 0 would produce.
        $cf = [];
        for ($m = 0; $m < $months; $m++) {
            $cf[$m] = [
                'net_cash_flow' => $net[$m],
                'cash_balance'  => bcadd($cumulative[$m], $requiredInjection, 2),
                'capex_paid'    => $in['fixedAssets']['capex_cash_by_month'][$m],
            ];
        }

        return [$cf, $requiredInjection];
    }

    /**
     * PLN-002 fix (2026-09) — the projected balance sheet now carries
     * the WHOLE opening balance sheet, not just its cash line.
     *
     * It used to take exactly two figures from the company's real
     * opening position — cash and total equity — and drop the rest.
     * openingFigures() computes accounts_receivable, inventory and
     * accounts_payable too; nothing read them. So every projected
     * month was out by the same constant: the opening assets and
     * liabilities that were never carried across. It showed from
     * month 0, before the plan had done anything at all, which is
     * what identified it as an opening-balance problem rather than a
     * flaw in the monthly arithmetic.
     *
     * That constant fed straight into the DCF and the multiple, since
     * both are computed off these statements.
     *
     * Carried here as four figures:
     *
     *   opening AR        added to the plan's own AR movement
     *   opening inventory held flat (Phase 1 models no inventory
     *                     movement — see the class header)
     *   opening AP        the Liabilities line, held flat for the
     *                     same reason
     *   other opening net  everything else on the opening balance
     *                     sheet the plan does not model — fixed
     *                     assets already owned, other receivables,
     *                     loans, accruals — as ONE figure, derived so
     *                     the opening position closes exactly:
     *
     *       otherNet = equity - (cash + AR + inventory - AP)
     *
     * This is a plug by construction, and deliberately so: inventing
     * a projection for balances the plan has no model for would be
     * guessing, while dropping them silently is what produced the
     * defect. Holding them flat and naming them states the assumption
     * instead of hiding it.
     *
     * The monthly tie-out the class already achieved is untouched —
     * every figure added below is a constant, added to both sides.
     */
    private function buildBalanceSheet(Plan $plan, array $in, array $pl, array $cf, array $openingFigures, string $requiredInjection): array
    {
        $months = $plan->totalMonths();
        $openingEquity    = $openingFigures['total_equity'] ?? '0.00';
        $openingCash      = $openingFigures['cash_and_bank'] ?? '0.00';
        $openingAr        = $openingFigures['accounts_receivable'] ?? '0.00';
        $openingInventory = $openingFigures['inventory'] ?? '0.00';
        $openingAp        = $openingFigures['accounts_payable'] ?? '0.00';

        $openingModelledNet = bcsub(
            bcadd(bcadd($openingCash, $openingAr, 2), $openingInventory, 2),
            $openingAp,
            2
        );
        $openingOtherNet = bcsub($openingEquity, $openingModelledNet, 2);

        $bs = [];

        $cumulativeNetProfit = '0.00';
        $prepaidExpense = '0.00';

        for ($m = 0; $m < $months; $m++) {
            $cumulativeNetProfit = bcadd($cumulativeNetProfit, $pl[$m]['netProfit'], 2);

            // Prepaid Expenses — running total of (cash paid − P&L
            // expensed) for expense lines. See class header: this is
            // the one accrual line kept, not simplified away, because
            // it's what makes the tie-out hold exactly.
            $expenseGap = bcsub($in['expenses']['cash_by_month'][$m], $in['expenses']['total_by_month'][$m], 2);
            $prepaidExpense = bcadd($prepaidExpense, $expenseGap, 2);

            $cash = $cf[$m]['cash_balance'];
            $ar   = bcadd($openingAr, $in['revenue']['ar_balance_by_month'][$m], 2);
            $nfa  = $in['fixedAssets']['net_book_value_by_month'][$m];

            $totalAssets = bcadd(
                bcadd(bcadd($cash, $ar, 2), bcadd($nfa, $prepaidExpense, 2), 2),
                bcadd($openingInventory, $openingOtherNet, 2),
                2
            );
            $totalLiabilities = $openingAp; // Phase 1 models no AP movement — see class header
            $totalEquity = bcadd(bcadd($openingEquity, $requiredInjection, 2), $cumulativeNetProfit, 2);

            $difference = bcsub($totalAssets, bcadd($totalLiabilities, $totalEquity, 2), 2);

            $bs[$m] = [
                'cash' => $cash, 'accounts_receivable' => $ar, 'net_fixed_assets' => $nfa,
                'prepaid_expenses' => $prepaidExpense,
                'inventory' => $openingInventory,
                'other_net_assets' => $openingOtherNet,
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities, 'total_equity' => $totalEquity,
                'is_balanced' => bccomp($difference, '0', 2) === 0,
                'difference' => $difference,
            ];
        }

        return $bs;
    }

    private function pct(string $numerator, string $denominator): string
    {
        if (bccomp($denominator, '0', 2) === 0) {
            return '0.00';
        }

        return bcmul(bcdiv($numerator, $denominator, 6), '100', 2);
    }
}
