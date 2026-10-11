<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  BillOfMaterialRequest
//
//  Validates create/update for a BOM header + material lines.
//  product_id/version/status are deliberately NOT here — those
//  are set by BillOfMaterialService, never user input (see that
//  service's update() explicitly stripping them from the update
//  path).
//
//  BOM Routing (VeroCFO_Manufacturing_Costing_Redesign_v1_0.md,
//  Section 5, Sept 2026): the old flat standard_labor_hours_per_batch
//  / standard_machine_hours_per_batch fields are gone — replaced by
//  the operations array below, one entry per Routing step.
//  Operations are optional (a BOM can have zero, same flexibility the
//  old fields had) — but every operation that IS provided must be
//  complete: Process, default Machine, and Labor Category are all
//  required per step, since standard costing has nothing to compute
//  from without all three.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class BillOfMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id'                        => ['required', 'exists:products,id'],
            'output_quantity'                   => ['required', 'numeric', 'min:0.0001'],
            'effective_date'                     => ['required', 'date'],
            'notes'                               => ['nullable', 'string'],

            'lines'                              => ['required', 'array', 'min:1'],
            'lines.*.component_product_id'      => ['required', 'exists:products,id'],
            'lines.*.quantity_per_batch'        => ['required', 'numeric', 'min:0.0001'],
            'lines.*.uom_id'                      => ['nullable', 'exists:units_of_measure,id'],

            'operations'                              => ['nullable', 'array'],
            'operations.*.process_id'                => ['required', 'exists:processes,id'],
            'operations.*.default_machine_id'        => ['required', 'exists:machines,id'],
            'operations.*.labor_category_id'         => ['required', 'exists:labor_categories,id'],
            'operations.*.standard_labor_hours'      => ['nullable', 'numeric', 'min:0'],
            'operations.*.standard_machine_hours'    => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
