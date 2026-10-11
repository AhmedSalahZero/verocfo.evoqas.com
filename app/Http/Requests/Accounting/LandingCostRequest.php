<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LandingCostRequest
//
//  Validates create/update for Landing Cost. Note: chart_of_account_id
//  must be a child of 146 (Landing Cost Clearing) — checked here at
//  the "is a real, active COA row" level; LandingCostService is where
//  partner.is_landing_cost_supplier gets enforced, not here.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class LandingCostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'partner_id'           => ['required', 'integer', 'exists:partners,id'],
            'chart_of_account_id'  => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'cost_date'            => ['required', 'date'],
            'amount'               => ['required', 'numeric', 'min:0.01'],
            'currency_id'          => ['nullable', 'integer', 'exists:currencies,id'],
            'exchange_rate'        => ['nullable', 'numeric', 'gt:0'],
            'vat_amount'           => ['nullable', 'numeric', 'min:0'], // recoverable customs/clearing VAT — excluded from the stock cost
            'reference'            => ['nullable', 'string', 'max:100'],
            'internal_notes'       => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'partner_id'          => 'Landing Cost Supplier',
            'chart_of_account_id' => 'Landing Cost Clearing Account',
            'cost_date'           => 'Cost Date',
        ];
    }
}
