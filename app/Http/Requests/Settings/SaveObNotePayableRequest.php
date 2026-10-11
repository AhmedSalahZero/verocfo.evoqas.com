<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  SaveObNotePayableRequest
//
//  Validates the Notes Payable tab on Opening Balances.
//  Split out of OpeningBalanceController::saveNp() (July 2026 audit).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use App\Rules\ExistsForCompany;
use Illuminate\Foundation\Http\FormRequest;

class SaveObNotePayableRequest extends FormRequest
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
            'drawl_bank'      => ['required', 'string', 'max:150'],
            'check_date'      => ['required', 'date'],
            'due_date'        => ['required', 'date'],
            'is_post_dated'   => ['boolean'],
            'amount'          => ['required', 'numeric', 'min:0.01'],
            'currency_id'     => ['nullable', 'integer'],
            'amount_foreign'  => ['nullable', 'numeric', 'min:0'],
            'exchange_rate'   => ['nullable', 'numeric', 'min:0'],
            'status'          => ['required', 'in:issued,received_by_supplier'],
            // Audit C7: required — the bank GL account (131/132) the cheque
            // is drawn on; the opening cheque is marked paid from it.
            'bank_account_id' => ['required', 'integer', new ExistsForCompany(
                'chart_of_accounts', true,
                fn ($q) => $q->where('is_postable', true)
                             ->where(fn ($w) => $w->where('code', 'like', '131%')->orWhere('code', 'like', '132%'))
            )],
            'notes'           => ['nullable', 'string'],
        ];
    }
}
