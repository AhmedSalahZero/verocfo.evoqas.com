<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  DeliveryNoteController
//
//  HTTP layer for Delivery Notes. Zero business logic — delegated to
//  DeliveryNoteService. Mirrors CustomerInvoiceController's shape.
//
//  eligibleSoLines() — only returns physical-goods lines
//  (Product::has_stock) with something left to deliver
//  (quantity - delivered_quantity > 0) — a pure service SO shows
//  nothing here, by design (design doc Section 10).
//
//  Routes (under auth + active.company):
//    GET    /accounting/delivery-notes                        → index()
//    GET    /accounting/delivery-notes/create                  → create()
//    POST   /accounting/delivery-notes                         → store()
//    GET    /accounting/delivery-notes/{deliveryNote}/edit      → edit()
//    PUT    /accounting/delivery-notes/{deliveryNote}           → update()
//    DELETE /accounting/delivery-notes/{deliveryNote}           → destroy()
//    PATCH  /accounting/delivery-notes/{deliveryNote}/post      → post()
//    PATCH  /accounting/delivery-notes/{deliveryNote}/cancel    → cancel()
//    PATCH  /accounting/delivery-notes/{deliveryNote}/set-to-draft → setToDraft()
//    GET    /accounting/delivery-notes/eligible-so-lines        → eligibleSoLines() (JSON)
//    GET    /accounting/delivery-notes/eligible-sales-orders    → eligibleSalesOrders() (JSON)
//
//  @layer    Controller
//  @depends  DeliveryNoteService, DeliveryNote, DeliveryNoteRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\DeliveryNoteRequest;
use App\Models\DeliveryNote;
use App\Models\InventoryLocation;
use App\Models\Partner;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Services\Accounting\DeliveryNoteService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeliveryNoteController extends Controller
{
    public function __construct(private DeliveryNoteService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/DeliveryNotes/Index', [
            'deliveryNotes' => $this->service->paginate($this->companyId($request), $request->only('search', 'status', 'partner_id', 'sales_order_id')),
            'filters'       => $request->only('search', 'status', 'partner_id', 'sales_order_id'),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Accounting/DeliveryNotes/Form', [
            'deliveryNote' => null,
            ...$this->formProps($request),
        ]);
    }

    public function store(DeliveryNoteRequest $request)
    {
        try {
            $delivery = $this->service->create($this->companyId($request), $request->validated(), $request->user());
            return redirect()->route('accounting.delivery-notes.index')->with('success', "Delivery Note [{$delivery->delivery_code}] saved as draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function edit(Request $request, DeliveryNote $deliveryNote)
    {
        return Inertia::render('Accounting/DeliveryNotes/Form', [
            'deliveryNote' => $deliveryNote->load([
                'partner:id,name_en', 'salesOrder:id,so_code',
                'lines.location:id,name_en',
                'lines.product:id,name_en', 'lines.uom:id,code',
                'lines.salesOrderLine:id,description,quantity,delivered_quantity,unit_price',
                'inventoryJournalEntry:id,entry_number', 'revenueJournalEntry:id,entry_number',
            ]),
            ...$this->formProps($request),
        ]);
    }

    public function update(DeliveryNoteRequest $request, DeliveryNote $deliveryNote)
    {
        try {
            $this->service->update($this->companyId($request), $deliveryNote, $request->validated(), $request->user());
            return redirect()->route('accounting.delivery-notes.index')->with('success', 'Delivery Note updated successfully.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }
    }

    public function destroy(Request $request, DeliveryNote $deliveryNote)
    {
        try {
            $this->service->delete($deliveryNote, $request->user());
            return redirect()->route('accounting.delivery-notes.index')->with('success', 'Draft Delivery Note deleted.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function post(Request $request, DeliveryNote $deliveryNote)
    {
        try {
            $delivery = $this->service->post($deliveryNote, $request->user());
            return back()->with('success', "Delivery Note [{$delivery->delivery_code}] posted — Inventory and Revenue recognized.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, DeliveryNote $deliveryNote)
    {
        try {
            $this->service->cancel($deliveryNote, $request->user());
            return back()->with('success', "Delivery Note [{$deliveryNote->delivery_code}] cancelled.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Reopens a posted Delivery Note back to draft — see DeliveryNoteService::setToDraft() for the full rule set. */
    public function setToDraft(Request $request, DeliveryNote $deliveryNote)
    {
        try {
            // Audit H5: a written reason is mandatory and is recorded with the reopen.
            app(\App\Services\Accounting\DocumentReopenService::class)->withReason($deliveryNote, $request->user(), (string) $request->input('reason'), fn () => $this->service->setToDraft($deliveryNote, $request->user()));
            return redirect()->route('accounting.delivery-notes.edit', $deliveryNote)->with('success', "Delivery Note [{$deliveryNote->delivery_code}] reopened to draft.");
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /** Live lookup — physical-goods SO lines only, with what's left to deliver. */
    public function eligibleSoLines(Request $request)
    {
        $soId = $request->integer('sales_order_id');

        $lines = SalesOrderLine::where('sales_order_id', $soId)
            ->whereHas('product', fn ($q) => $q->where('has_stock', true))
            ->with('product:id,name_en,name_ar,primary_uom_id,cogs_account_id,inventory_account_id')
            ->get()
            ->map(fn ($line) => [
                'id'                    => $line->id,
                'description'           => $line->description,
                'product_id'            => $line->product_id,
                'product_name'          => $line->product?->name_en,
                'quantity'              => (string) $line->quantity,
                'delivered_quantity'    => (string) $line->delivered_quantity,
                'remaining_quantity'    => bcsub((string) $line->quantity, (string) $line->delivered_quantity, 4),
                'unit_price'            => (string) $line->unit_price,
                'uom_id'                => $line->uom_id ?? $line->product?->primary_uom_id,
            ])
            ->filter(fn ($line) => bccomp($line['remaining_quantity'], '0', 4) > 0)
            ->values();

        return response()->json(['lines' => $lines]);
    }

    /** Live lookup — active Sales Orders (for a customer) that have at least one physical-goods line. */
    public function eligibleSalesOrders(Request $request)
    {
        $companyId = $this->companyId($request);
        $partnerId = $request->integer('partner_id');

        $orders = SalesOrder::where('company_id', $companyId)
            ->where('status', 'active')
            ->when($partnerId, fn ($q) => $q->where('partner_id', $partnerId))
            ->whereHas('lines.product', fn ($q) => $q->where('has_stock', true))
            ->orderByDesc('created_at')
            ->get(['id', 'so_code', 'partner_id']);

        return response()->json(['orders' => $orders]);
    }

    /**
     * Live lookup — on-hand quantity for a product at a specific
     * location, for the per-line Shipping From picker (Mahmoud: show
     * the available quantity as soon as a location is picked, so the
     * user knows before saving whether there's enough).
     */
    public function locationStock(Request $request)
    {
        $companyId = $this->companyId($request);

        $onHand = \App\Models\ProductLocationStock::where('company_id', $companyId)
            ->where('product_id', $request->integer('product_id'))
            ->where('inventory_location_id', $request->integer('location_id'))
            ->value('quantity_on_hand') ?? '0.0000';

        return response()->json(['quantity_on_hand' => (string) $onHand]);
    }

    // ── Private ────────────────────────────────────────────────────

    private function formProps(Request $request): array
    {
        return [
            'customers' => Partner::ofType('customer')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'locations' => InventoryLocation::active()->orderBy('name_en')->get(['id', 'name_en', 'name_ar']),
        ];
    }
}
