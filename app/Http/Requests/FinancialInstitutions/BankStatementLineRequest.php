<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankStatementLineRequest
//
//  One bank statement line typed in by hand. Amount is signed: + money in, − money out.
//  Every id is checked against the CURRENT company (audit H2) — a
//  bank account or line from another company is rejected here, before
//  the service is even reached.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BankStatementLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) app('currentCompanyId');

        return [
            'line_date'   => ['required', 'date'],
            'amount'      => ['required', 'numeric', 'not_in:0'],
            'reference'   => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
