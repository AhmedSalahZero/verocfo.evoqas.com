<?php

namespace App\Http\Controllers\Valuation;

// ══════════════════════════════════════════════════════════════════
//  BusinessPlanController
//
//  HTTP layer for Business Plan & Valuation (Enterprise tier only —
//  module:valuation, feature 'business_plan', per
//  UserModulePermission::FEATURES and ModulePermission::TIER_MODULES
//  — 'business_plan' relocated from the 'planning' feature array to
//  'valuation' as part of this build, per Decision 1: Business Plan
//  is bundled with Valuation, not available at Pro tier on its own).
//
//  Every wizard-step action is shared with Annual Plan via
//  ManagesPlanWizard (Decision 1) — only Setup (store/update, using
//  BusinessPlanRequest) is specific to this controller: user-picked
//  3–10 year duration, WACC/perpetual growth/valuation fields
//  required. The Valuation page itself (DCF + Multiplier results)
//  is a Phase 2 addition once PlanCalculationService exists — not
//  built in this pass.
//
//  Routes: see routes/web.php, valuation.business-plan.* group.
//
//  @layer    Controller
//  @depends  PlanService, PlanSalesService, PlanCogsService,
//            PlanManpowerService, PlanExpenseService,
//            PlanFixedAssetService, PlanOpeningBalanceService,
//            BusinessPlanRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Controllers\Planning\Concerns\ManagesPlanWizard;
use App\Http\Requests\Valuation\BusinessPlanRequest;
use App\Models\Plan;
use App\Services\Planning\PlanCogsService;
use App\Services\Planning\PlanExpenseService;
use App\Services\Planning\PlanFixedAssetService;
use App\Services\Planning\PlanManpowerService;
use App\Services\Planning\PlanOpeningBalanceService;
use App\Services\Planning\PlanSalesService;
use App\Services\Planning\PlanService;

class BusinessPlanController extends Controller
{
    use ManagesPlanWizard;

    public function __construct(
        protected PlanService $planService,
        protected PlanSalesService $planSalesService,
        protected PlanCogsService $planCogsService,
        protected PlanManpowerService $planManpowerService,
        protected PlanExpenseService $planExpenseService,
        protected PlanFixedAssetService $planFixedAssetService,
        protected PlanOpeningBalanceService $planOpeningBalanceService,
        protected \App\Services\Planning\PlanCalculationService $planCalculationService,
        protected \App\Services\CurrencyService $currencyService,
    ) {
    }

    protected function planType(): string
    {
        return Plan::TYPE_BUSINESS_PLAN;
    }

    protected function viewPrefix(): string
    {
        return 'Valuation/BusinessPlan';
    }

    protected function routeName(): string
    {
        return 'valuation.business-plan';
    }

    public function create(\Illuminate\Http\Request $request)
    {
        return \Inertia\Inertia::render('Valuation/BusinessPlan/Setup', [
            'currencies' => $this->currencyService->activeCurrenciesOrdered($this->companyId($request)),
            'plan'       => null,
        ]);
    }

    public function store(BusinessPlanRequest $request)
    {
        $plan = $this->planService->create($this->companyId($request), Plan::TYPE_BUSINESS_PLAN, $request->validated(), $request->user());

        return redirect()->route('valuation.business-plan.edit', $plan->id)->with('success', "\"{$plan->name}\" created.");
    }

    public function update(BusinessPlanRequest $request, int $plan)
    {
        $plan = $this->planService->update($this->findPlan($request, $plan), $request->validated(), $request->user());

        return back()->with('success', "\"{$plan->name}\" updated.");
    }
}
