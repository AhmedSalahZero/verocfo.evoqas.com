<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  GoodsReceiptNoteController
//
//  HTTP layer for Goods Receipt Notes. Zero business logic —
//  delegated to GoodsReceiptNoteService. Mirrors
//  PurchaseOrderController's pattern closely (same pillar,
//  Company::hasInventory() gate).
//
//  eligiblePoLines() is a live JSON endpoint the Form page calls
//  when the user picks a Purchase Order — returns only that PO's
//  lines with remaining receivable quantity > 0 (maxReceivableQuantity()),
//  so the form never lets a user key in a line the tolerance would
//  reject anyway (the service re-validates it regardless — this is
//  just to keep the form honest, not the source of truth).
//
//  Routes (under auth + active.company):
//    GET    /accounting/goods-receipt-notes                      → index()
//    GET    /accounting/goods-receipt-notes/create               → create()
//    POST   /accounting/goods-receipt-notes                      → store()
//    GET    /accounting/goods-receipt-notes/{grn}/edit           → edit()
//    PUT    /accounting/goods-receipt-notes/{grn}                → update()
//    DELETE /accounting/goods-receipt-notes/{grn}                → destroy()
//    PATCH  /accounting/goods-receipt-notes/{grn}/post           → post()
//    PATCH  /accounting/goods-receipt-notes/{grn}/cancel         → cancel()
//    PATCH  /accounting/goods-receipt-notes/{grn}/set-to-draft   → setToDraft()
//    GET    /accounting/goods-receipt-notes/eligible-po-lines    → eligiblePoLines() (JSON)
//    GET    /accounting/goods-receipt-notes/eligible-landing-costs → eligibleLandingCosts() (JSON)
//
//  @layer    Controller
//  @depends  GoodsReceiptNoteService, GoodsReceiptNote, GoodsReceiptNoteRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\GoodsReceiptNoteRequest;
use App\Models\Company;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryLocation;
use App\Models\Partner;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\UnitOfMeasure;
use App\Services\Accounting\GoodsReceiptNoteService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GoodsReceiptNoteController extends Controller
{
    public function __construct(
        private GoodsReceiptNoteService $service,
        private \App\Services\Accounting\LandingCostService $landingCostService,
    ) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/GoodsReceiptNotes/Index', [
            'goodsReceiptNotes' => $this->service->paginate($this->companyId($request), $request->only('search', 'status', 'partner_id', 'purchase_order_id')),
            'filters'           => $request->only('search', 'status', 'partner_id', 'purchase_order_id'),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/GoodsReceiptNotes/Form', [
            'goodsReceiptNote' => null,
            'grnPolicy'        => Company::withoutGlobalScopes()->find($companyId)?->grn_policy,
            'suppliers'        => Partner::ofType('supplier')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'purchaseOrders'   => PurchaseOrder::active()->where('receipt_status', '!=', 'fully_received')
                ->orderByDesc('created_at')->get(['id', 'po_code', 'partner_id', 'currency_id', 'exchange_rate']),
            'inventoryLocations' => InventoryLocation::active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar', 'type']),
            'products'         => Product::active()->withStock()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar', 'primary_uom_id', 'inventory_account_id']),
            'uoms'             => UnitOfMeasure::forCompany($companyId)->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'projects'         => \App\Models\Project::active()->orderBy('name_en')->get(['id', 'contract_code', 'name_en']),
        ]);
    }

    public function store(GoodsReceiptNoteRequest $request)
    {
        $data = $request->validated();
        try {
            $grn = $this->service->create($this->companyId($request), $data, $request->user());
            return redirect()->route('accounting.goods-receipt-notes.index')->with('success', "Goods Receipt Note [{$grn->grn_code}] saved as draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(Request $request, GoodsReceiptNote $goodsReceiptNote)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/GoodsReceiptNotes/Form', [
            'goodsReceiptNote' => $goodsReceiptNote->load([
                'partner:id,name_en', 'purchaseOrder:id,po_code,currency_id,exchange_rate',
                'inventoryLocation:id,name_en', 'lines.product:id,name_en', 'lines.uom:id,code',
                'journalEntry.lines.chartOfAccount:id,code,name_en',
                'landingCostSelections.landingCost.chartOfAccount:id,code,name_en',
                'landingCostSelections.landingCost.partner:id,name_en',
                'landingCosts.partner:id,name_en', 'landingCosts.chartOfAccount:id,code,name_en',
            ]),
            'grnPolicy'        => Company::withoutGlobalScopes()->find($companyId)?->grn_policy,
            'suppliers'        => Partner::ofType('supplier')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'purchaseOrders'   => PurchaseOrder::active()->where('receipt_status', '!=', 'fully_received')
                ->orderByDesc('created_at')->get(['id', 'po_code', 'partner_id', 'currency_id', 'exchange_rate']),
            'inventoryLocations' => InventoryLocation::active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar', 'type']),
            'products'         => Product::active()->withStock()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar', 'primary_uom_id', 'inventory_account_id']),
            'uoms'             => UnitOfMeasure::forCompany($companyId)->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'projects'         => \App\Models\Project::active()->orderBy('name_en')->get(['id', 'contract_code', 'name_en']),
        ]);
    }

    public function update(GoodsReceiptNoteRequest $request, GoodsReceiptNote $goodsReceiptNote)
    {
        $data = $request->validated();
        try {
            $this->service->update($this->companyId($request), $goodsReceiptNote, $data, $request->user());
            return redirect()->route('accounting.goods-receipt-notes.index')->with('success', 'Goods Receipt Note updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroy(Request $request, GoodsReceiptNote $goodsReceiptNote)
    {
        try {
            $this->service->delete($goodsReceiptNote, $request->user());
            return redirect()->route('accounting.goods-receipt-notes.index')->with('success', 'Draft Goods Receipt Note deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post(Request $request, GoodsReceiptNote $goodsReceiptNote)
    {
        try {
            $grn = $this->service->post($goodsReceiptNote, $request->user());
            return back()->with('success', "Goods Receipt Note [{$grn->grn_code}] posted — stock received and journal entry created.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — every unused Landing Cost, for the Post-time attachment picker. See LandingCost::scopeEligibleForAttachment(). */
    public function eligibleLandingCosts(Request $request)
    {
        $landingCosts = $this->landingCostService->eligibleForAttachment($this->companyId($request))
            ->map(fn ($lc) => [
                'id'                  => $lc->id,
                'landing_cost_code'   => $lc->landing_cost_code,
                'partner_name'        => $lc->partner->name_en,
                'chart_of_account_id' => $lc->chart_of_account_id,
                'account_label'       => "{$lc->chartOfAccount->code} — {$lc->chartOfAccount->name_en}",
                'amount'              => (string) $lc->amount,
                'cost_date'           => $lc->cost_date?->toDateString(),
            ]);

        return response()->json(['landing_costs' => $landingCosts]);
    }

    public function cancel(Request $request, GoodsReceiptNote $goodsReceiptNote)
    {
        $this->service->cancel($goodsReceiptNote, $request->user());
        return back()->with('success', "Goods Receipt Note [{$goodsReceiptNote->grn_code}] cancelled.");
    }

    /** Reopens a posted GRN back to draft — see GoodsReceiptNoteService::setToDraft() for the full rule set. */
    public function setToDraft(Request $request, GoodsReceiptNote $goodsReceiptNote)
    {
        try {
            // Audit H5: a written reason is mandatory and is recorded with the reopen.
            app(\App\Services\Accounting\DocumentReopenService::class)->withReason($goodsReceiptNote, $request->user(), (string) $request->input('reason'), fn () => $this->service->setToDraft($goodsReceiptNote, $request->user()));
            return redirect()->route('accounting.goods-receipt-notes.edit', $goodsReceiptNote)->with('success', "Goods Receipt Note [{$goodsReceiptNote->grn_code}] reopened to draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — eligible lines of one PO, with remaining receivable quantity. */
    public function eligiblePoLines(Request $request)
    {
        $purchaseOrderId = $request->integer('purchase_order_id');

        $lines = PurchaseOrderLine::where('purchase_order_id', $purchaseOrderId)
            ->with('product:id,name_en,name_ar,inventory_account_id', 'uom:id,code')
            ->get()
            ->filter(fn ($line) => bccomp($line->maxReceivableQuantity(), '0', 4) > 0)
            ->map(fn ($line) => [
                'id'                     => $line->id,
                'product_id'             => $line->product_id,
                'product_name'           => $line->product?->name_en,
                'description'            => $line->description,
                'ordered_quantity'       => (string) $line->quantity,
                'received_quantity'      => (string) $line->received_quantity,
                'max_receivable_quantity' => $line->maxReceivableQuantity(),
                'uom_id'                 => $line->uom_id,
                'unit_price'             => (string) $line->unit_price,
            ])
            ->values();

        return response()->json(['lines' => $lines]);
    }
}