<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanExpenseCalculator
//
//  Step 4 of the calculation engine — bcmath port of CFO Tools'
//  calcExpenses.js, same three input shapes, unchanged logic:
//
//    pct_revenue      → amount% of that month's total revenue,
//                        grown by annual_increase_pct per plan year
//    fixed_recurring  → a flat monthly amount, same annual growth
//    one_time         → the total amount, amortized evenly (P&L)
//                        over amortization_months starting at
//                        start_month — but paid in cash IN FULL at
//                        start_month (SIMPLIFICATION, flagged: no
//                        payment-policy field on plan_expense_lines,
//                        matching the same Phase 1 "no AP lag"
//                        posture as PlanCogsCalculator).
//
//  category maps directly to the real COA Level 2 groups (52/53/54/55
//  — spec Section 3.2 / PlanExpenseLine::CATEGORY_COA_GROUP).
//
//  bcmath throughout (Rule 4). Read-only.
//
//  @layer    Service
//  @depends  Plan, PlanExpenseLine
// ══════════════════════════════════════════════════════════════════

use App\Models\Plan;
use App\Models\PlanExpenseLine;

class PlanExpenseCalculator
{
    /**
     * $revenueByMonth = [monthIndex => 'total revenue'] from
     * PlanRevenueCalculator.
     *
     * Returns:
     *  [
     *    'total_by_month'      => [monthIndex => 'all categories, P&L basis'],
     *    'cash_by_month'       => [monthIndex => 'cash paid — differs from total only for one_time lines'],
     *    'by_category'         => ['sales'=>[...],'marketing'=>[...],'general_admin'=>[...],'finance'=>[...]],
     *  ]
     */
    public function calculate(Plan $plan, array $revenueByMonth): array
    {
        $months = $plan->totalMonths();
        $zero   = array_fill(0, $months, '0.00');

        $totalByMonth = $zero;
        $cashByMonth  = $zero;
        $byCategory   = [
            PlanExpenseLine::CATEGORY_SALES         => $zero,
            PlanExpenseLine::CATEGORY_MARKETING     => $zero,
            PlanExpenseLine::CATEGORY_GENERAL_ADMIN => $zero,
            PlanExpenseLine::CATEGORY_FINANCE       => $zero,
        ];

        foreach ($plan->expenseLines as $line) {
            $endMonth = $line->end_month ?? ($months - 1);
            $growth = bcadd('1', bcdiv((string) $line->annual_increase_pct, '100', 6), 6);

            match ($line->expense_type) {
                PlanExpenseLine::TYPE_PCT_REVENUE     => $this->pctRevenue($line, $revenueByMonth, $growth, $line->start_month, $endMonth, $totalByMonth, $cashByMonth, $byCategory),
                PlanExpenseLine::TYPE_FIXED_RECURRING => $this->fixedRecurring($line, $growth, $line->start_month, $endMonth, $totalByMonth, $cashByMonth, $byCategory),
                PlanExpenseLine::TYPE_ONE_TIME        => $this->oneTime($line, $months, $totalByMonth, $cashByMonth, $byCategory),
                default => null,
            };
        }

        return [
            'total_by_month' => $totalByMonth,
            'cash_by_month'  => $cashByMonth,
            'by_category'    => $byCategory,
        ];
    }

    private function pctRevenue(PlanExpenseLine $line, array $revenueByMonth, string $growth, int $start, int $end, array &$total, array &$cash, array &$byCategory): void
    {
        $pct = bcdiv((string) $line->amount, '100', 6);

        for ($m = $start; $m <= $end && isset($revenueByMonth[$m]); $m++) {
            $factor = $this->compound($growth, intdiv($m, 12));
            $cost = bcmul(bcmul($revenueByMonth[$m], $pct, 4), $factor, 2);
            $this->accumulate($m, $cost, $line->category, $total, $cash, $byCategory);
        }
    }

    private function fixedRecurring(PlanExpenseLine $line, string $growth, int $start, int $end, array &$total, array &$cash, array &$byCategory): void
    {
        for ($m = $start; $m <= $end; $m++) {
            $factor = $this->compound($growth, intdiv($m, 12));
            $cost = bcmul((string) $line->amount, $factor, 2);
            $this->accumulate($m, $cost, $line->category, $total, $cash, $byCategory);
        }
    }

    private function oneTime(PlanExpenseLine $line, int $months, array &$total, array &$cash, array &$byCategory): void
    {
        $amortMonths = max(1, (int) $line->amortization_months);
        $monthly = bcdiv((string) $line->amount, (string) $amortMonths, 2);

        for ($m = $line->start_month; $m < $line->start_month + $amortMonths && $m < $months; $m++) {
            $total[$m] = bcadd($total[$m], $monthly, 2);
            $byCategory[$line->category][$m] = bcadd($byCategory[$line->category][$m], $monthly, 2);
        }

        if ($line->start_month < $months) {
            $cash[$line->start_month] = bcadd($cash[$line->start_month], (string) $line->amount, 2);
        }
    }

    private function accumulate(int $m, string $cost, string $category, array &$total, array &$cash, array &$byCategory): void
    {
        $total[$m] = bcadd($total[$m], $cost, 2);
        $cash[$m]  = bcadd($cash[$m], $cost, 2);
        $byCategory[$category][$m] = bcadd($byCategory[$category][$m], $cost, 2);
    }

    private function compound(string $factor, int $years): string
    {
        $result = '1';
        for ($y = 0; $y < $years; $y++) {
            $result = bcmul($result, $factor, 6);
        }

        return $result;
    }
}
