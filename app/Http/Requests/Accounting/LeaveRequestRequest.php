<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LeaveRequestRequest
//
//  Validates a leave request's own fields only. The rules that make
//  leave management actually work — no overlapping requests, no
//  overdrawn entitlement, the employee really is an employee — all
//  live in LeaveRequestService, not here, for the same reason
//  DeliveryNoteRequest defers to its service: they need to read other
//  rows and compare them, which is a service's job.
//
//  What this DOES do is stop a cross-tenant id before it reaches the
//  service, by scoping every exists: rule to the current company
//  (Building Bible Rule 2.5). The service re-checks both independently.
//
//  after_or_equal:from_date is enforced here as well as in the
//  service, because a date the user typed backwards deserves a field
//  error next to the input rather than an exception page.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeaveRequestRequest extends FormRequest
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
            'leave_type_id' => [
                'required',
                Rule::exists('leave_types', 'id')
                    ->where('company_id', $companyId)
                    ->where('is_active', true),
            ],
            'from_date' => ['required', 'date'],
            'to_date'   => ['required', 'date', 'after_or_equal:from_date'],
            'reason'    => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'to_date.after_or_equal' => 'Leave cannot end before it starts.',
            'leave_type_id.exists'   => 'Choose an active leave type belonging to this company.',
        ];
    }

    public function attributes(): array
    {
        return [
            'partner_id'    => 'employee',
            'leave_type_id' => 'leave type',
            'from_date'     => 'from date',
            'to_date'       => 'to date',
        ];
    }
}
