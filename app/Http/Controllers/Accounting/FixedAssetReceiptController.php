<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetReceiptController
//
//  HTTP layer for Fixed Asset Receipts — a dedicated, simplified page
//  under Accounting → Fixed Assets, separate from the general Goods
//  Receipt Notes screen (confirmed with Mahmoud — a Fixed Asset
//  receipt has no project/sales-order tagging or UOM choice to make,
//  so showing those fields alongside inventory-only concerns
//  confused the user). Zero NEW business logic — every lifecycle
//  action (create/update/delete/post/cancel/setToDraft) delegates
//  straight to the existing GoodsReceiptNoteService, since a Fixed
//  Asset Receipt IS a GoodsReceiptNote under the hood; only the
//  presentation layer here is different.
//
//  Routes:
//    GET    /accounting/fixed-asset-receipts                        → index()
//    GET    /accounting/fixed-asset-receipts/create                 → create()
//    POST   /accounting/fixed-asset-receipts                        → store()
//    GET    /accounting/fixed-asset-receipts/{goodsReceiptNote}/edit → edit()
//    PUT    /accounting/fixed-asset-receipts/{goodsReceiptNote}      → update()
//    DELETE /accounting/fixed-asset-receipts/{goodsReceiptNote}      → destroy()
//    PATCH  /accounting/fixed-asset-receipts/{goodsReceiptNote}/post → post()
//    PATCH  /accounting/fixed-asset-receipts/{goodsReceiptNote}/cancel → cancel()
//    PATCH  /accounting/fixed-asset-receipts/{goodsReceiptNote}/set-to-draft → setToDraft()
//    GET    /accounting/fixed-asset-receipts/eligible-po-lines       → eligiblePoLines() (JSON)
//
//  @layer    Controller
//  @depends  GoodsReceiptNoteService, GoodsReceiptNote, FixedAssetReceiptRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\FixedAssetReceiptRequest;
use App\Models\Company;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryLocation;
use App\Models\Partner;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\Accounting\GoodsReceiptNoteService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FixedAssetReceiptController extends Controller
{
    public function __construct(private GoodsReceiptNoteService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/FixedAssetReceipts/Index', [
            'goodsReceiptNotes' => $this->service->paginateFixedAssetReceipts($this->companyId($request), $request->only('search', 'status')),
            'filters'           => $request->only('search', 'status'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/FixedAssetReceipts/Form', [
            'goodsReceiptNote' => null,
            'grnPolicy'        => Company::withoutGlobalScopes()->find($companyId)?->grn_policy,
            'suppliers'        => Partner::ofType('supplier')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'purchaseOrders'   => $this->purchaseOrdersWithFixedAssetLines(),
            'inventoryLocations' => InventoryLocation::active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar', 'type']),
            'fixedAssetTypes'  => $this->fixedAssetTypesForDropdown(),
        ]);
    }

    public function store(FixedAssetReceiptRequest $request)
    {
        $data = $request->validated();
        try {
            $grn = $this->service->create($this->companyId($request), $data, $request->user());
            return redirect()->route('accounting.fixed-asset-receipts.index')->with('success', "Fixed Asset Receipt [{$grn->grn_code}] saved as draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(Request $request, GoodsReceiptNote $goodsReceiptNote)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/FixedAssetReceipts/Form', [
            'goodsReceiptNote' => $goodsReceiptNote->load([
                'partner:id,name_en', 'purchaseOrder:id,po_code,currency_id,exchange_rate',
                'inventoryLocation:id,name_en', 'lines.product:id,name_en', 'lines.uom:id,code',
                'journalEntry.lines.chartOfAccount:id,code,name_en',
            ]),
            'grnPolicy'        => Company::withoutGlobalScopes()->find($companyId)?->grn_policy,
            'suppliers'        => Partner::ofType('supplier')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'purchaseOrders'   => $this->purchaseOrdersWithFixedAssetLines(),
            'inventoryLocations' => InventoryLocation::active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar', 'type']),
            'fixedAssetTypes'  => $this->fixedAssetTypesForDropdown(),
        ]);
    }

    public function update(FixedAssetReceiptRequest $request, GoodsReceiptNote $goodsReceiptNote)
    {
        $data = $request->validated();
        try {
            $this->service->update($this->companyId($request), $goodsReceiptNote, $data, $request->user());
            return redirect()->route('accounting.fixed-asset-receipts.index')->with('success', 'Fixed Asset Receipt updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroy(Request $request, GoodsReceiptNote $goodsReceiptNote)
    {
        try {
            $this->service->delete($goodsReceiptNote, $request->user());
            return redirect()->route('accounting.fixed-asset-receipts.index')->with('success', 'Draft Fixed Asset Receipt deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post(Request $request, GoodsReceiptNote $goodsReceiptNote)
    {
        $grn = $this->service->post($goodsReceiptNote, $request->user());
        return back()->with('success', "Fixed Asset Receipt [{$grn->grn_code}] posted — assets registered and journal entry created.");
    }

    public function cancel(Request $request, GoodsReceiptNote $goodsReceiptNote)
    {
        $this->service->cancel($goodsReceiptNote, $request->user());
        return back()->with('success', "Fixed Asset Receipt [{$goodsReceiptNote->grn_code}] cancelled.");
    }

    /** Reopens a posted receipt back to draft — see GoodsReceiptNoteService::setToDraft() for the full rule set. */
    public function setToDraft(Request $request, GoodsReceiptNote $goodsReceiptNote)
    {
        try {
            $this->service->setToDraft($goodsReceiptNote, $request->user());
            return redirect()->route('accounting.fixed-asset-receipts.edit', $goodsReceiptNote)->with('success', "Fixed Asset Receipt [{$goodsReceiptNote->grn_code}] reopened to draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — eligible Fixed-Asset-only lines of one PO, with remaining receivable quantity. */
    public function eligiblePoLines(Request $request)
    {
        $purchaseOrderId = $request->integer('purchase_order_id');

        $lines = PurchaseOrderLine::where('purchase_order_id', $purchaseOrderId)
            ->whereHas('product', fn ($q) => $q->where('nature', Product::NATURE_FIXED_ASSET))
            ->with('product:id,name_en,name_ar,primary_uom_id', 'uom:id,code')
            ->get()
            ->filter(fn ($line) => bccomp($line->maxReceivableQuantity(), '0', 4) > 0)
            ->map(fn ($line) => [
                'id'                      => $line->id,
                'product_id'              => $line->product_id,
                'product_name'            => $line->product?->name_en,
                'description'             => $line->description,
                'ordered_quantity'        => (string) $line->quantity,
                'received_quantity'       => (string) $line->received_quantity,
                'max_receivable_quantity' => $line->maxReceivableQuantity(),
                'uom_id'                  => $line->product?->primary_uom_id,
                'unit_price'              => (string) $line->unit_price,
            ])
            ->values();

        return response()->json(['lines' => $lines]);
    }

    // ── Private helpers ───────────────────────────────────────────

    /** Only Fixed Asset Type products — never the general product catalogue. */
    private function fixedAssetTypesForDropdown()
    {
        return Product::active()
            ->where('nature', Product::NATURE_FIXED_ASSET)
            ->orderBy('name_en')
            ->get(['id', 'code', 'name_en', 'name_ar', 'primary_uom_id', 'fixed_asset_account_id']);
    }

    /** Only POs that actually have at least one Fixed Asset line — never an unrelated inventory PO. */
    private function purchaseOrdersWithFixedAssetLines()
    {
        return PurchaseOrder::active()
            ->where('receipt_status', '!=', 'fully_received')
            ->whereHas('lines.product', fn ($q) => $q->where('nature', Product::NATURE_FIXED_ASSET))
            ->orderByDesc('created_at')
            ->get(['id', 'po_code', 'partner_id', 'currency_id', 'exchange_rate']);
    }
}
