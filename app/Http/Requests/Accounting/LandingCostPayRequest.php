<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LandingCostPayRequest
//
//  Validates the "Pay" action on a posted Landing Cost.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class LandingCostPayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'paid_date'       => ['required', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'bank_account_id' => 'Bank Account',
            'paid_date'       => 'Payment Date',
        ];
    }
}
