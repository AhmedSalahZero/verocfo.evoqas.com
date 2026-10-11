<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  RenewTdRequest
//
//  Validates TimeDepositController::renew() — the TD stays open past
//  maturity. The interest for the completed period is realized (same
//  shape as Apply Interest) and the account's own maturity date and
//  rate roll forward. Principal is never part of this action.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class RenewTdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_date'              => ['required', 'date'],
            'new_maturity_date'       => ['required', 'date', 'after:event_date'],
            'new_interest_rate'       => ['required', 'numeric', 'min:0', 'max:100'],
            'wht_amount'              => ['nullable', 'numeric', 'min:0'], // M-33: blank = deposit's WHT rate × interest
            'interest_amount'         => ['required', 'numeric', 'min:0'],
            'credit_bank_account_id'  => ['required', 'exists:bank_accounts,id'],
        ];
    }
}
