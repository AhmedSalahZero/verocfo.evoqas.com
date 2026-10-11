<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  ApplyTdInterestRequest
//
//  Validates TimeDepositController::applyInterest() — periodic
//  interest on an open Time Deposit. Principal is never part of this
//  action, see TimeDepositService::applyInterest().
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class ApplyTdInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_date'              => ['required', 'date'],
            'wht_amount'              => ['nullable', 'numeric', 'min:0'], // M-33: blank = deposit's WHT rate × interest
            'interest_amount'         => ['required', 'numeric', 'min:0.01'],
            'credit_bank_account_id'  => ['required', 'exists:bank_accounts,id'],
        ];
    }
}
