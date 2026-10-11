<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LeaveTypeRequest
//
//  Validates one entry in the per-company leave type list.
//
//  annual_entitlement_days is deliberately NULLABLE and the
//  difference matters: null means "this type is not balance-tracked
//  at all" (sick leave approved case by case, unpaid leave which has
//  no allowance by definition), whereas 0 would mean "tracked, with
//  nothing available". Both are legitimate, so an empty field is not
//  coerced to zero.
//
//  deduction_reason_id is scoped to the current company with
//  Rule::exists()->where('company_id', ...) rather than a bare
//  exists: rule — the same Building Bible Rule 2.5 correction
//  StockTransferRequest documents. LeaveTypeService re-checks it as
//  a second, independent guard.
//
//  @layer    Request
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = app('currentCompanyId');

        return [
            'name_en' => [
                'required', 'string', 'max:255',
                Rule::unique('leave_types', 'name_en')
                    ->where('company_id', $companyId)
                    ->ignore($this->route('type')?->id),
            ],
            'name_ar' => ['nullable', 'string', 'max:255'],
            'is_paid' => ['required', 'boolean'],

            // null = untracked; 0 = tracked with nothing left.
            'annual_entitlement_days' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],

            // M-35
            'skip_non_working_days'   => ['nullable', 'boolean'],
            'senior_entitlement_days' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'senior_after_years'      => ['nullable', 'integer', 'min:0', 'max:60'],
            'senior_age'              => ['nullable', 'integer', 'min:0', 'max:100'],
            'carry_forward_max_days'  => ['nullable', 'numeric', 'min:0', 'max:9999.99'],

            'deduction_reason_id' => [
                'nullable',
                Rule::exists('deduction_reasons', 'id')->where('company_id', $companyId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name_en.unique' => 'This company already has a leave type with that name.',
        ];
    }
}
