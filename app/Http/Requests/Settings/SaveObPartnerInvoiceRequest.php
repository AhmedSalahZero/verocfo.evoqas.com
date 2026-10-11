<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  SaveObPartnerInvoiceRequest
//
//  Validates the Customer AR / Supplier AP tabs on Opening Balances
//  (they share one table, ObPartnerInvoice, distinguished by
//  partner_type). Split out of
//  OpeningBalanceController::saveInvoice() (July 2026 audit).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class SaveObPartnerInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'id'                  => ['nullable', 'integer'],
            'partner_id'          => ['required', 'integer', 'exists:partners,id'],
            'partner_type'        => ['required', 'in:customer,supplier'],
            'account_id'          => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'invoice_ref'         => ['required', 'string', 'max:100'],
            'invoice_date'        => ['required', 'date'],
            'due_date'            => ['required', 'date'],
            'invoice_amount'      => ['required', 'numeric', 'min:0'],
            'previous_collection' => ['nullable', 'numeric', 'min:0'],
            'currency_id'         => ['nullable', 'integer'],
            'amount_foreign'      => ['nullable', 'numeric', 'min:0'],
            'exchange_rate'       => ['nullable', 'numeric', 'min:0'],
            'notes'               => ['nullable', 'string'],
        ];
    }
}
