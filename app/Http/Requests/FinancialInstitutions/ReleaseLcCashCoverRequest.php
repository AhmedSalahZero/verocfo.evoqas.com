<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  ReleaseLcCashCoverRequest
//
//  Audit H-21 — closing an LC that was never drawn (expired or
//  cancelled) and getting its cash cover back from the bank.
//  Only a date and an optional note come from the user; the amount
//  and the accounts are always taken from the LC itself.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class ReleaseLcCashCoverRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'release_date' => ['required', 'date'],
            'notes'        => ['nullable', 'string', 'max:1000'],
        ];
    }
}
