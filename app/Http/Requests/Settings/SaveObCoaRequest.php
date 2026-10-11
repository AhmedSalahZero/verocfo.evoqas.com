<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  SaveObCoaRequest
//
//  Validates the Chart of Accounts tab on Opening Balances.
//  Split out of OpeningBalanceController::saveCoa() (July 2026
//  audit) — inline $request->validate() in a controller is a
//  Building Bible Rule 3.2 violation.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class SaveObCoaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account_id'     => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'entry_side'     => ['required', 'in:debit,credit'],
            'amount'         => ['required', 'numeric', 'min:0'],
            'currency_id'    => ['nullable', 'integer', 'exists:currencies,id'],
            'amount_foreign' => ['nullable', 'numeric', 'min:0'],
            'exchange_rate'  => ['nullable', 'numeric', 'min:0'],
            'notes'          => ['nullable', 'string'],
        ];
    }
}
