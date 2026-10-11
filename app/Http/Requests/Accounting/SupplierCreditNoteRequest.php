<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SupplierCreditNoteRequest
//
//  Validates create/update for Supplier Credit Notes. Note: partner_id,
//  currency_id, exchange_rate are NOT validated here — they're
//  inherited from the bill being affected (SupplierCreditNoteService::
//  create()), never user input.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class SupplierCreditNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_bill_id'               => ['required', 'integer', 'exists:supplier_bills,id'],
            'credit_date'                     => ['required', 'date'],
            'credit_reason'                   => ['nullable', 'string', 'max:500'],
            'supplier_debit_note_reference'   => ['nullable', 'string', 'max:100'],
            'internal_notes'                  => ['nullable', 'string'],

            'lines'                            => ['required', 'array', 'min:1'],
            'lines.*.supplier_bill_line_id'    => ['required', 'integer', 'exists:supplier_bill_lines,id'],
            'lines.*.description'              => ['required', 'string', 'max:500'],
            'lines.*.quantity'                 => ['nullable', 'numeric', 'min:0.0001'],
            'lines.*.unit_price'               => ['required', 'numeric', 'min:0'],
            'lines.*.vat_rate'                 => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_bill_id'               => 'Supplier Bill',
            'credit_date'                     => 'Credit Date',
            'supplier_debit_note_reference'  => "Supplier's Debit Note Reference",
        ];
    }
}
