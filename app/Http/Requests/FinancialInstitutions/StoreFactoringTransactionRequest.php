<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  StoreFactoringTransactionRequest
//
//  Validates disbursement of a Factoring Transaction under an
//  existing Factoring Agreement. factoring_interest_amount and
//  other_charges are user-editable after the live preview (same as
//  CashVero's own form — the calculate() endpoint only supplies a
//  starting suggestion). received_amount is deliberately NOT
//  accepted here — unlike CashVero (pure treasury, no balanced
//  ledger to protect), Vero CFO's JournalEntryService rejects any
//  entry where debits and credits don't match exactly. Letting the
//  browser submit an independent received_amount could produce a
//  disbursement that doesn't balance, so FactoringTransactionService
//  always derives it as Factoring Amount − Interest − Other Charges.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class StoreFactoringTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'factoring_agreement_id'     => ['required', 'exists:factoring_agreements,id'],
            'customer_invoice_id'        => ['required', 'exists:customer_invoices,id'],
            'factoring_date'             => ['required', 'date'],
            'factoring_percentage'       => ['required', 'numeric', 'min:0.01', 'max:100'],
            'factoring_interest_amount'  => ['nullable', 'numeric', 'min:0'], // H-20: system recalculates; a typed figure must be within 1%
            'other_charges'              => ['nullable', 'numeric', 'min:0'],
            'bank_account_id'            => ['required', 'exists:bank_accounts,id'],
            'notes'                      => ['nullable', 'string', 'max:1000'],
        ];
    }
}
