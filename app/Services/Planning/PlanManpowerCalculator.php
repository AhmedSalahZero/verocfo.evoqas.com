<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanManpowerCalculator
//
//  Step 3 of the calculation engine. Confirmed title-level design
//  (spec Section 3.2): headcount(month) = existing_headcount + every
//  planned hire whose plan_year has started by that month (hires
//  accumulate and stay — nobody modeled as leaving). Cost(month) =
//  headcount(month) × avg salary, grown by the plan's ONE
//  manpower_annual_increase_pct, compounding per plan year — applies
//  to existing staff and new hires alike (confirmed design).
//
//  Category routing mirrors the real COA mapping confirmed in
//  EmployeePayrollProfile (spec Section 3.2):
//    sales / marketing / general_admin → Operating Expenses (521/531/541-family)
//    cost_of_service / manufacturing   → Cost of Goods Sold (513-family) —
//      both post like a direct cost, not SG&A, matching the real
//      payroll module's own confirmed design (manufacturing labor
//      folds into COGS, never a direct SG&A salary line).
//
//  bcmath throughout (Rule 4). Read-only.
//
//  @layer    Service
//  @depends  Plan, PlanManpowerTitle
// ══════════════════════════════════════════════════════════════════

use App\Models\Plan;
use App\Models\PlanManpowerTitle;

class PlanManpowerCalculator
{
    private const COGS_CATEGORIES = [
        PlanManpowerTitle::CATEGORY_COST_OF_SERVICE,
        PlanManpowerTitle::CATEGORY_MANUFACTURING,
    ];

    /**
     * Returns:
     *  [
     *    'cost_by_month'       => [monthIndex => 'total payroll cost, every title'],
     *    'cogs_labor_by_month' => [monthIndex => 'cost_of_service + manufacturing titles only'],
     *    'opex_by_month'       => [monthIndex => 'sales + marketing + general_admin titles only'],
     *    'by_category'         => ['sales' => [...], 'marketing' => [...], ...],
     *  ]
     */
    public function calculate(Plan $plan): array
    {
        $months = $plan->totalMonths();
        $zero   = array_fill(0, $months, '0.00');
        $increaseFactor = bcadd('1', bcdiv((string) $plan->manpower_annual_increase_pct, '100', 6), 6);

        $costByMonth      = $zero;
        $cogsLaborByMonth = $zero;
        $opexByMonth      = $zero;
        $byCategory       = [
            PlanManpowerTitle::CATEGORY_SALES           => $zero,
            PlanManpowerTitle::CATEGORY_MARKETING       => $zero,
            PlanManpowerTitle::CATEGORY_GENERAL_ADMIN   => $zero,
            PlanManpowerTitle::CATEGORY_COST_OF_SERVICE => $zero,
            PlanManpowerTitle::CATEGORY_MANUFACTURING   => $zero,
        ];

        foreach ($plan->manpowerTitles as $title) {
            $hiringByYear = $title->hiringLines->pluck('new_hires_count', 'plan_year');

            for ($m = 0; $m < $months; $m++) {
                $planYear = intdiv($m, 12) + 1;

                $cumulativeNewHires = 0;
                foreach ($hiringByYear as $year => $count) {
                    if ($year <= $planYear) {
                        $cumulativeNewHires += (int) $count;
                    }
                }

                $headcount = $title->existing_headcount + $cumulativeNewHires;
                if ($headcount <= 0) {
                    continue;
                }

                $salary = (string) $title->avg_current_salary;
                $years = intdiv($m, 12);
                for ($y = 0; $y < $years; $y++) {
                    $salary = bcmul($salary, $increaseFactor, 4);
                }

                $cost = bcmul($salary, (string) $headcount, 2);

                $costByMonth[$m] = bcadd($costByMonth[$m], $cost, 2);
                $byCategory[$title->expense_category][$m] = bcadd($byCategory[$title->expense_category][$m], $cost, 2);

                if (in_array($title->expense_category, self::COGS_CATEGORIES, true)) {
                    $cogsLaborByMonth[$m] = bcadd($cogsLaborByMonth[$m], $cost, 2);
                } else {
                    $opexByMonth[$m] = bcadd($opexByMonth[$m], $cost, 2);
                }
            }
        }

        return [
            'cost_by_month'       => $costByMonth,
            'cogs_labor_by_month' => $cogsLaborByMonth,
            'opex_by_month'       => $opexByMonth,
            'by_category'         => $byCategory,
        ];
    }
}
