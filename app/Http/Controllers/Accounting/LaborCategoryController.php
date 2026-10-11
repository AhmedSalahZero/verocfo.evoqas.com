<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LaborCategoryController
//
//  HTTP layer for user-defined labor categories. New sidebar entry,
//  added alongside Overhead Pools, gated by Company::isManufacturing().
//  Create + Edit share one Vue page. Mirrors OverheadPoolController
//  exactly.
//
//  @layer    Controller
//  @depends  LaborCategoryService, LaborCategoryRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\LaborCategoryRequest;
use App\Models\LaborCategory;
use App\Services\Accounting\LaborCategoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LaborCategoryController extends Controller
{
    public function __construct(private LaborCategoryService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Manufacturing/LaborCategoryIndex', [
            'categories' => $this->service->paginate($this->companyId($request), $request->only('search')),
            'filters'    => $request->only('search'),
        ]);
    }

    public function create()
    {
        return Inertia::render('Accounting/Manufacturing/LaborCategoryForm');
    }

    public function edit(LaborCategory $category)
    {
        return Inertia::render('Accounting/Manufacturing/LaborCategoryForm', ['category' => $category]);
    }

    public function store(LaborCategoryRequest $request)
    {
        $this->service->create($this->companyId($request), $request->validated(), $request->user());

        return redirect()->route('accounting.labor-categories.index')->with('success', 'Labor category created.');
    }

    public function update(LaborCategoryRequest $request, LaborCategory $category)
    {
        $this->service->update($category, $request->validated(), $request->user());

        return redirect()->route('accounting.labor-categories.index')->with('success', 'Labor category updated.');
    }
}
