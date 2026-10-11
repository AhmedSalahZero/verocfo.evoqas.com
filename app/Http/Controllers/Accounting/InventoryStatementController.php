<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  InventoryStatementController
//
//  New report (July 2026 — Point 7 from the cross-audit discussion):
//  per-product Inventory Statement over a date range, quantity side
//  (Beginning Balance / In / Out / Ending Balance) and value side
//  (Beginning Balance / Debit / Credit / Ending Balance / WAC).
//
//  Nothing new is tracked to build this — every figure comes straight
//  from stock_movements via StockLedgerService::statement(). This
//  controller is just the read/render layer.
//
//  Routes (under auth + active.company):
//    GET  /accounting/inventory-statement                → index() (product picker, no result yet)
//    GET  /accounting/inventory-statement/run             → run() (JSON — product + date range selected)
//
//  @layer    Controller
//  @depends  StockLedgerService, Product
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Services\Inventory\StockLedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InventoryStatementController extends Controller
{
    public function __construct(private StockLedgerService $service) {}

    public function index(Request $request)
    {
        return Inertia::render('Accounting/Inventory/InventoryStatement', [
            // Master + Warehouse only — Project Site locations are
            // deliberately excluded here. Project-tagged inventory has
            // its own dedicated report under Projects & Contracts
            // (Project Inventory), tracked at actual purchase cost,
            // not WAC — a different question this report isn't for.
            'locations' => InventoryLocation::active()
                ->whereIn('type', [InventoryLocation::TYPE_MASTER, InventoryLocation::TYPE_WAREHOUSE])
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_ar', 'type']),
        ]);
    }

    /**
     * Live search endpoint behind the Product SearchableSelect —
     * matches on code OR name (English or Arabic), capped at 20
     * results. Deliberately does NOT load every product into the
     * page up front — with companies carrying thousands of SKUs, a
     * plain dropdown or a "load everything, filter in the browser"
     * approach doesn't scale.
     */
    public function searchProducts(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $products = Product::active()
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
     * Live lookup — the Inventory Statement ledger itself: one row
     * per transaction, running balance, Transaction/Journal
     * references. Optionally scoped to one Inventory Location; null
     * (or omitted) means combined across every location.
     */
    public function ledger(Request $request)
    {
        $request->validate([
            'product_id'  => ['required', 'integer', 'exists:products,id'],
            'date_from'   => ['required', 'date'],
            'date_to'     => ['required', 'date', 'after_or_equal:date_from'],
            'location_id' => ['nullable', 'integer', 'exists:inventory_locations,id'],
            'page'        => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->service->ledger(
            $this->companyId($request),
            $request->integer('product_id'),
            $request->string('date_from'),
            $request->string('date_to'),
            $request->filled('location_id') ? $request->integer('location_id') : null,
            $request->integer('page', 1),
        );

        return response()->json($result);
    }

    // ── Category Value Statement (new report, July 2026) ────────────

    public function categoryValueIndex(Request $request)
    {
        return Inertia::render('Accounting/Inventory/CategoryValueStatement', []);
    }

    public function categoryValueRun(Request $request)
    {
        $request->validate([
            'date_from' => ['required', 'date'],
            'date_to'   => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $rows = $this->service->categoryValueStatement(
            $this->companyId($request),
            $request->string('date_from'),
            $request->string('date_to'),
        );

        return response()->json(['categories' => $rows]);
    }
}
