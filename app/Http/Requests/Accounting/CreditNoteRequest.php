<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CreditNoteRequest
//
//  Validates create/update for Credit Notes. Note: partner_id,
//  currency_id, exchange_rate are NOT validated here — they're
//  inherited from the invoice being credited (CreditNoteService::
//  create()), never user input.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class CreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_invoice_id' => ['required', 'integer', 'exists:customer_invoices,id'],
            'credit_date'         => ['required', 'date'],
            'credit_reason'       => ['nullable', 'string', 'max:500'],
            'internal_notes'      => ['nullable', 'string'],

            'lines'                                    => ['required', 'array', 'min:1'],
            'lines.*.customer_invoice_line_id'          => ['required', 'integer', 'exists:customer_invoice_lines,id'],
            'lines.*.description'                       => ['required', 'string', 'max:500'],
            'lines.*.quantity'                           => ['nullable', 'numeric', 'min:0.0001'],
            'lines.*.unit_price'                         => ['required', 'numeric', 'min:0'],
            'lines.*.vat_rate'                           => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_invoice_id' => 'Customer Invoice',
            'credit_date'          => 'Credit Date',
        ];
    }
}
