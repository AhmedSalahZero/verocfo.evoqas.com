<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  StockBalanceController
//
//  Read-only. Powers the "Inventory" sidebar item (route
//  accounting.inventory.index, already gated by
//  Company::hasInventory() in HandleInertiaRequests) — replaces the
//  placeholder closure that used to render the bare Dashboard.
//
//  Shows the current company-wide WAC + quantity for every product,
//  per ProductStockBalance (the Layer 1 authoritative balance).
//  locationBreakdown() is a small JSON endpoint used to expand a row
//  and show the per-location quantity split without a full page load.
//
//  MFG-003 fix (confirmed with Mahmoud, 2026-09) — ProductStockBalance
//  never includes a Job Order's own segment stock (by design), which
//  used to make this page disagree with the Accounting Dashboard.
//  jobOrderStockByProduct is passed alongside the pooled balances so
//  the page can show that missing piece explicitly rather than hide
//  it, and locationBreakdown() now also returns the matching Job
//  Order breakdown for a row's expand detail.
//
//  No Form Request — nothing here writes anything.
//
//  @layer    Controller
//  @depends  StockLedgerService, JobOrderStockBalanceService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Services\Accounting\JobOrderStockBalanceService;
use App\Services\Inventory\StockLedgerService;
use App\Services\Inventory\StockReservationService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StockBalanceController extends Controller
{
    public function __construct(
        private StockLedgerService $service,
        private StockReservationService $reservationService,
        private JobOrderStockBalanceService $jobOrderStockBalances,
    ) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Inventory/StockBalanceIndex', [
            'balances' => $this->service->paginateBalances($companyId, $request->only('search')),
            'filters'  => $request->only('search'),
            // Reserved (July 2026) — company-wide, general reservations
            // only (see StockReservationService). Keyed by product_id
            // so the page can show Reserved / Available per row without
            // an extra request per row.
            'reservedByProduct' => $this->reservationService->reservedQuantitiesByProduct($companyId),
            // Job Order segment stock (MFG-003 fix, 2026-09) — keyed by
            // product_id, ['quantity' => ..., 'value' => ...]. Never
            // part of ProductStockBalance by design; shown separately
            // so the page's totals reconcile with the Accounting
            // Dashboard without hiding which stock is general vs
            // reserved for a specific Job Order.
            'jobOrderStockByProduct' => $this->jobOrderStockBalances->totalsByProduct($companyId),
        ]);
    }

    public function locationBreakdown(Request $request, int $product)
    {
        $companyId = $this->companyId($request);

        return response()->json([
            'locations' => $this->service->locationBreakdown($companyId, $product),
            'jobOrders' => $this->jobOrderStockBalances->breakdownForProduct($companyId, $product),
        ]);
    }
}
