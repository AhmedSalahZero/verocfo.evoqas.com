<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PlanCogsAssumption
//
//  One row per plan_product — the COGS assumption, shape depends on
//  the product's nature. See migration 000243 header.
//
//  @layer    Model
//  @depends  BelongsToCompany, PlanProduct
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanCogsAssumption extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'plan_product_id',
        'unit_material_cost',
        'unit_labor_cost',
        'overhead_pct_of_labor',
        'unit_purchase_cost',
        'inventory_coverage_days',
        'direct_cost_pct',
        'annual_cost_increase_pct',
        'beginning_inventory_units',
        'beginning_inventory_value',
    ];

    protected $casts = [
        'unit_material_cost'        => 'decimal:4',
        'unit_labor_cost'           => 'decimal:4',
        'overhead_pct_of_labor'     => 'decimal:4',
        'unit_purchase_cost'        => 'decimal:4',
        'direct_cost_pct'           => 'decimal:4',
        'annual_cost_increase_pct'  => 'decimal:4',
        'beginning_inventory_units' => 'decimal:4',
        'beginning_inventory_value' => 'decimal:2',
    ];

    public function planProduct(): BelongsTo
    {
        return $this->belongsTo(PlanProduct::class);
    }
}
