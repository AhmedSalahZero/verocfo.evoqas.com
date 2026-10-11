<?php

namespace App\Http\Requests\Valuation;

// ══════════════════════════════════════════════════════════════════
//  BusinessPlanRequest
//
//  Setup step validation for Business Plan & Valuation. Duration is
//  user-picked, 3–10 years (Decision 8 — confirmed cap, not CFO
//  Tools' wider 3–20). WACC, perpetual growth rate, and the
//  Multiplier Valuation inputs (basis + multiplier) are all required
//  here — this product always produces a Valuation page.
//
//  Lives under Requests\Valuation (not Requests\Planning) to mirror
//  the module split confirmed in Decision 1: Business Plan &
//  Valuation is licensed as one Enterprise-only product under the
//  `valuation` module, even though it shares PlanService/BasePlanRequest
//  with Annual Plan under the hood.
//
//  @layer    Request
//  @depends  BasePlanRequest, Plan
// ══════════════════════════════════════════════════════════════════

use App\Http\Requests\Planning\BasePlanRequest;
use App\Models\Plan;

class BusinessPlanRequest extends BasePlanRequest
{
    public function rules(): array
    {
        return array_merge($this->commonRules(), [
            'duration_years'         => [
                'required', 'integer',
                'min:' . Plan::BUSINESS_PLAN_MIN_YEARS,
                'max:' . Plan::BUSINESS_PLAN_MAX_YEARS,
            ],
            'wacc_pct'                => ['required', 'numeric', 'min:0', 'max:100'],
            'perpetual_growth_pct'    => ['required', 'numeric', 'min:0', 'max:20'],
            'valuation_basis'         => ['required', 'in:revenue,ebitda,net_profit'],
            'valuation_multiplier'    => ['required', 'numeric', 'min:0'],
        ]);
    }
}
