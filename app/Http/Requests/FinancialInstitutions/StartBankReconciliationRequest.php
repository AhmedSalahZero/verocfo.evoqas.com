<?php

namespace App\Http\Requests\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  StartBankReconciliationRequest
//
//  Start (or edit the header of) a bank reconciliation: account, statement date, closing balance.
//  Every id is checked against the CURRENT company (audit H2) — a
//  bank account or line from another company is rejected here, before
//  the service is even reached.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartBankReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) app('currentCompanyId');

        return [
            'bank_account_id'          => ['required', 'integer', Rule::exists('bank_accounts', 'id')->where('company_id', $companyId)],
            'statement_date'           => ['required', 'date', 'before_or_equal:today'],
            'statement_ending_balance' => ['required', 'numeric'],
            'notes'                    => ['nullable', 'string', 'max:1000'],
        ];
    }
}
