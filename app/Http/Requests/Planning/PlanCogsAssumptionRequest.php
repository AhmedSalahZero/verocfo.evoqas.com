<?php

namespace App\Http\Requests\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanCogsAssumptionRequest
//
//  All fields are nullable/optional here on purpose — which ones
//  matter depends on the product's nature (manufacturing/trading/
//  service), and this one row carries all three shapes (spec Section
//  3.2 / migration 000243 header). The Vue wizard step only shows and
//  submits the fields relevant to that product's nature; the ones
//  that don't apply are simply left at their 0 default.
//
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class PlanCogsAssumptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'unit_material_cost'         => ['nullable', 'numeric', 'min:0'],
            'unit_labor_cost'            => ['nullable', 'numeric', 'min:0'],
            'overhead_pct_of_labor'      => ['nullable', 'numeric', 'min:0'],
            'unit_purchase_cost'         => ['nullable', 'numeric', 'min:0'],
            'inventory_coverage_days'    => ['nullable', 'integer', 'min:0'],
            'direct_cost_pct'            => ['nullable', 'numeric', 'min:0', 'max:100'],
            'annual_cost_increase_pct'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'beginning_inventory_units'  => ['nullable', 'numeric', 'min:0'],
            'beginning_inventory_value'  => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
