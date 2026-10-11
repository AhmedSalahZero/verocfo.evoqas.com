<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanFixedAssetService
//
//  Fixed Assets step — confirmed simple, two-source design (Sept
//  2026, spec Section 3.4). Exactly two actions, no more:
//
//    addExistingAsset() — picks a real, in-service FixedAsset from
//    the company's own register and snapshots its current numbers
//    (cost, residual, useful life, depreciation so far) into a
//    PlanFixedAsset row. The snapshot is deliberate — a plan is a
//    point-in-time projection, not a live view (same principle as
//    PlanProduct for real Products) — but the depreciation FORMULA
//    used to project it forward is identical to the real
//    FixedAsset::monthlyDepreciationAmount(), so the numbers agree
//    with what the real asset actually posts.
//
//    addPlannedAsset() — a future purchase that doesn't exist in the
//    real register yet. No construction-in-progress phase, no
//    equity/debt split, no S-curve billing — deliberately not built,
//    matching the real Fixed Assets module's own Phase 1 scope
//    (straight-line only, FixedAsset::METHOD_STRAIGHT_LINE).
//
//  @layer    Service
//  @depends  Plan, PlanFixedAsset, FixedAsset, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\FixedAsset;
use App\Models\Plan;
use App\Models\PlanFixedAsset;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlanFixedAssetService
{
    /** Real, in-service assets the user can pick from for a plan's company. */
    public function eligibleExistingAssets(int $companyId): \Illuminate\Support\Collection
    {
        return FixedAsset::inService()
            ->where('company_id', $companyId)
            ->orderBy('asset_code')
            ->get(['id', 'asset_code', 'name', 'cost_basis', 'accumulated_depreciation', 'useful_life_months', 'residual_value']);
    }

    public function addExistingAsset(Plan $plan, FixedAsset $fixedAsset, User $actor): PlanFixedAsset
    {
        if ($fixedAsset->company_id !== $plan->company_id) {
            throw new \DomainException('That fixed asset does not belong to this company.');
        }

        return DB::transaction(function () use ($plan, $fixedAsset, $actor) {
            $planAsset = PlanFixedAsset::create([
                'company_id'                          => $plan->company_id,
                'plan_id'                              => $plan->id,
                'fixed_asset_id'                        => $fixedAsset->id,
                'name'                                  => $fixedAsset->name,
                'cost_basis'                            => $fixedAsset->cost_basis,
                'residual_value'                        => $fixedAsset->residual_value,
                'useful_life_months'                    => $fixedAsset->useful_life_months,
                'accumulated_depreciation_snapshot'      => $fixedAsset->accumulated_depreciation,
                'purchase_month'                        => null,
            ]);

            AuditLog::record($actor, 'plan.fixed_asset_added_existing', $planAsset, null, $planAsset->toArray());

            return $planAsset;
        });
    }

    public function addPlannedAsset(Plan $plan, array $data, User $actor): PlanFixedAsset
    {
        return DB::transaction(function () use ($plan, $data, $actor) {
            $planAsset = PlanFixedAsset::create([
                'company_id'           => $plan->company_id,
                'plan_id'              => $plan->id,
                'fixed_asset_id'        => null,
                'name'                  => $data['name'],
                'cost_basis'            => $data['cost_basis'],
                'residual_value'        => $data['residual_value'] ?? 0,
                'useful_life_months'    => $data['useful_life_months'],
                'accumulated_depreciation_snapshot' => 0,
                'purchase_month'        => $data['purchase_month'],
            ]);

            AuditLog::record($actor, 'plan.fixed_asset_added_planned', $planAsset, null, $planAsset->toArray());

            return $planAsset;
        });
    }

    public function removeAsset(PlanFixedAsset $planAsset, User $actor): void
    {
        DB::transaction(function () use ($planAsset, $actor) {
            $before = $planAsset->toArray();
            $planAsset->delete();

            AuditLog::record($actor, 'plan.fixed_asset_removed', $planAsset, $before, null);
        });
    }
}
