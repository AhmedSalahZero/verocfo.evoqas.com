<?php

namespace App\Http\Controllers\Planning;

// ══════════════════════════════════════════════════════════════════
//  AnnualPlanController
//
//  HTTP layer for Annual Plan (Pro tier and above — module:planning,
//  feature 'annual_plan', per UserModulePermission::FEATURES and
//  ModulePermission::TIER_MODULES). Every wizard-step action is
//  shared with Business Plan & Valuation via ManagesPlanWizard
//  (Decision 1) — only Setup (store/update, using AnnualPlanRequest)
//  is specific to this controller: fixed 12-month duration, no
//  WACC/perpetual growth/valuation fields.
//
//  Routes: see routes/web.php, planning.annual-plan.* group.
//
//  @layer    Controller
//  @depends  PlanService, PlanSalesService, PlanCogsService,
//            PlanManpowerService, PlanExpenseService,
//            PlanFixedAssetService, PlanOpeningBalanceService,
//            AnnualPlanRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Controllers\Planning\Concerns\ManagesPlanWizard;
use App\Http\Requests\Planning\AnnualPlanRequest;
use App\Models\Plan;
use App\Services\Planning\PlanCogsService;
use App\Services\Planning\PlanExpenseService;
use App\Services\Planning\PlanFixedAssetService;
use App\Services\Planning\PlanManpowerService;
use App\Services\Planning\PlanOpeningBalanceService;
use App\Services\Planning\PlanSalesService;
use App\Services\Planning\PlanService;

class AnnualPlanController extends Controller
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
        return Plan::TYPE_ANNUAL;
    }

    protected function viewPrefix(): string
    {
        return 'Planning/AnnualPlan';
    }

    protected function routeName(): string
    {
        return 'planning.annual-plan';
    }

    public function create(\Illuminate\Http\Request $request)
    {
        return \Inertia\Inertia::render('Planning/AnnualPlan/Setup', [
            'currencies' => $this->currencyService->activeCurrenciesOrdered($this->companyId($request)),
            'plan'       => null,
        ]);
    }

    public function store(AnnualPlanRequest $request)
    {
        $plan = $this->planService->create($this->companyId($request), Plan::TYPE_ANNUAL, $request->validated(), $request->user());

        return redirect()->route('planning.annual-plan.edit', $plan->id)->with('success', "\"{$plan->name}\" created.");
    }

    public function update(AnnualPlanRequest $request, int $plan)
    {
        $plan = $this->planService->update($this->findPlan($request, $plan), $request->validated(), $request->user());

        return back()->with('success', "\"{$plan->name}\" updated.");
    }
}
