<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  EmployeeSalaryRevisionRequest
//
//  Validates a new salary revision on an Employee Payroll Profile —
//  see EmployeePayrollProfileService::addSalaryRevision() and
//  migration 000231's header.
//
//  effective_date deliberately has no min: date bound — a past date
//  is a legitimate correction (backdated data entry), not just a
//  future-scheduled raise.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeSalaryRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'base_salary'    => ['required', 'numeric', 'min:0'],
            'effective_date' => [
                'required',
                'date',
                // One revision per profile per date — see migration 000231's
                // esr_profile_date_unique index. Caught here as a friendly
                // validation error instead of a raw DB exception.
                Rule::unique('employee_salary_revisions', 'effective_date')
                    ->where('employee_payroll_profile_id', $this->route('profile')?->id),
            ],
            'note'           => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'effective_date.unique' => 'This employee already has a salary change dated on this day. Pick a different date, or edit/remove the existing entry first.',
        ];
    }
}