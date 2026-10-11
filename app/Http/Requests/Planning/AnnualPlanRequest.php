<?php

namespace App\Http\Requests\Planning;

// ══════════════════════════════════════════════════════════════════
//  AnnualPlanRequest
//
//  Setup step validation for Annual Plan. Duration isn't collected at
//  all — Plan::ANNUAL_DURATION_YEARS is hardcoded by PlanService, not
//  something the user picks. No WACC/perpetual growth/valuation
//  fields — a 12-month operating budget has no valuation step.
//
//  @layer    Request
//  @depends  BasePlanRequest
// ══════════════════════════════════════════════════════════════════

class AnnualPlanRequest extends BasePlanRequest
{
    public function rules(): array
    {
        return $this->commonRules();
    }
}
