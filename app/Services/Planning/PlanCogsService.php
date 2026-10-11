<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanCogsService
//
//  COGS step — updates the one PlanCogsAssumption row seeded per
//  product by PlanService::addProduct(). Which fields the Form
//  Request actually requires depends on the product's nature
//  (manufacturing/trading/service — spec Section 3.2); this service
//  just persists whatever the request already validated, it doesn't
//  re-derive nature-specific requirements itself.
//
//  @layer    Service
//  @depends  PlanProduct, PlanCogsAssumption, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\PlanProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlanCogsService
{
    public function saveAssumption(PlanProduct $planProduct, array $data, User $actor): PlanProduct
    {
        return DB::transaction(function () use ($planProduct, $data, $actor) {
            $assumption = $planProduct->cogsAssumption;
            $before = $assumption?->toArray();

            $assumption->update([
                'unit_material_cost'          => $data['unit_material_cost'] ?? 0,
                'unit_labor_cost'             => $data['unit_labor_cost'] ?? 0,
                'overhead_pct_of_labor'       => $data['overhead_pct_of_labor'] ?? 0,
                'unit_purchase_cost'          => $data['unit_purchase_cost'] ?? 0,
                'inventory_coverage_days'     => $data['inventory_coverage_days'] ?? 30,
                'direct_cost_pct'             => $data['direct_cost_pct'] ?? 0,
                'annual_cost_increase_pct'    => $data['annual_cost_increase_pct'] ?? 0,
                'beginning_inventory_units'   => $data['beginning_inventory_units'] ?? 0,
                'beginning_inventory_value'   => $data['beginning_inventory_value'] ?? 0,
            ]);

            AuditLog::record($actor, 'plan.cogs_assumption_saved', $assumption, $before, $assumption->fresh()->toArray());

            return $planProduct->fresh(['cogsAssumption']);
        });
    }
}
