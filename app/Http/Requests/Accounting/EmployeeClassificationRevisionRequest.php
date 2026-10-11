<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  EmployeeClassificationRevisionRequest
//
//  Validates a new classification revision on an Employee Payroll
//  Profile — see EmployeePayrollProfileService::addClassificationRevision()
//  and migration 000256's header. Mirrors EmployeeSalaryRevisionRequest
//  exactly.
//
//  effective_date deliberately has no min: date bound — a past date
//  is a legitimate correction (backdated data entry), not just a
//  future-scheduled reclassification.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeClassificationRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expense_category' => ['required', 'in:manufacturing,sales,marketing,general_admin,cost_of_service'],
            'effective_date'   => [
                'required',
                'date',
                // One revision per profile per date — see migration 000256's
                // ecr_profile_date_unique index. Caught here as a friendly
                // validation error instead of a raw DB exception.
                Rule::unique('employee_classification_revisions', 'effective_date')
                    ->where('employee_payroll_profile_id', $this->route('profile')?->id),
            ],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'effective_date.unique' => 'This employee already has a classification change dated on this day. Pick a different date, or edit/remove the existing entry first.',
        ];
    }
}
