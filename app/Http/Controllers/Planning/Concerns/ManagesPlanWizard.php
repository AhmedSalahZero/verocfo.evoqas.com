<?php

namespace App\Http\Controllers\Planning\Concerns;

// ══════════════════════════════════════════════════════════════════
//  ManagesPlanWizard
//
//  Shared by AnnualPlanController and BusinessPlanController — every
//  wizard-step action (Products, Sales, COGS, Manpower, Expenses,
//  Fixed Assets, Opening Balance) is identical between the two
//  products (Decision 1: one shared flow, two products), so it lives
//  here once instead of being copy-pasted into both controllers and
//  quietly drifting apart — same pattern as
//  Accounting\Concerns\BuildsComparativePeriods.
//
//  Only Setup (index/store/update/destroy) differs between the two
//  controllers — different Form Request class, different plan_type —
//  so those stay in each concrete controller, not here.
//
//  Every method resolves the plan via findPlan(), which filters by
//  BOTH company (via the model's own CompanyScope) AND planType() —
//  so AnnualPlanController can never accidentally operate on a
//  business_plan row by a guessed/mistyped id, and vice versa.
//
//  Rule 7.1 (max 15 lines per controller method) applies here too —
//  every method below delegates to a Planning service, never touches
//  the database directly (Rule 3.3).
//
//  @layer    Controller Concern
//  @depends  Plan, PlanProduct, PlanFixedAsset, PlanManpowerTitle,
//            PlanExpenseLine, FixedAsset, all Services\Planning\*
// ══════════════════════════════════════════════════════════════════

use App\Http\Requests\Planning\PlanCogsAssumptionRequest;
use App\Http\Requests\Planning\PlanCollectionTranchesRequest;
use App\Http\Requests\Planning\PlanExpenseLineRequest;
use App\Http\Requests\Planning\PlanFixedAssetRequest;
use App\Http\Requests\Planning\PlanManpowerHiringRequest;
use App\Http\Requests\Planning\PlanManpowerTitleRequest;
use App\Http\Requests\Planning\PlanProductRequest;
use App\Http\Requests\Planning\PlanSalesLinesRequest;
use App\Models\FixedAsset;
use App\Models\Plan;
use App\Models\PlanExpenseLine;
use App\Models\PlanFixedAsset;
use App\Models\PlanManpowerTitle;
use App\Models\PlanProduct;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;

trait ManagesPlanWizard
{
    abstract protected function planType(): string;

    abstract protected function viewPrefix(): string;

    abstract protected function routeName(): string;

    protected function findPlan(Request $request, int $planId): Plan
    {
        return Plan::where('id', $planId)->where('plan_type', $this->planType())->firstOrFail();
    }

