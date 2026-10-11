<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  StoreReverseFactoringTransactionRequest
//
//  reverse_due_date is REQUIRED and always taken from the request —
//  Mahmoud's explicit ask is that the user can override the
//  agreement's own extended_payment_days per transaction, so unlike
//  received_amount on the AR side, this one IS trusted from the
//  client (there's no balance-of-the-ledger risk in a date).
//
//  cheques.* mirrors Supplier Payment's payable-cheque fields
//  (cheque_number, due_date) plus cheque_amount, which Supplier
//  Payment doesn't need (its cheque IS the whole payment) but this
//  does (multiple cheques can split one liability) — deliberately
//  NO reference_number field, per Mahmoud ("has no logic").
//  cheque_amount is validated as a plain positive number here; the
//  Service is what enforces the cheques must sum to exactly the
//  Reverse Factoring Amount, the same "never trust the client with
//  anything that has to balance" rule used throughout this module.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class StoreReverseFactoringTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'factoring_agreement_id'            => ['required', 'exists:factoring_agreements,id'],
            'supplier_bill_id'                  => ['required', 'exists:supplier_bills,id'],
            'reverse_factoring_date'             => ['required', 'date'],
            'reverse_due_date'                   => ['required', 'date', 'after:reverse_factoring_date'],
            'reverse_factoring_percentage'       => ['required', 'numeric', 'min:0.01', 'max:100'],
            'reverse_factoring_interest_amount'  => ['nullable', 'numeric', 'min:0'], // H-20: system recalculates; a typed figure must be within 1%
            'reverse_factoring_charges'          => ['nullable', 'numeric', 'min:0'],
            'settlement_method'                  => ['required', 'in:due_date,cheques'],
            'source_bank_account_id'             => ['required', 'exists:bank_accounts,id'],
            'notes'                              => ['nullable', 'string', 'max:1000'],

            'cheques'                    => ['required_if:settlement_method,cheques', 'array', 'min:1'],
            'cheques.*.cheque_number'    => ['required_with:cheques', 'string', 'max:100'],
            'cheques.*.due_date'         => ['required_with:cheques', 'date'],
            'cheques.*.cheque_amount'    => ['required_with:cheques', 'numeric', 'min:0.01'],
        ];
    }
}
