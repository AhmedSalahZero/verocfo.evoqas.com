<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanCalculationService
//
//  The master entry point for the calculation engine — mirrors CFO
//  Tools' runStudy() orchestration, in bcmath PHP over Vero CFO's
//  relational plan tables instead of a client-side JS engine reading
//  JSON blobs (spec Section 4.1's confirmed rebuild). Runs all seven
//  steps in order:
//
//    1. PlanRevenueCalculator    — revenue, AR, collections
//    2. PlanCogsCalculator       — COGS by nature
//    3. PlanManpowerCalculator   — payroll, COGS-routed vs OpEx-routed
//    4. PlanExpenseCalculator    — the three OpEx shapes
//    5. PlanFixedAssetCalculator — depreciation + planned CAPEX
//    6. PlanFinancialStatementBuilder — P&L / Cash Flow / Balance Sheet
//    7. PlanValuationService     — DCF + Multiplier (Business Plan only)
//
//  Opening figures are sourced live from PlanOpeningBalanceService
//  when opening_balance_source = 'auto' (spec Section 3.3) — never
//  re-entered. For 'manual'/'none', zeros are used (a genuinely new
//  company starting from nothing).
//
//  CACHING — Rule 8.4 ("Analytics services use Cache::remember()")
//  applies directly: this is a real computation, not a database read,
//  and running it on every page view for a 10-year Business Plan
//  (120 months × several products) is exactly the kind of cost that
//  rule exists to avoid. Cached per plan, invalidated by touching
//  updated_at — any wizard-step write goes through a service that
//  calls Plan's own touch() indirectly via its updated_at column, or
//  the plan's own update(); a simpler and more robust invalidation
//  key is used here instead: company_id + plan_id + the plan's own
//  updated_at timestamp, so ANY change to the plan model itself
//  (Setup edits) busts the cache, and every wizard-step write also
//  touches the parent plan's updated_at (see calculate()'s own
//  cache-key construction) rather than relying on cascading touch()
//  through six other tables.
//
//  bcmath throughout (Rule 4). Read-only — never writes to the DB.
//
//  @layer    Service
//  @depends  Plan, all Services\Planning\Plan*Calculator/Builder,
//            PlanOpeningBalanceService, PlanValuationService
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Models\Plan;
use Illuminate\Support\Facades\Cache;

class PlanCalculationService
{
    public function __construct(
        private PlanRevenueCalculator $revenueCalculator,
        private PlanCogsCalculator $cogsCalculator,
        private PlanManpowerCalculator $manpowerCalculator,
        private PlanExpenseCalculator $expenseCalculator,
        private PlanFixedAssetCalculator $fixedAssetCalculator,
        private PlanFinancialStatementBuilder $statementBuilder,
        private PlanOpeningBalanceService $openingBalanceService,
        private PlanValuationService $valuationService,
    ) {
    }

