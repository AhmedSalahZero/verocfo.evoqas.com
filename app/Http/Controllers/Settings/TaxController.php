<?php

namespace App\Http\Controllers\Settings;

// ══════════════════════════════════════════════════════════════════
//  TaxController
//
//  HTTP layer for Tax Settings.
//  Routes:
//    GET    /settings/taxes                    → index()
//    POST   /settings/taxes/settings           → saveSettings()
//    POST   /settings/taxes/overrides          → saveOverride()
//    DELETE /settings/taxes/overrides/{id}     → deleteOverride()
//
//  @layer    Controller
//  @depends  TaxService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\ProductCategory;
use App\Models\Product;
use App\Services\TaxService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaxController extends Controller
{
    public function __construct(private TaxService $service) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Settings/Taxes/Index', [
            'settings'   => $this->service->getOrCreateSettings($companyId),
            'overrides'  => $this->service->overrides($companyId),
            'categories' => ProductCategory::where('company_id', $companyId)
                                ->active()->orderBy('name_en')
                                ->get(['id', 'name_en', 'name_ar', 'level', 'parent_id']),
            'products'   => Product::where('company_id', $companyId)
                                ->active()->orderBy('name_en')
                                ->get(['id', 'code', 'name_en', 'category_id']),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $v = $request->validate([
            'vat_rate'       => ['required', 'numeric', 'min:0', 'max:100'],
            'debit_wht_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $this->service->saveSettings($this->companyId($request), $v, $request->user());
        return back()->with('success', 'Tax settings saved.');
    }

    public function saveOverride(Request $request)
    {
        $v = $request->validate([
            'category_id'    => ['nullable', 'integer', 'exists:product_categories,id'],
            'product_id'     => ['nullable', 'integer', 'exists:products,id'],
            'vat_rate'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'debit_wht_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes'          => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->service->saveOverride($this->companyId($request), $v, $request->user());
            return back()->with('success', 'Tax override saved.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function deleteOverride(Request $request, int $id)
    {
        $this->service->deleteOverride($this->companyId($request), $id, $request->user());
        return back()->with('success', 'Override removed.');
    }
}