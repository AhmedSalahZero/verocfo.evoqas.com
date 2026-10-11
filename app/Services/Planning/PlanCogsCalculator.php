<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanCogsCalculator
//
//  Step 2 of the calculation engine. Deliberately a SIMPLIFIED stand-
//  in for CFO Tools' full BOM/WAC inventory-statement engine — this
//  is Phase 1 scope (see migration 000243's own header, which flags
//  this up front rather than silently building something smaller
//  than CFO Tools without saying so):
//
//    manufacturing → unit cost = material + labor + (labor × overhead
//                    %), grown by annual_cost_increase_pct per plan
//                    year. COGS(month) = unit cost × units sold that
//                    month (from PlanRevenueCalculator's volume). No
//                    WIP/raw-material inventory tracking — sold this
//                    month is assumed produced this month.
//    trading       → unit cost = purchase cost, same annual growth.
//                    COGS(month) = unit cost × units sold. Ending
//                    inventory units ≈ (coverage days ÷ 30) × next
//                    month's volume (an approximation, not a running
//                    weighted-average ledger) — used only to size the
//                    Inventory line on the Balance Sheet.
//    service       → COGS(month) = revenue(month) × direct_cost_pct.
//
//  SIMPLIFICATION FLAGGED, NOT HIDDEN: no Accounts Payable lag is
//  modeled anywhere in this calculator — every COGS cash cost is
//  assumed paid the same month it's incurred. A later phase can add
//  a payment-policy field to plan_cogs_assumptions (mirroring
//  plan_collection_tranches) if AP timing turns out to matter for
//  planning accuracy; not built now because it wasn't asked for and
//  would meaningfully grow this table's shape.
//
//  Trading's inventory_by_month is ALSO memo-only, same posture as
//  PlanRevenueCalculator's VAT/WHT estimates — it's a coverage-days
//  estimate, not derived from an actual purchases-minus-sold flow
//  tied to cash, so PlanFinancialStatementBuilder does not include it
//  in the Balance Sheet's Total Assets (doing so would break the
//  Assets = Liabilities + Equity tie-out, since there's no matching
//  cash outflow or payable behind it in this simplified model).
//
//  bcmath throughout (Rule 4). Read-only.
//
//  @layer    Service
//  @depends  Plan, PlanProduct, PlanCogsAssumption
// ══════════════════════════════════════════════════════════════════

use App\Models\Plan;
use App\Models\PlanCogsAssumption;
use App\Models\PlanProduct;

class PlanCogsCalculator
{
    /**
     * $volumeByProduct = [planProductId => [monthIndex => 'units sold']],
     * from PlanRevenueCalculator's by_product output.
     *
     * Returns:
     *  [
     *    'cogs_by_month'      => [monthIndex => 'total COGS, all products'],
     *    'inventory_by_month' => [monthIndex => 'ending inventory value, trading only'],
     *    'cash_by_month'      => [monthIndex => 'cash paid for COGS (== cogs_by_month, Phase 1 — see header)'],
     *  ]
     */
    public function calculate(Plan $plan, array $volumeByProduct): array
    {
        $months = $plan->totalMonths();
        $zero   = array_fill(0, $months, '0.00');

        $cogsByMonth      = $zero;
        $inventoryByMonth = $zero;

        foreach ($plan->products as $product) {
            $assumption = $product->cogsAssumption;
            if (! $assumption) {
                continue;
            }

            $volumes = $volumeByProduct[$product->id]['volume_by_month'] ?? $zero;

            match ($product->nature) {
                PlanProduct::NATURE_MANUFACTURING => $this->manufacturing($assumption, $volumes, $months, $cogsByMonth),
                PlanProduct::NATURE_TRADING       => $this->trading($assumption, $volumes, $months, $cogsByMonth, $inventoryByMonth),
                PlanProduct::NATURE_SERVICE       => $this->service($assumption, $volumeByProduct[$product->id]['revenue_by_month'] ?? $zero, $months, $cogsByMonth),
                default => null,
            };
        }

        return [
            'cogs_by_month'      => $cogsByMonth,
            'inventory_by_month' => $inventoryByMonth,
            'cash_by_month'      => $cogsByMonth, // Phase 1 — no AP lag, see header
        ];
    }

    private function unitCostForMonth(string $baseCost, string $annualIncreasePct, int $month): string
    {
        $years = intdiv($month, 12);
        $growth = bcdiv($annualIncreasePct, '100', 6);
        $factor = bcadd('1', $growth, 6);

        $cost = $baseCost;
        for ($y = 0; $y < $years; $y++) {
            $cost = bcmul($cost, $factor, 4);
        }

        return $cost;
    }

    private function manufacturing(PlanCogsAssumption $a, array $volumes, int $months, array &$cogsByMonth): void
    {
        $overheadFactor = bcadd('1', bcdiv((string) $a->overhead_pct_of_labor, '100', 6), 6);
        $baseUnitCost = bcadd(
            (string) $a->unit_material_cost,
            bcmul((string) $a->unit_labor_cost, $overheadFactor, 4),
            4
        );

        for ($m = 0; $m < $months; $m++) {
            $vol = $volumes[$m] ?? '0.00';
            if (bccomp($vol, '0', 4) === 0) {
                continue;
            }
            $unitCost = $this->unitCostForMonth($baseUnitCost, (string) $a->annual_cost_increase_pct, $m);
            $cogsByMonth[$m] = bcadd($cogsByMonth[$m], bcmul($unitCost, $vol, 2), 2);
        }
    }

    private function trading(PlanCogsAssumption $a, array $volumes, int $months, array &$cogsByMonth, array &$inventoryByMonth): void
    {
        $coverageMonths = bcdiv((string) $a->inventory_coverage_days, '30', 6);

        for ($m = 0; $m < $months; $m++) {
            $vol = $volumes[$m] ?? '0.00';
            $unitCost = $this->unitCostForMonth((string) $a->unit_purchase_cost, (string) $a->annual_cost_increase_pct, $m);

            if (bccomp($vol, '0', 4) > 0) {
                $cogsByMonth[$m] = bcadd($cogsByMonth[$m], bcmul($unitCost, $vol, 2), 2);
            }

            $nextVol = $volumes[$m + 1] ?? ($volumes[$m] ?? '0.00');
            $endingUnits = bcmul($nextVol, $coverageMonths, 4);
            $inventoryByMonth[$m] = bcadd($inventoryByMonth[$m], bcmul($endingUnits, $unitCost, 2), 2);
        }
    }

    private function service(PlanCogsAssumption $a, array $revenueByMonth, int $months, array &$cogsByMonth): void
    {
        $pct = bcdiv((string) $a->direct_cost_pct, '100', 6);

        for ($m = 0; $m < $months; $m++) {
            $rev = $revenueByMonth[$m] ?? '0.00';
            if (bccomp($rev, '0', 2) === 0) {
                continue;
            }
            $cogsByMonth[$m] = bcadd($cogsByMonth[$m], bcmul($rev, $pct, 2), 2);
        }
    }
}
