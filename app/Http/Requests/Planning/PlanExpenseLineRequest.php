<?php

namespace App\Http\Requests\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanExpenseLineRequest
//
//  Operating expense assumption — category maps to real COA Level 2
//  groups (52/53/54/55, spec Section 3.2); expense_type is one of
//  pct_revenue/fixed_recurring/one_time.
//
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class PlanExpenseLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expense_name'          => ['required', 'string', 'max:200'],
            'category'              => ['required', 'in:sales,marketing,general_admin,finance'],
            'expense_type'          => ['required', 'in:pct_revenue,fixed_recurring,one_time'],
            'amount'                => ['required', 'numeric', 'min:0'],
            'amortization_months'   => ['nullable', 'integer', 'min:1'],
            'annual_increase_pct'   => ['nullable', 'numeric', 'min:0', 'max:100'],
            'start_month'           => ['nullable', 'integer', 'min:0'],
            'end_month'             => ['nullable', 'integer', 'min:0'],
        ];
    }
}
