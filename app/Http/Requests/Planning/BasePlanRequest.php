<?php

namespace App\Http\Requests\Planning;

// ══════════════════════════════════════════════════════════════════
//  BasePlanRequest
//
//  Fields common to both Annual Plan and Business Plan & Valuation's
//  Setup step. AnnualPlanRequest and BusinessPlanRequest each extend
//  this and add only what differs (duration bounds, whether
//  WACC/perpetual growth/valuation fields are required) — deliberately
//  two concrete request classes rather than one generic class reading
//  plan_type off the route, so validation for each product is
//  self-contained and doesn't depend on route-parameter wiring being
//  correct at call time.
//
//  @layer    Request
// ══════════════════════════════════════════════════════════════════

use Illuminate\Foundation\Http\FormRequest;

abstract class BasePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function commonRules(): array
    {
        return [
            'name'                          => ['required', 'string', 'max:255'],
            'currency_id'                   => ['required', 'exists:currencies,id'],
            'start_date'                    => ['required', 'date'],
            'business_type'                 => ['required', 'in:manufacturing,trading,service,mixed'],
            'corporate_tax_rate'            => ['required', 'numeric', 'min:0', 'max:100'],
            'manpower_annual_increase_pct'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'opening_balance_source'        => ['nullable', 'in:auto,manual,none'],
        ];
    }
}