    /**
     * Returns the full result set:
     *  [
     *    'pl' => [...monthly...], 'cf' => [...], 'bs' => [...],
     *    'required_equity_injection' => '...',
     *    'annual' => ['pl' => [...], 'cf' => [...], 'bs' => [...]],  // year-aggregated, for display
     *    'balance_check' => ['all_balanced' => bool, 'unbalanced_months' => [...], ...],
     *    'valuation' => null | ['dcf' => [...], 'multiplier_value' => '...'],  // Business Plan only
     *    'revenue_by_product' => [...],  // for a per-product breakdown view
     *  ]
     */
    public function calculate(Plan $plan, bool $bypassCache = false): array
    {
        $cacheKey = "plan_calculation:{$plan->company_id}:{$plan->id}:{$plan->updated_at->timestamp}";

        if ($bypassCache) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, now()->addHour(), function () use ($plan) {
            return $this->run($plan);
        });
    }

    private function run(Plan $plan): array
    {
        $plan->loadMissing([
            'products.salesLines', 'products.collectionTranches', 'products.cogsAssumption',
            'manpowerTitles.hiringLines', 'expenseLines', 'fixedAssets',
        ]);

        $revenue = $this->revenueCalculator->calculate($plan);
        $manpower = $this->manpowerCalculator->calculate($plan);
        $cogs = $this->cogsCalculator->calculate($plan, $revenue['by_product']);
        $expenses = $this->expenseCalculator->calculate($plan, $revenue['revenue_by_month']);
        $fixedAssets = $this->fixedAssetCalculator->calculate($plan);

        $openingFigures = $this->resolveOpeningFigures($plan);

        $statements = $this->statementBuilder->build($plan, [
            'revenue' => $revenue, 'cogs' => $cogs, 'manpower' => $manpower,
            'expenses' => $expenses, 'fixedAssets' => $fixedAssets,
        ], $openingFigures);

        $annual = $this->aggregateAnnual($plan, $statements);

        $valuation = null;
        if ($plan->isBusinessPlan()) {
            $valuation = [
                'dcf' => $this->valuationService->dcf($plan, $statements['pl'], $statements['cf'], $statements['required_equity_injection']),
                'multiplier_value' => $this->valuationService->multiplierValuation($plan, $statements['pl']),
                'valuation_basis' => $plan->valuation_basis,
                'valuation_multiplier' => (string) $plan->valuation_multiplier,
            ];
        }

        return array_merge($statements, [
            'annual' => $annual,
            // PLN-002 fix (2026-09) — the tie-out verdict for EVERY
            // month, not just the twelfth of each year.
            //
            // aggregateAnnual() takes each year's LAST month as that
            // year's balance sheet, so annual.bs[y].is_balanced is one
            // month's verdict standing in for twelve. The screens read
            // only that, which is how a plan whose every month was out
            // by a constant could still display a green "Balanced"
            // badge. This is what the screens read now.
            'balance_check' => $this->balanceCheck($statements['bs']),
            'valuation' => $valuation,
            'revenue_by_product' => $revenue['by_product'],
            'vat_estimate_by_month' => $revenue['vat_estimate_by_month'],
            'wht_estimate_by_month' => $revenue['wht_estimate_by_month'],
            'inventory_estimate_by_month' => $cogs['inventory_by_month'],
        ]);
    }

    private function resolveOpeningFigures(Plan $plan): array
    {
        if ($plan->opening_balance_source !== Plan::OB_SOURCE_AUTO) {
            return ['cash_and_bank' => '0.00', 'accounts_receivable' => '0.00', 'inventory' => '0.00', 'accounts_payable' => '0.00', 'total_equity' => '0.00'];
        }

        return $this->openingBalanceService->openingFigures($plan);
    }

    /**
     * Every month's tie-out, summarised for the screens.
     *
     * 'all_balanced' is false if ANY month fails, and the failing
     * months are named with their own difference so the number is
     * actionable rather than a bare warning.
     */
    private function balanceCheck(array $bs): array
    {
        $unbalanced = [];

        foreach ($bs as $month => $row) {
            if (! ($row['is_balanced'] ?? false)) {
                $unbalanced[] = [
                    'month'      => $month,
                    'difference' => (string) ($row['difference'] ?? '0.00'),
                ];
            }
        }

        return [
            'all_balanced'      => $unbalanced === [],
            'months_checked'    => count($bs),
            'unbalanced_months' => $unbalanced,
            'worst_difference'  => $unbalanced === []
                ? '0.00'
                : array_reduce(
                    $unbalanced,
                    fn (string $worst, $u) => bccomp($d = ltrim(Money::round($u['difference']), '-'), $worst, 2) > 0 ? $d : $worst,
                    '0.00'
                ),
        ];
    }

    /** Sums each month's figures into plan-year totals, for a less noisy display than 120 months at once. */
    private function aggregateAnnual(Plan $plan, array $statements): array
    {
        $years = $plan->duration_years;
        $pl = []; $cf = []; $bs = [];

        for ($y = 0; $y < $years; $y++) {
            $months = range($y * 12, $y * 12 + 11);

            $pl[$y] = $this->sumRows($statements['pl'], $months, ['revenue', 'cogs', 'grossProfit', 'opex', 'ebitda', 'depreciation', 'ebit', 'tax', 'netProfit']);
            $cf[$y] = $this->sumRows($statements['cf'], $months, ['net_cash_flow', 'capex_paid']);
            $cf[$y]['cash_balance_end'] = $statements['cf'][$months[count($months) - 1]]['cash_balance'] ?? '0.00';

            $lastMonth = $months[count($months) - 1];
            $bs[$y] = $statements['bs'][$lastMonth] ?? null;
        }

        return ['pl' => $pl, 'cf' => $cf, 'bs' => $bs];
    }

    private function sumRows(array $monthlyRows, array $months, array $fields): array
    {
        $sums = array_fill_keys($fields, '0.00');

        foreach ($months as $m) {
            if (! isset($monthlyRows[$m])) {
                continue;
            }
            foreach ($fields as $field) {
                $sums[$field] = bcadd($sums[$field], $monthlyRows[$m][$field] ?? '0.00', 2);
            }
        }

        return $sums;
    }
}
