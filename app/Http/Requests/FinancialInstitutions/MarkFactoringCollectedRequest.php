<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  MarkFactoringCollectedRequest
//
//  bank_account_id is nullable and optional — the "difference"
//  released back to the company defaults to the same account used
//  for the original disbursement (Mahmoud's confirmed design); the
//  user only needs to send this when overriding that default.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class MarkFactoringCollectedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'collection_date' => ['required', 'date'],
            'bank_account_id' => ['nullable', 'exists:bank_accounts,id'],
        ];
    }
}
