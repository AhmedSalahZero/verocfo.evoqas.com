<?php

namespace App\Http\Requests\Accounting;

// ══════════════════════════════════════════════════════════════════
//  AccrualRequest
//
//  Accruals have no draft/edit lifecycle — create() posts a real GL
//  entry immediately (see AccrualService header), so this Request
//  only ever validates a create.
//
//  @layer    FormRequest
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

class AccrualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ModulePermission middleware gates the route itself
    }

    public function rules(): array
    {
        return [
            'partner_id'          => ['required', 'integer', 'exists:partners,id'],
            'chart_of_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'cost_center_id'      => ['nullable', 'integer', 'exists:cost_centers,id'],
            'project_id'          => ['nullable', 'integer', 'exists:projects,id'],
            'sales_order_id'      => ['nullable', 'integer', 'exists:sales_orders,id'],
            // Section 7 — optional tag marking this as a real
            // manufacturing overhead cost belonging to a specific
            // pool, so it counts toward that pool's Actual side at
            // month-end reconciliation (PayrollRunService). Never
            // required — most accruals are unrelated to manufacturing.
            'overhead_pool_id'    => ['nullable', 'integer', 'exists:overhead_pools,id'],
            'description'         => ['required', 'string', 'max:500'],
            'accrual_date'        => ['required', 'date'],
            'amount'              => ['required', 'numeric', 'min:0.01'],
            'internal_notes'      => ['nullable', 'string'],
        ];
    }

    public function attributes(): array
    {
        return [
            'partner_id'          => 'Supplier',
            'chart_of_account_id' => 'Expense Account',
            'accrual_date'        => 'Accrual Date',
        ];
    }
}
