<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanFixedAssetCalculator
//
//  Step 5 of the calculation engine. Confirmed simple design (spec
//  Section 3.4) — straight-line only, matching FixedAsset::
//  monthlyDepreciationAmount()'s exact formula:
//
//    Existing asset (fixed_asset_id set) → depreciation starts at the
//    plan's own month 0 (it's already in service), using the
//    SNAPSHOT's remaining depreciable base (cost − residual −
//    accumulated_depreciation_snapshot), not the full original cost —
//    only what's actually left to depreciate is projected forward.
//    Opening net book value = netBookValueAtPlanStart(). No CAPEX
//    cash flow — it was already bought before the plan started.
//
//    Planned asset (fixed_asset_id null) → CAPEX cash flow posts in
//    full at purchase_month; depreciation starts the month AFTER
//    purchase_month (same convention as the real FixedAsset's own
//    default), using the full cost − residual as the depreciable
//    base. Opening net book value is 0 until purchase_month, then
//    jumps to full cost_basis.
//
//  Every asset's last depreciation period absorbs any rounding
//  residue so its remaining depreciable base hits exactly 0.00 —
//  same principle Leasing's wear-down schedule and the real
//  FixedAsset model already use.
//
//  bcmath throughout (Rule 4). Read-only.
//
//  @layer    Service
//  @depends  Plan, PlanFixedAsset
// ══════════════════════════════════════════════════════════════════

use App\Models\Plan;
use App\Models\PlanFixedAsset;

class PlanFixedAssetCalculator
{
    /**
     * Returns:
     *  [
     *    'depreciation_by_month'   => [monthIndex => 'total D&A, all assets'],
     *    'capex_cash_by_month'     => [monthIndex => 'cash out for planned purchases'],
     *    'net_book_value_by_month' => [monthIndex => 'total NBV across all assets, end of month'],
     *  ]
     */
    public function calculate(Plan $plan): array
    {
        $months = $plan->totalMonths();
        $zero   = array_fill(0, $months, '0.00');

        $depreciationByMonth = $zero;
        $capexCashByMonth    = $zero;
        $nbvByMonth          = $zero;

        foreach ($plan->fixedAssets as $asset) {
            $schedule = $this->assetSchedule($asset, $months);

            foreach ($schedule['depreciation'] as $m => $amount) {
                $depreciationByMonth[$m] = bcadd($depreciationByMonth[$m], $amount, 2);
            }
            foreach ($schedule['nbv'] as $m => $value) {
                $nbvByMonth[$m] = bcadd($nbvByMonth[$m], $value, 2);
            }
            if ($schedule['capex_month'] !== null) {
                $capexCashByMonth[$schedule['capex_month']] = bcadd(
                    $capexCashByMonth[$schedule['capex_month']], (string) $asset->cost_basis, 2
                );
            }
        }

        return [
            'depreciation_by_month'   => $depreciationByMonth,
            'capex_cash_by_month'     => $capexCashByMonth,
            'net_book_value_by_month' => $nbvByMonth,
        ];
    }

    /**
     * Builds one asset's full month-by-month depreciation + NBV
     * trajectory in a single pass, so both outputs are guaranteed
     * consistent with each other (no risk of the two figures drifting
     * apart from being computed twice, differently).
     */
    private function assetSchedule(PlanFixedAsset $asset, int $months): array
    {
        $monthlyAmount = $asset->monthlyDepreciationAmount();
        $depreciation = [];
        $nbv = [];

        if ($asset->isExistingAsset()) {
            $depreciationStart = 0;
            $capexMonth = null;
            $remaining = bcsub($asset->netBookValueAtPlanStart(), (string) $asset->residual_value, 2);
            $runningNbv = $asset->netBookValueAtPlanStart();
        } else {
            $depreciationStart = ($asset->purchase_month ?? 0) + 1;
            $capexMonth = ($asset->purchase_month !== null && $asset->purchase_month < $months) ? $asset->purchase_month : null;
            $remaining = bcsub((string) $asset->cost_basis, (string) $asset->residual_value, 2);
            $runningNbv = '0.00';
        }

        for ($m = 0; $m < $months; $m++) {
            if (! $asset->isExistingAsset() && $m === $asset->purchase_month) {
                $runningNbv = bcadd($runningNbv, (string) $asset->cost_basis, 2);
            }

            $periodDepreciation = '0.00';
            if ($m >= $depreciationStart && bccomp($remaining, '0', 2) > 0) {
                $periodDepreciation = bccomp($remaining, $monthlyAmount, 2) < 0 ? $remaining : $monthlyAmount;
                $remaining = bcsub($remaining, $periodDepreciation, 2);
                $runningNbv = bcsub($runningNbv, $periodDepreciation, 2);
            }

            $depreciation[$m] = $periodDepreciation;
            $nbv[$m] = $runningNbv;
        }

        return ['depreciation' => $depreciation, 'nbv' => $nbv, 'capex_month' => $capexMonth];
    }
}
