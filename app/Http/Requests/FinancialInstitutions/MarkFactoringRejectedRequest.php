<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  MarkFactoringRejectedRequest
//
//  bank_account_id is nullable and optional — the repayment to the
//  factoring company defaults to the same account used for the
//  original disbursement (Mahmoud's confirmed design); the user only
//  needs to send this when overriding that default.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class MarkFactoringRejectedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rejection_date'             => ['required', 'date'],
            'uncollected_invoice_charges' => ['nullable', 'numeric', 'min:0'],
            'bank_account_id'            => ['nullable', 'exists:bank_accounts,id'],
        ];
    }
}
