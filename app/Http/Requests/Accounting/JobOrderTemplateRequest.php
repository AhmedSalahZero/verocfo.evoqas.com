<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  JobOrderTemplateRequest
//
//  Validates a Job Order template — the optional, unversioned
//  starting-point list. Lighter than BillOfMaterialRequest: no
//  version/effective_date, since a template here is a convenience,
//  not a governed document.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class JobOrderTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'                          => ['required', 'string', 'max:150'],
            'product_id'                    => ['nullable', 'exists:products,id'],
            'is_active'                     => ['nullable', 'boolean'],
            'notes'                         => ['nullable', 'string'],
            'lines'                         => ['required', 'array', 'min:1'],
            'lines.*.component_product_id'  => ['required', 'exists:products,id'],
            'lines.*.quantity_per_unit'     => ['required', 'numeric', 'min:0.0001'],
            'lines.*.uom_id'                => ['nullable', 'exists:units_of_measure,id'],
        ];
    }
}
