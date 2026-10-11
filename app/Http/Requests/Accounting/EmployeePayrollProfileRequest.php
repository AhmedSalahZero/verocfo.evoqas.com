<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  EmployeePayrollProfileRequest
//
//  Validates employee salary setup. partner_id is only required on
//  create — an existing profile's employee is fixed (route-model-
//  bound), so update() never sends it.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class EmployeePayrollProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'currency_id'       => ['required', 'exists:currencies,id'],
            'eosb_rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];

        $rules['date_of_birth'] = ['nullable', 'date', 'before:today']; // M-35: for the age-50 leave entitlement

        if ($this->routeIs('*.employees.store')) {
            $rules['partner_id']  = ['required', 'exists:partners,id'];
            // Only required on create — these are the starting salary
            // and starting classification. Every change after that
            // goes through addSalaryRevision() / addClassificationRevision(),
            // not this form. See EmployeePayrollProfileService header.
            $rules['base_salary']      = ['required', 'numeric', 'min:0'];
            $rules['expense_category'] = ['required', 'in:manufacturing,sales,marketing,general_admin,cost_of_service'];

            // HR-002 fix (8 Sep 2026) — the real date this employee's
            // starting salary/classification began, so a company
            // migrating existing staff can record their true hire
            // date instead of being forced to use today. Defaults to
            // today when omitted (EmployeePayrollProfileService::
            // create()), matching the previous always-today behavior
            // for a genuinely new hire.
            $rules['effective_date'] = ['nullable', 'date'];

            // Manufacturing Role (Section 2) — optional even when the
            // starting category is manufacturing, since a company may
            // want to assign it in a follow-up edit rather than at
            // creation. When provided, the pairing rules below still
            // apply. See EmployeePayrollProfileService::
            // assertValidRoleAndPool() for the same rule enforced
            // server-side as the ultimate source of truth.
            $rules['manufacturing_role'] = ['nullable', 'in:direct_labor,overhead'];
            $rules['overhead_pool_id']   = ['required_if:manufacturing_role,overhead', 'nullable', 'exists:overhead_pools,id'];
        }

        return $rules;
    }
}
