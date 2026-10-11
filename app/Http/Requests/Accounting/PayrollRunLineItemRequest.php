<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  PayrollRunLineItemRequest
//
//  deduction_reason_id is required only when type=deduction — an
//  addition (a bonus) doesn't need one. Enforced with a conditional
//  rule rather than always-required, matching the generic-line-item
//  design (see PayrollRunLineItem header).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class PayrollRunLineItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type'                 => ['required', 'in:addition,deduction'],
            'deduction_reason_id'  => ['required_if:type,deduction', 'nullable', 'exists:deduction_reasons,id'],
            'amount'               => ['required', 'numeric', 'min:0.01'],
            'description'          => ['nullable', 'string', 'max:255'],
        ];
    }
}
