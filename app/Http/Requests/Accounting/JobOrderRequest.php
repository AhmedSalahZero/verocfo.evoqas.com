<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  JobOrderRequest
//
//  Validates opening a new Job Order. lines is required only when no
//  template is chosen (job_order_template_id is present) — confirmed:
//  a job can start from a saved template OR be entered ad-hoc.
//  estimated_material_cost_per_unit is NEVER here — JobOrderService
//  always auto-calculates it from the lines × current WAC. Only
//  labor/overhead estimates are user input, since those have no
//  repeatable routing figure to derive from for a one-off job.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class JobOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'job_order_template_id'            => ['nullable', 'exists:job_order_templates,id'],
            'sales_order_id'                    => ['nullable', 'exists:sales_orders,id'],
            'product_id'                         => ['required', 'exists:products,id'],
            'quantity_to_produce'               => ['required', 'numeric', 'min:0.0001'],
            'raw_material_location_id'          => ['required', 'exists:inventory_locations,id'],
            'output_location_id'                => ['required', 'exists:inventory_locations,id'],
            'planned_start_date'                => ['nullable', 'date'],
            'estimated_labor_cost_per_unit'     => ['nullable', 'numeric', 'min:0'],
            'estimated_overhead_cost_per_unit'  => ['nullable', 'numeric', 'min:0'],
            'notes'                              => ['nullable', 'string'],

            'lines'                              => ['required_without:job_order_template_id', 'array'],
            'lines.*.component_product_id'      => ['required_with:lines', 'exists:products,id'],
            'lines.*.quantity'                  => ['required_with:lines', 'numeric', 'min:0.0001'],
            'lines.*.uom_id'                     => ['nullable', 'exists:units_of_measure,id'],
        ];
    }
}
