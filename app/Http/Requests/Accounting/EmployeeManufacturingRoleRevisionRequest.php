<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  EmployeeManufacturingRoleRevisionRequest
//
//  Validates a new Manufacturing Role revision on an Employee
//  Payroll Profile — see
//  EmployeePayrollProfileService::addManufacturingRoleRevision() and
//  migration 000258's header. Mirrors
//  EmployeeClassificationRevisionRequest exactly, plus the
//  role/overhead_pool_id pairing rule (also enforced server-side in
//  the service as the ultimate source of truth — see
//  assertValidRoleAndPool()).
//
//  effective_date deliberately has no min: date bound — a past date
//  is a legitimate correction, not just a future-scheduled change.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeManufacturingRoleRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role'             => ['required', 'in:direct_labor,overhead'],
            // Required when the role is Overhead, and — since a plain
            // 'nullable' rule alone would still let a Direct Labor
            // submission carry a leftover pool id from the form —
            // 'prohibited_unless' shuts that door explicitly too.
            'overhead_pool_id' => [
                'required_if:role,overhead',
                'prohibited_unless:role,overhead',
                'nullable',
                'exists:overhead_pools,id',
            ],
            'effective_date' => [
                'required',
                'date',
                // One revision per profile per date — see migration 000258's
                // emrr_profile_date_unique index. Caught here as a friendly
                // validation error instead of a raw DB exception.
                Rule::unique('employee_manufacturing_role_revisions', 'effective_date')
                    ->where('employee_payroll_profile_id', $this->route('profile')?->id),
            ],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'effective_date.unique'        => 'This employee already has a role change dated on this day. Pick a different date, or edit/remove the existing entry first.',
            'overhead_pool_id.required_if'  => 'Pick which Overhead Pool this employee\'s salary belongs to.',
            'overhead_pool_id.prohibited_unless' => 'An Overhead Pool only applies when the role is Manufacturing Overhead.',
        ];
    }
}
