<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  DecreaseLgIssuanceRequest
//
//  Validates LgIssuanceController::decrease() (Advanced Payment LG
//  only — recalculates forward commission).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class DecreaseLgIssuanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'decrease_amount' => ['required', 'numeric', 'min:0.01'],
            'event_date'      => ['required', 'date'],
            'notes'           => ['nullable', 'string'],
        ];
    }
}
