<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  KitRecipeController
//
//  HTTP layer for Kit recipe master data. Zero business logic —
//  delegated to KitRecipeService. Powers the "Assembly / Kits"
//  sidebar group's "Kit Recipes" item, gated by
//  Company::hasInventory() (not isManufacturing() — see migration
//  000251 header for why this is deliberately available to Trading /
//  Service-with-Inventory companies, not just Manufacturing).
//
//  Create + Edit share one Vue page (Rule 2). No activate()/version
//  action here, unlike BillOfMaterialController — see
//  KitRecipeService header for why this recipe is deliberately
//  unversioned. toggleActive() is the one extra action, for hiding a
//  discontinued kit from the Assembly picker without deleting it.
//
//  @layer    Controller
//  @depends  KitRecipeService, KitRecipeRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\KitRecipeRequest;
use App\Models\KitRecipe;
use App\Models\Product;
use App\Services\Accounting\KitRecipeService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class KitRecipeController extends Controller
{
    public function __construct(private KitRecipeService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Manufacturing/KitRecipeIndex', [
            'recipes' => $this->service->paginate($this->companyId($request), $request->only('search')),
            'filters' => $request->only('search'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Accounting/Manufacturing/KitRecipeForm', [
            'products' => Product::where('company_id', $this->companyId($request))->withStock()->forDropdown()->get(),
        ]);
    }

    public function edit(Request $request, KitRecipe $recipe)
    {
        return Inertia::render('Accounting/Manufacturing/KitRecipeForm', [
            'recipe'   => $recipe->load('lines.componentProduct'),
            'products' => Product::where('company_id', $this->companyId($request))->withStock()->forDropdown()->get(),
        ]);
    }

    public function store(KitRecipeRequest $request)
    {
        $this->service->create($this->companyId($request), $request->validated(), $request->user());

        return redirect()->route('accounting.kit-recipes.index')->with('success', 'Kit recipe created.');
    }

    public function update(KitRecipeRequest $request, KitRecipe $recipe)
    {
        $this->service->update($this->companyId($request), $recipe, $request->validated(), $request->user());

        return redirect()->route('accounting.kit-recipes.index')->with('success', 'Kit recipe updated.');
    }

    public function toggleActive(Request $request, KitRecipe $recipe)
    {
        $this->service->toggleActive($this->companyId($request), $recipe, $request->user());

        return back()->with('success', 'Kit recipe status updated.');
    }
}
