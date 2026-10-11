<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  PaymentTermRequest
//
//  Validates create and update requests for Payment Terms.
//  The lines repeater is validated as a required array where
//  each line must have percentage, due_days, and reference_point.
//
//  Percentage sum validation (must equal 100%) is handled in
//  PaymentTermService — not here — because FormRequest validation
//  cannot easily do cross-field aggregate arithmetic.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class PaymentTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'                       => ['required', 'string', 'max:20'],
            'name_en'                    => ['required', 'string', 'max:100'],
            'name_ar'                    => ['nullable', 'string', 'max:100'],
            'lines'                      => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.percentage'         => ['required', 'numeric', 'min:0.01', 'max:100'],
            'lines.*.due_days'           => ['required', 'integer', 'min:0', 'max:365'],
            'lines.*.reference_point'    => ['required', 'in:invoice_date,so_date,po_date,contract_date'],
            'lines.*.label'              => ['nullable', 'string', 'max:80'],
        ];
    }
}
