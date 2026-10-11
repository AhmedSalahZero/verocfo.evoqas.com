<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CustomerDebitNoteRequest
//
//  Validates create/update for Customer Debit Notes. Note: partner_id,
//  currency_id, exchange_rate are NOT validated here — they're
//  inherited from the invoice being debited (CustomerDebitNoteService::
//  create()), never user input. Mirrors CreditNoteRequest exactly.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class CustomerDebitNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_invoice_id' => ['required', 'integer', 'exists:customer_invoices,id'],
            'debit_date'          => ['required', 'date'],
            'debit_reason'        => ['nullable', 'string', 'max:500'],
            'internal_notes'      => ['nullable', 'string'],

            'lines'                             => ['required', 'array', 'min:1'],
            'lines.*.customer_invoice_line_id'  => ['required', 'integer', 'exists:customer_invoice_lines,id'],
            'lines.*.description'               => ['required', 'string', 'max:500'],
            'lines.*.quantity'                  => ['nullable', 'numeric', 'min:0.0001'],
            'lines.*.unit_price'                => ['required', 'numeric', 'min:0'],
            'lines.*.vat_rate'                  => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return [
            'customer_invoice_id' => 'Customer Invoice',
            'debit_date'          => 'Debit Date',
        ];
    }
}
