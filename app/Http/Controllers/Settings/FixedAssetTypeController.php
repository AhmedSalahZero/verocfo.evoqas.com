<?php

namespace App\Http\Controllers\Settings;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetTypeController
//
//  HTTP layer for Fixed Asset Types — a dedicated, simplified page,
//  separate from Products & Services (confirmed with Mahmoud after
//  the combined-form design proved confusing). Zero business logic —
//  delegated to ProductService's Fixed Asset Type methods. Under the
//  hood this still manages real Product rows (nature = 'fixed_asset')
//  — the whole PO → GRN → Bill → FixedAssetService pipeline is
//  already built around that — but this controller never exposes
//  category/UOM/usage_types to the user; ProductService auto-resolves
//  those behind the scenes.
//
//  Routes:
//    GET    /settings/fixed-asset-types               → index()
//    GET    /settings/fixed-asset-types/create        → create()
//    POST   /settings/fixed-asset-types                → store()
//    GET    /settings/fixed-asset-types/{product}/edit → edit()
//    PUT    /settings/fixed-asset-types/{product}      → update()
//    PATCH  /settings/fixed-asset-types/{product}/toggle → toggleActive()
//
//  AUDIT M2 FIX (Sept 2026): edit / update / toggle now answer
//  "404 Not Found" unless the product in the web address really is a
//  Fixed Asset Type (nature = fixed_asset). Before, any product id
//  could be opened here and saving silently converted it into a
//  fixed asset. ProductService::updateFixedAssetType() re-checks.
//
//  @layer    Controller
//  @depends  ProductService, Product, FixedAssetTypeRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\FixedAssetTypeRequest;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FixedAssetTypeController extends Controller
{
    public function __construct(private ProductService $service) {}

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        return Inertia::render('Settings/FixedAssetTypes/Index', [
            'fixedAssetTypes' => $this->service->paginateFixedAssetTypes($companyId, $request->only('search', 'status')),
            'filters'         => $request->only('search', 'status'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Settings/FixedAssetTypes/Form', [
            'fixedAssetType'     => null,
            'fixedAssetAccounts' => $this->fixedAssetAccountsForDropdown($this->companyId($request)),
        ]);
    }

    public function store(FixedAssetTypeRequest $request)
    {
        $type = $this->service->createFixedAssetType(
            $this->companyId($request),
            $request->validated(),
            $request->user()
        );
        return redirect()->route('settings.fixed-asset-types.index')
            ->with('success', "Fixed Asset Type [{$type->code}] created successfully.");
    }

    public function edit(Request $request, Product $product): Response
    {
        $this->ensureFixedAssetType($product);
        return Inertia::render('Settings/FixedAssetTypes/Form', [
            'fixedAssetType'     => $product->load('fixedAssetAccount:id,code,name_en'),
            'fixedAssetAccounts' => $this->fixedAssetAccountsForDropdown($this->companyId($request)),
        ]);
    }

    public function update(FixedAssetTypeRequest $request, Product $product)
    {
        $this->ensureFixedAssetType($product);
        $this->service->updateFixedAssetType($product, $request->validated(), $request->user());
        return redirect()->route('settings.fixed-asset-types.index')
            ->with('success', 'Fixed Asset Type updated successfully.');
    }

    public function toggleActive(Request $request, Product $product)
    {
        $this->ensureFixedAssetType($product);
        $this->service->toggleActive($product, $request->user());
        return back()->with('success', 'Fixed Asset Type status updated.');
    }

    /** 404 unless the product is a Fixed Asset Type (Audit M2). */
    private function ensureFixedAssetType(Product $product): void
    {
        abort_unless($product->nature === Product::NATURE_FIXED_ASSET, 404);
    }

    /**
     * The six postable 111xxx category accounts — the only account
     * the user picks directly (its 113xxx counterpart is auto-derived,
     * see ProductService::resolveAccounts()).
     */
    private function fixedAssetAccountsForDropdown(int $companyId): array
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->where('code', 'like', '111%')
            ->where('code', '!=', '111')
            ->where('is_postable', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar'])
            ->toArray();
    }
}
