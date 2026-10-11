<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetLifecycleController
//
//  Accounting › Fixed Assets › Manage (Audit H17): one asset's page,
//  where it can be sold or scrapped, impaired or moved, and where
//  each of those can be cancelled. Thin layer over
//  FixedAssetDisposalService (Rule 3).
//
//  @layer    Controller
//  @depends  FixedAssetDisposalService, FixedAsset, FixedAssetDisposal,
//            FixedAssetImpairment, ChartOfAccount, Partner,
//            InventoryLocation
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\FixedAssetImpairment;
use App\Models\InventoryLocation;
use App\Models\Partner;
use App\Services\Accounting\FixedAssetDisposalService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FixedAssetLifecycleController extends Controller
{
    public function __construct(private FixedAssetDisposalService $service) {}

    public function manage(Request $request, FixedAsset $fixedAsset)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/FixedAssets/Manage', [
            'asset'            => $this->service->history($companyId, $fixedAsset),
            'proceedsAccounts' => ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)->where('is_postable', true)
                ->where('class', ChartOfAccount::CLASS_ASSETS)->where('code', 'like', '1%')->where('code', 'not like', '11%')
                ->orderBy('code')->get(['id', 'code', 'name_en']),
            'buyers'           => Partner::active()->orderBy('name_en')->get(['id', 'code', 'name_en']),
            'locations'        => InventoryLocation::orderBy('name_en')->get(['id', 'code', 'name_en']),
        ]);
    }

    public function preview(Request $request, FixedAsset $fixedAsset)
    {
        $data = $request->validate(['disposal_date' => ['required', 'date']]);
        try {
            return response()->json($this->service->preview($this->companyId($request), $fixedAsset, $data['disposal_date']));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function dispose(Request $request, FixedAsset $fixedAsset)
    {
        $data = $request->validate([
            'disposal_date' => ['required', 'date'], 'disposal_type' => ['required', 'in:sale,scrap'],
            'proceeds' => ['nullable', 'numeric', 'min:0'], 'vat_amount' => ['nullable', 'numeric', 'min:0'],
            'proceeds_account_id' => ['nullable', 'integer'], 'partner_id' => ['nullable', 'integer', 'exists:partners,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attempt(fn () => $this->service->dispose($this->companyId($request), $fixedAsset, $data, $request->user()), "Asset {$fixedAsset->asset_code} disposed.");
    }

    public function cancelDisposal(Request $request, FixedAssetDisposal $fixedAssetDisposal)
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->cancelDisposal($this->companyId($request), $fixedAssetDisposal, $data['cancel_reason'], $request->user()), 'Disposal cancelled — the asset is back on the books.');
    }

    public function impair(Request $request, FixedAsset $fixedAsset)
    {
        $data = $request->validate([
            'impairment_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->attempt(fn () => $this->service->impair($this->companyId($request), $fixedAsset, $data, $request->user()), "Impairment posted for {$fixedAsset->asset_code}.");
    }

    public function cancelImpairment(Request $request, FixedAssetImpairment $fixedAssetImpairment)
    {
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:1000']]);

        return $this->attempt(fn () => $this->service->cancelImpairment($this->companyId($request), $fixedAssetImpairment, $data['cancel_reason'], $request->user()), 'Impairment cancelled.');
    }

    public function transfer(Request $request, FixedAsset $fixedAsset)
    {
        $data = $request->validate([
            'transfer_date' => ['required', 'date'], 'to_location_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->attempt(fn () => $this->service->transfer($this->companyId($request), $fixedAsset, $data, $request->user()), "Asset {$fixedAsset->asset_code} moved.");
    }

    private function attempt(callable $action, string $success)
    {
        try {
            $action();
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
