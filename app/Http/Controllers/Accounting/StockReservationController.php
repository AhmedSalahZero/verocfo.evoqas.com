<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  StockReservationController
//
//  Powers the "Stock Reservations" screen — warehouse-facing, kept
//  entirely separate from the Sales Order form itself (confirmed
//  scope, July 2026). Two halves on one page: reserve a line from an
//  activated Sales Order that has none yet, and release an already-
//  active reservation once its line is fully delivered.
//
//  Zero business logic here — delegated to StockReservationService,
//  same pattern as every other controller in this app.
//
//  Routes (under auth + active.company, gated by Company::hasInventory()):
//    GET   /accounting/stock-reservations              → index()
//    POST  /accounting/stock-reservations               → store() (reserve one line)
//    PATCH /accounting/stock-reservations/{id}/release   → release()
//
//  @layer    Controller
//  @depends  StockReservationService, SalesOrderLine, StockReservation
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\SalesOrderLine;
use App\Models\StockReservation;
use App\Services\Inventory\StockReservationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockReservationController extends Controller
{
    public function __construct(private StockReservationService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Inventory/StockReservations/Index', [
            'eligibleSalesOrders' => $this->service->eligibleSalesOrders($companyId),
            'activeReservations'  => $this->service->paginateActive($companyId, $request->only('search')),
            'filters'             => $request->only('search'),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate(['sales_order_line_id' => ['required', 'integer', 'exists:sales_order_lines,id']]);

        $companyId = $this->companyId($request);
        $line = SalesOrderLine::where('company_id', $companyId)->findOrFail($request->integer('sales_order_line_id'));

        try {
            $this->service->reserve($companyId, $line, $request->user());
            return back()->with('success', 'Stock reserved for this order line.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function release(Request $request, StockReservation $stockReservation)
    {
        $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        try {
            $this->service->release($stockReservation, $request->user(), $request->string('notes')->toString() ?: null);
            return back()->with('success', 'Reservation released.');
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
