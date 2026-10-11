<?php

namespace App\Http\Requests\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanSalesLinesRequest
//
//  Saves a batch of monthly price/volume rows for one plan_product —
//  the caller can send the whole month grid or just the changed
//  months, PlanSalesService updates in place either way.
//
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class PlanSalesLinesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'lines'                    => ['required', 'array', 'min:1'],
            'lines.*.period_month'     => ['required', 'integer', 'min:0'],
            'lines.*.price'            => ['nullable', 'numeric', 'min:0'],
            'lines.*.volume'           => ['nullable', 'numeric', 'min:0'],
            'lines.*.local_pct'        => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.export_pct'       => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
