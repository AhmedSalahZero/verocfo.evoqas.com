<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankReconciliationMatchRequest
//
//  Matching / ticking / unticking ledger entries in a reconciliation. statement_line_id is only used by "match".
//  Every id is checked against the CURRENT company (audit H2) — a
//  bank account or line from another company is rejected here, before
//  the service is even reached.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BankReconciliationMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) app('currentCompanyId');

        return [
            'statement_line_id'  => ['nullable', 'integer', Rule::exists('bank_statement_lines', 'id')->where('company_id', $companyId)],
            'book_line_ids'      => ['nullable', 'array'],
            'book_line_ids.*'    => ['integer', Rule::exists('journal_entry_lines', 'id')->where('company_id', $companyId)],
            'window_days'        => ['nullable', 'integer', 'min:0', 'max:90'],
        ];
    }
}
