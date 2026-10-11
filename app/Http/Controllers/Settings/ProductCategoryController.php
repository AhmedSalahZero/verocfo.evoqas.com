<?php

namespace App\Http\Controllers\Settings;

// ══════════════════════════════════════════════════════════════════
//  ProductCategoryController
//
//  HTTP layer for Product Categories.
//  Routes:
//    GET    /settings/product-categories              → index()
//    GET    /settings/product-categories/create       → create()
//    POST   /settings/product-categories              → store()
//    GET    /settings/product-categories/{cat}/edit   → edit()
//    PUT    /settings/product-categories/{cat}        → update()
//    PATCH  /settings/product-categories/{cat}/toggle → toggleActive()
//    POST   /settings/product-categories/quick        → quickCreate() [JSON — from product form modal]
//
//  @layer    Controller
//  @depends  ProductCategoryService, ProductCategoryRequest,
//            ProductCategoryQuickCreateRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProductCategoryQuickCreateRequest;
use App\Http\Requests\Settings\ProductCategoryRequest;
use App\Models\ProductCategory;
use App\Services\ProductCategoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductCategoryController extends Controller
{
    public function __construct(private ProductCategoryService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Settings/ProductCategories/Index', [
            'categories' => $this->service->tree($this->companyId($request)),
        ]);
    }

    public function create(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $parentId  = $request->query('parent_id') ? (int) $request->query('parent_id') : null;
        $mains     = $this->service->mainForSelector($companyId);

        return Inertia::render('Settings/ProductCategories/Form', [
            'category'           => null,
            'main_categories'    => $mains,
            'selected_parent_id' => $parentId,
            'selected_parent'    => $parentId ? $mains->firstWhere('id', $parentId) : null,
            'expense_accounts'   => $this->service->expenseAccounts($companyId),
        ]);
    }

    public function store(ProductCategoryRequest $request)
    {
        try {
            $cat = $this->service->create($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('settings.product-categories.index')
                ->with('success', "Category [{$cat->name_en}] created.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function edit(Request $request, ProductCategory $productCategory): Response
    {
        $companyId = $this->companyId($request);
        return Inertia::render('Settings/ProductCategories/Form', [
            'category'           => $productCategory->load('parent', 'expenseAccount'),
            'main_categories'    => $this->service->mainForSelector($companyId),
            'selected_parent_id' => null,
            'selected_parent'    => null,
            'expense_accounts'   => $this->service->expenseAccounts($companyId),
        ]);
    }

    public function update(ProductCategoryRequest $request, ProductCategory $productCategory)
    {
        try {
            $this->service->update($productCategory, $request->validated(), $request->user());
            return redirect()->route('settings.product-categories.index')
                ->with('success', "Category [{$productCategory->name_en}] updated.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function toggleActive(Request $request, ProductCategory $productCategory)
    {
        try {
            $this->service->toggleActive($productCategory, $request->user());
            return back()->with('success', 'Category status updated.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Quick-create from inline modal on the Product Form.
     * Returns JSON with the new category for Vue to add to dropdown.
     */
    public function quickCreate(ProductCategoryQuickCreateRequest $request)
    {
        try {
            $cat = $this->service->create($this->companyId($request), $request->validated(), $request->user());
            return response()->json([
                'id'        => $cat->id,
                'name_en'   => $cat->name_en,
                'name_ar'   => $cat->name_ar,
                'parent_id' => $cat->parent_id,
                'level'     => $cat->level,
            ]);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }
}