    // ── Setup — list / show (store/update/destroy live per-controller) ──

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render($this->viewPrefix() . '/Index', [
            'plans'   => $this->planService->paginate($companyId, $this->planType(), $request->only('search', 'status')),
            'filters' => $request->only('search', 'status'),
        ]);
    }

    public function edit(Request $request, int $plan)
    {
        $plan = $this->findPlan($request, $plan)
            ->load(['currency', 'products.cogsAssumption', 'products.collectionTranches', 'products.salesLines']);

        return Inertia::render($this->viewPrefix() . '/Setup', [
            'plan' => $plan,
            'currencies' => $this->currencyService->activeCurrenciesOrdered($plan->company_id),
            'wizardSteps' => $this->wizardStepHrefs($plan),
        ]);
    }

    public function destroy(Request $request, int $plan)
    {
        $this->planService->delete($this->findPlan($request, $plan), $request->user());

        return redirect()->route($this->routeName() . '.index');
    }

    // ── Products ──────────────────────────────────────────────────

    public function storeProduct(PlanProductRequest $request, int $plan)
    {
        $product = $this->planService->addProduct($this->findPlan($request, $plan), $request->validated(), $request->user());

        return back()->with('success', "Product \"{$product->name}\" added.");
    }

    public function destroyProduct(Request $request, PlanProduct $planProduct)
    {
        $this->planService->removeProduct($planProduct, $request->user());

        return back()->with('success', 'Product removed.');
    }

    // Type-ahead search backing the Setup page's "Existing Product"
    // picker (SearchableSelect) — only sellable products (excludes
    // fixed-asset nature, which can't become a plan product) that
    // aren't already added to this plan.
    public function searchProducts(Request $request, int $plan)
    {
        $plan = $this->findPlan($request, $plan);
        $q = trim((string) $request->query('q', ''));
        $alreadyAdded = $plan->products()->whereNotNull('product_id')->pluck('product_id');

        $products = Product::where('company_id', $plan->company_id)
            ->where('is_active', true)
            ->where('nature', '!=', Product::NATURE_FIXED_ASSET)
            ->whereNotIn('id', $alreadyAdded)
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('code', 'like', "%{$q}%")
                    ->orWhere('name_en', 'like', "%{$q}%")
                    ->orWhere('name_ar', 'like', "%{$q}%");
            }))
            ->orderBy('name_en')->limit(20)->get(['id', 'code', 'name_en']);

        return response()->json(['results' => $products->map(fn ($p) => ['id' => $p->id, 'label' => "{$p->code} — {$p->name_en}"])]);
    }

    // Live, read-only preview of the LOCKED fields a real product
    // will bring in, shown on the Setup page before the planner
    // confirms "Add Product" — same resolution addProduct() itself
    // uses, so the preview can never drift from what actually gets saved.
    public function previewProduct(Request $request, int $plan, int $product)
    {
        $plan = $this->findPlan($request, $plan);

        return response()->json($this->planService->previewRealProduct($plan->company_id, $product));
    }

    // ── Sales ─────────────────────────────────────────────────────

    public function storeSalesLines(PlanSalesLinesRequest $request, PlanProduct $planProduct)
    {
        $this->planSalesService->saveSalesLines($planProduct, $request->validated('lines'), $request->user());

        return back()->with('success', 'Sales projection saved.');
    }

    public function storeCollectionTranches(PlanCollectionTranchesRequest $request, PlanProduct $planProduct)
    {
        $this->planSalesService->saveCollectionTranches(
            $planProduct, $request->validated('market'), $request->validated('tranches'), $request->user()
        );

        return back()->with('success', 'Collection policy saved.');
    }

    // ── COGS ──────────────────────────────────────────────────────

    public function storeCogsAssumption(PlanCogsAssumptionRequest $request, PlanProduct $planProduct)
    {
        $this->planCogsService->saveAssumption($planProduct, $request->validated(), $request->user());

        return back()->with('success', 'COGS assumption saved.');
    }

    // ── Manpower ──────────────────────────────────────────────────

    public function snapshotManpower(Request $request, int $plan)
    {
        $this->planManpowerService->snapshotExistingTeam($this->findPlan($request, $plan), $request->user());

        return back()->with('success', 'Existing team pulled in from Payroll.');
    }

    public function storeManpowerTitle(PlanManpowerTitleRequest $request, int $plan)
    {
        $this->planManpowerService->addPlannedTitle($this->findPlan($request, $plan), $request->validated(), $request->user());

        return back()->with('success', 'Title added.');
    }

    public function storeManpowerHiring(PlanManpowerHiringRequest $request, PlanManpowerTitle $planManpowerTitle)
    {
        $this->planManpowerService->setPlannedHiring(
            $planManpowerTitle, $request->validated('plan_year'), $request->validated('new_hires_count'), $request->user()
        );

        return back()->with('success', 'Planned hiring saved.');
    }

    public function destroyManpowerTitle(Request $request, PlanManpowerTitle $planManpowerTitle)
    {
        $this->planManpowerService->removeTitle($planManpowerTitle, $request->user());

        return back()->with('success', 'Title removed.');
    }

    // ── Expenses ──────────────────────────────────────────────────

    public function storeExpenseLine(PlanExpenseLineRequest $request, int $plan)
    {
        $this->planExpenseService->addLine($this->findPlan($request, $plan), $request->validated(), $request->user());

        return back()->with('success', 'Expense added.');
    }

    public function updateExpenseLine(PlanExpenseLineRequest $request, PlanExpenseLine $planExpenseLine)
    {
        $this->planExpenseService->updateLine($planExpenseLine, $request->validated(), $request->user());

        return back()->with('success', 'Expense updated.');
    }

    public function destroyExpenseLine(Request $request, PlanExpenseLine $planExpenseLine)
    {
        $this->planExpenseService->removeLine($planExpenseLine, $request->user());

        return back()->with('success', 'Expense removed.');
    }

    // ── Fixed Assets ──────────────────────────────────────────────

    public function eligibleFixedAssets(Request $request, int $plan)
    {
        return response()->json($this->planFixedAssetService->eligibleExistingAssets($this->companyId($request)));
    }

    public function storeExistingFixedAsset(Request $request, int $plan, FixedAsset $fixedAsset)
    {
        $this->planFixedAssetService->addExistingAsset($this->findPlan($request, $plan), $fixedAsset, $request->user());

        return back()->with('success', "\"{$fixedAsset->name}\" added from your Fixed Assets register.");
    }

    public function storePlannedFixedAsset(PlanFixedAssetRequest $request, int $plan)
    {
        $this->planFixedAssetService->addPlannedAsset($this->findPlan($request, $plan), $request->validated(), $request->user());

        return back()->with('success', 'Planned asset added.');
    }

    public function destroyFixedAsset(Request $request, PlanFixedAsset $planFixedAsset)
    {
        $this->planFixedAssetService->removeAsset($planFixedAsset, $request->user());

        return back()->with('success', 'Asset removed from plan.');
    }

    // ── Step pages (page-per-step wizard — Option A) ─────────────

    /** Every step's URL for a given plan, used by the WizardSteps bar. */
    private function wizardStepHrefs(Plan $plan): array
    {
        $prefix = $this->routeName();

        return [
            'setup'            => route($prefix . '.edit', $plan->id),
            'sales'            => route($prefix . '.sales', $plan->id),
            'cogs'             => route($prefix . '.cogs', $plan->id),
            'manpower'         => route($prefix . '.manpower', $plan->id),
            'expenses'         => route($prefix . '.expenses', $plan->id),
            'fixed-assets'     => route($prefix . '.fixed-assets', $plan->id),
            'opening-balance'  => route($prefix . '.opening-balance', $plan->id),
            'results'          => route($prefix . '.results', $plan->id),
        ];
    }

    public function salesPage(Request $request, int $plan)
    {
        $plan = $this->findPlan($request, $plan)
            ->load(['currency', 'products.salesLines', 'products.collectionTranches']);

        return Inertia::render($this->viewPrefix() . '/Sales', [
            'plan' => $plan,
            'wizardSteps' => $this->wizardStepHrefs($plan),
        ]);
    }

    public function cogsPage(Request $request, int $plan)
    {
        $plan = $this->findPlan($request, $plan)
            ->load(['currency', 'products.cogsAssumption']);

        return Inertia::render($this->viewPrefix() . '/Cogs', [
            'plan' => $plan,
            'wizardSteps' => $this->wizardStepHrefs($plan),
        ]);
    }

    public function manpowerPage(Request $request, int $plan)
    {
        $plan = $this->findPlan($request, $plan)
            ->load(['currency', 'manpowerTitles.hiringLines']);

        return Inertia::render($this->viewPrefix() . '/Manpower', [
            'plan' => $plan,
            'wizardSteps' => $this->wizardStepHrefs($plan),
        ]);
    }

    public function expensesPage(Request $request, int $plan)
    {
        $plan = $this->findPlan($request, $plan)
            ->load(['currency', 'expenseLines']);

        return Inertia::render($this->viewPrefix() . '/Expenses', [
            'plan' => $plan,
            'wizardSteps' => $this->wizardStepHrefs($plan),
        ]);
    }

    public function fixedAssetsPage(Request $request, int $plan)
    {
        $plan = $this->findPlan($request, $plan)
            ->load(['currency', 'fixedAssets']);

        return Inertia::render($this->viewPrefix() . '/FixedAssets', [
            'plan' => $plan,
            'wizardSteps' => $this->wizardStepHrefs($plan),
        ]);
    }

    public function openingBalancePage(Request $request, int $plan)
    {
        $plan = $this->findPlan($request, $plan)->load('currency');

        $data = $plan->opening_balance_source === Plan::OB_SOURCE_AUTO
            ? [
                'report'  => $this->planOpeningBalanceService->build($plan),
                'figures' => $this->planOpeningBalanceService->openingFigures($plan),
            ]
            : ['report' => null, 'figures' => null];

        return Inertia::render($this->viewPrefix() . '/OpeningBalance', array_merge($data, [
            'plan' => $plan,
            'wizardSteps' => $this->wizardStepHrefs($plan),
        ]));
    }

    public function resultsPage(Request $request, int $plan)
    {
        $plan = $this->findPlan($request, $plan)->load('currency');

        return Inertia::render($this->viewPrefix() . '/Results', [
            'plan' => $plan,
            'results' => $this->planCalculationService->calculate($plan, bypassCache: $request->boolean('refresh')),
            'wizardSteps' => $this->wizardStepHrefs($plan),
        ]);
    }
}
