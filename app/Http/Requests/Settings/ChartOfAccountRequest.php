<?php

namespace App\Http\Requests\Settings;

// ══════════════════════════════════════════════════════════════════
//  ChartOfAccountRequest
//
//  Validates create and update requests for Chart of Accounts.
//  On create: parent_id, name_en, optional currency for cash/bank.
//  On update: name_en only (code, class, section are immutable).
//
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class ChartOfAccountRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $isEdit    = $this->route('coa') !== null;
        $companyId = app()->bound('currentCompanyId') ? app('currentCompanyId') : null;

        if ($isEdit) {
            return [
                'name_en'              => ['required', 'string', 'max:150'],
                'name_ar'              => ['nullable', 'string', 'max:150'],
                'currency_id'          => ['nullable', 'exists:currencies,id'],
                'requires_cost_center' => ['nullable', 'boolean'],
            ];
        }

        return [
            'parent_id'            => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'name_en'              => ['required', 'string', 'max:150'],
            'name_ar'              => ['nullable', 'string', 'max:150'],
            'use_auto_code'        => ['required', 'boolean'],
            // A manually typed code must not already exist for this
            // company — and "exists" includes the shared system
            // accounts (company_id NULL), which is what forCompany()
            // means everywhere else. The database's
            // unique(company_id, code) index cannot express that: a
            // NULL company_id never equals a real one in SQL, so it
            // would happily accept a second 111001 alongside the
            // seeded Land.
            //
            // ChartOfAccountService::assertCodeIsFree() enforces the
            // same rule for callers that do not come through this
            // form. This rule exists so the message lands on the field
            // instead of arriving as an exception.
            'code'                 => [
                'required_if:use_auto_code,false', 'nullable', 'string', 'max:20',
                function ($attribute, $value, $fail) use ($companyId) {
                    if ($this->boolean('use_auto_code') || ! $value || ! $companyId) {
                        return;
                    }

                    $taken = \App\Models\ChartOfAccount::withoutGlobalScopes()
                        ->forCompany($companyId)
                        ->where('code', $value)
                        ->exists();

                    if ($taken) {
                        $fail("Account code {$value} is already in use.");
                    }
                },
            ],
            'currency_id'          => ['nullable', 'exists:currencies,id'],
            'requires_cost_center' => ['nullable', 'boolean'],
        ];
    }
}
