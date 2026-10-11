<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  CancelLgIssuanceRequest
//
//  Validates LgIssuanceController::cancel() (reverses cash cover).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class CancelLgIssuanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cancelled_at'                  => ['required', 'date'],
            'cancelled_reversal_account_id' => ['nullable', 'exists:bank_accounts,id'],
            'notes'                         => ['nullable', 'string'],
        ];
    }
}
