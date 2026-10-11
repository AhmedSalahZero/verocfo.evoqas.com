<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  SaveObNoteReceivableRequest
//
//  Validates the Notes Receivable tab on Opening Balances.
//  Split out of OpeningBalanceController::saveNr() (July 2026 audit).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class SaveObNoteReceivableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'              => ['nullable', 'integer'],
            'partner_id'      => ['required', 'integer', 'exists:partners,id'],
            'check_number'    => ['required', 'string', 'max:50'],
            'drawee_bank'     => ['required', 'string', 'max:150'],
            'check_date'      => ['required', 'date'],
            'due_date'        => ['required', 'date'],
            'is_post_dated'   => ['boolean'],
            'amount'          => ['required', 'numeric', 'min:0.01'],
            'currency_id'     => ['nullable', 'integer'],
            'amount_foreign'  => ['nullable', 'numeric', 'min:0'],
            'exchange_rate'   => ['nullable', 'numeric', 'min:0'],
            'status'          => ['required', 'in:in_safe,under_collection'],
            'bank_account_id' => ['nullable', 'integer', 'exists:chart_of_accounts,id'],
            'notes'           => ['nullable', 'string'],
        ];
    }
}
