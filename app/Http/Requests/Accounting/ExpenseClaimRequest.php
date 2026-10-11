<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ExpenseClaimRequest
//
//  Validates an expense claim and its receipt lines.
//
//  Every id is scoped to the current company (Building Bible Rule
//  2.5) — a bare exists: rule would accept another tenant's expense
//  account or cost centre. Chart of Accounts is the one exception
//  that needs care: its rows are either shared platform-wide
//  (company_id NULL) or owned by one company, so the rule has to
//  accept both, which is what the closure below does.
//  ExpenseClaimService re-checks the same thing independently.
//
//  At least one line is required. A claim with no receipts is not a
//  claim, and the service refuses to submit one anyway — this just
//  puts the error next to the form rather than at the end of it.
//
//  amount is gt:0 per line: a zero receipt is a mistake, and a
//  negative one is someone trying to express a refund, which this
//  module does not model (see migration 000281 on what is out of
//  scope).
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExpenseClaimRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app('currentCompanyId');

        return [
            'partner_id' => [
                'required',
                Rule::exists('partners', 'id')->where('company_id', $companyId),
            ],
            'claim_date'  => ['required', 'date'],
            'title'       => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],

            'currency_id'   => ['required', Rule::exists('currencies', 'id')],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],

            'lines'                          => ['required', 'array', 'min:1'],
            'lines.*.expense_account_id'     => [
                'required',
                // Shared system accounts carry company_id = NULL; a
                // company's own carry its id. Both are valid, another
                // tenant's is not.
                Rule::exists('chart_of_accounts', 'id')->where(
                    fn ($q) => $q->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId))
                ),
            ],
            'lines.*.cost_center_id' => [
                'nullable',
                Rule::exists('cost_centers', 'id')->where('company_id', $companyId),
            ],
            'lines.*.expense_date' => ['required', 'date'],
            'lines.*.description'  => ['required', 'string', 'max:255'],
            'lines.*.amount'       => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required'                    => 'Add at least one receipt to the claim.',
            'lines.*.amount.gt'                 => 'Each receipt must be for more than zero.',
            'lines.*.expense_account_id.exists' => 'Choose an expense account belonging to this company.',
        ];
    }
}
