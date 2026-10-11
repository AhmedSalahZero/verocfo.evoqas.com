<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  StockTransferController
//
//  Powers the "Internal Transfers" sidebar item (route
//  accounting.transfers.index, gated by Company::hasInventory()) —
//  replaces the placeholder closure that used to render the bare
//  Dashboard.
//
//  A transfer is create-only, like every other financial write in
//  this app (Rule: can_reverse replaces can_delete) — there is no
//  edit/update here. To correct a wrong transfer, record an
//  offsetting transfer back, same as you would with any posted
//  transaction.
//
//  2026-09 — optional Project / Sales Order tag added (see
//  StockTransferRequest and StockLedgerService::transfer() headers).
//  The dropdown offers standalone Sales Orders separately from
//  Projects (a Project-linked SO is reached via its parent Project's
//  own tag, not offered twice).
//
//  NOTE: the index list currently shows each leg of a transfer
//  (transfer_out / transfer_in) as its own ledger row rather than a
//  single paired "From A → To B" line — StockMovement has no link
//  between the two rows yet. Flagged, not hidden: if a combined view
//  is wanted, a small related_movement_id column would do it.
//
//  @layer    Controller
//  @depends  StockLedgerService, StockTransferRequest
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StockTransferRequest;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Project;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockTransferController extends Controller
{
    public function __construct(private StockLedgerService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Inventory/StockTransferIndex', [
            'movements' => $this->service->paginateMovements(
                $companyId,
                [StockMovement::TYPE_TRANSFER_OUT, StockMovement::TYPE_TRANSFER_IN],
                $request->only('product_id', 'inventory_location_id')
            ),
            'filters'   => $request->only('product_id', 'inventory_location_id'),
            'products'  => Product::where('company_id', $companyId)->withStock()->forDropdown()->get(),
            'locations' => InventoryLocation::where('company_id', $companyId)->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
        ]);
    }

    public function create(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Inventory/StockTransferForm', [
            'locations' => InventoryLocation::where('company_id', $companyId)->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'projects'  => Project::where('company_id', $companyId)->active()->orderBy('name_en')->get(['id', 'contract_code', 'name_en', 'name_ar']),
            // Standalone only (no parent project) — a Sales Order that
            // belongs to a Project is reached through that Project's own
            // tag instead, so it isn't offered twice here.
            'salesOrders' => SalesOrder::where('company_id', $companyId)->standalone()->active()->orderBy('so_code')->get(['id', 'so_code', 'subject']),
        ]);
    }

    /**
     * Live search behind the Product SearchableSelect — same reasoning
     * as InventoryStatementController::searchProducts(): never loads
     * every product up front, matches on code or name, capped results.
     */
    public function searchProducts(Request $request)
    {
        $companyId = $this->companyId($request);
        $q = trim((string) $request->query('q', ''));

        $products = Product::where('company_id', $companyId)
            ->withStock()
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($q) {
                $query->where('code', 'like', "%{$q}%")
                    ->orWhere('name_en', 'like', "%{$q}%")
                    ->orWhere('name_ar', 'like', "%{$q}%");
            }))
            ->orderBy('name_en')
            ->limit(20)
            ->get(['id', 'code', 'name_en', 'name_ar']);

        return response()->json([
            'results' => $products->map(fn ($p) => [
                'id'    => $p->id,
                'label' => "{$p->code} — {$p->name_en}",
            ]),
        ]);
    }

    /**
     * Live lookup — the product's unit (always returned) and, when a
     * location is given, that location's current quantity on hand.
     * Backs three things on the form at once: the UOM shown beside
     * the Quantity field, and the "available here" figure shown once
     * From Location or To Location is picked.
     */
    public function availability(Request $request)
    {
        $companyId = $this->companyId($request);

        $product = Product::where('company_id', $companyId)
            ->with('primaryUom:id,code,name_en')
            ->findOrFail($request->integer('product_id'));

        $quantity = null;
        if ($request->filled('location_id')) {
            $quantity = \App\Models\ProductLocationStock::where('company_id', $companyId)
                ->where('product_id', $product->id)
                ->where('inventory_location_id', $request->integer('location_id'))
                ->value('quantity_on_hand') ?? '0.0000';
        }

        return response()->json([
            'uom_code'         => $product->primaryUom?->code,
            'quantity_on_hand' => $quantity,
        ]);
    }

    public function store(StockTransferRequest $request)
    {
        $companyId = $this->companyId($request);
        $data      = $request->validated();
        $product   = Product::where('company_id', $companyId)->findOrFail($data['product_id']);

        $this->service->transfer(
            $companyId, $product, $data['from_location_id'], $data['to_location_id'],
            (string) $data['quantity'], $request->user(),
            [
                'movement_date'  => $data['movement_date'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'project_id'     => $data['project_id'] ?? null,
                'sales_order_id' => $data['sales_order_id'] ?? null,
            ]
        );

        return redirect()->route('accounting.transfers.index')->with('success', 'Stock transferred successfully.');
    }
}
