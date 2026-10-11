<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  CloseTdRequest
//
//  Validates TimeDepositController::breakOrMature() — used for both
//  Break TD (early closure) and Apply at Maturity (on-schedule
//  closure). Same shape either way: both always close the FULL
//  Time Deposit (confirmed with Mahmoud — no partial break), so
//  there's no principal_amount field here at all — the principal is
//  read from the account itself, not submitted by the user. Only the
//  interest and where it lands are user input.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class CloseTdRequest extends FormRequest
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
            'interest_amount'         => ['required', 'numeric', 'min:0'],
            'credit_bank_account_id'  => ['required', 'exists:bank_accounts,id'],
        ];
    }
}
