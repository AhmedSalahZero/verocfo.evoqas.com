<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetController
//
//  HTTP layer for the Fixed Asset Register — Fixed Assets module,
//  Phase 1. Zero business logic — all delegated to FixedAssetService.
//  Assets themselves are only ever CREATED via Goods Receipt Note
//  posting (see GoodsReceiptNoteService::post() /
//  FixedAssetService::receiveFromGrnLine()) — there is no "create"
//  action here, only editing an already-received asset's own fields,
//  marking it in service, and running/reversing depreciation.
//
//  Routes:
//    GET    /accounting/fixed-assets                                  → index()
//    GET    /accounting/fixed-assets/run-depreciation                 → runDepreciationForm()
//    POST   /accounting/fixed-assets/run-depreciation                 → runDepreciation()
//    GET    /accounting/fixed-assets/{fixedAsset}/edit                → edit()
//    PUT    /accounting/fixed-assets/{fixedAsset}                     → update()
//    PATCH  /accounting/fixed-assets/{fixedAsset}/mark-in-service     → markInService()
//    PATCH  /accounting/fixed-assets/{fixedAsset}/post-depreciation   → postDepreciation()
//    DELETE /accounting/fixed-asset-depreciation-entries/{entry}      → reverseDepreciation()
//
//  @layer    Controller
//  @depends  FixedAssetService, FixedAsset, FixedAssetRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\FixedAssetRequest;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Services\Accounting\FixedAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class FixedAssetController extends Controller
{
    public function __construct(private FixedAssetService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/FixedAssets/Index', [
            'fixedAssets' => $this->service->paginate($companyId, $request->only('search', 'status', 'product_id')),
            'filters'     => $request->only('search', 'status', 'product_id'),
            'products'    => Product::where('nature', Product::NATURE_FIXED_ASSET)->active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
            'inventoryLocations' => InventoryLocation::orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    public function show(Request $request, FixedAsset $fixedAsset)
    {
        return response()->json(
            $fixedAsset->load([
                'product:id,name_en,name_ar,code',
                'fixedAssetAccount:id,code,name_en',
                'accumulatedDepreciationAccount:id,code,name_en',
                'currency:id,code',
                'costLines' => fn ($q) => $q->orderBy('id'),
                'depreciationEntries' => fn ($q) => $q->orderBy('period_date'),
            ])
        );
    }

    public function update(FixedAssetRequest $request, FixedAsset $fixedAsset)
    {
        $this->service->update($fixedAsset, $request->validated(), $request->user());

        return redirect()->route('accounting.fixed-assets.index')
            ->with('success', "Asset {$fixedAsset->asset_code} updated successfully.");
    }

    public function markInService(Request $request, FixedAsset $fixedAsset)
    {
        try {
            $this->service->markInService($fixedAsset, $request->user());
            return back()->with('success', "Asset {$fixedAsset->asset_code} marked in service — no further cost can be capitalized onto it.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Depreciation — single asset ─────────────────────────────────

    public function postDepreciation(Request $request, FixedAsset $fixedAsset)
    {
        $validated = $request->validate(['for_date' => ['nullable', 'date']]);

        try {
            $this->service->postMonthlyDepreciation(
                $fixedAsset,
                $request->user(),
                isset($validated['for_date']) ? Carbon::parse($validated['for_date']) : null
            );
            return back()->with('success', "Depreciation posted for {$fixedAsset->asset_code}.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reverseDepreciation(Request $request, FixedAssetDepreciationEntry $depreciationEntry)
    {
        try {
            $this->service->reverseDepreciationEntry($depreciationEntry, $request->user());
            return back()->with('success', 'Depreciation entry reversed successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    // ── Depreciation — bulk run ──────────────────────────────────────

    public function runDepreciation(Request $request)
    {
        $validated = $request->validate(['for_date' => ['required', 'date']]);

        $result = $this->service->runDepreciationForPeriod(
            $this->companyId($request),
            Carbon::parse($validated['for_date']),
            $request->user()
        );

        $postedCount = count($result['posted']);
        $skippedCount = count($result['skipped']);

        return back()->with('success', "Depreciation run complete — {$postedCount} asset(s) posted, {$skippedCount} skipped.");
    }
}
