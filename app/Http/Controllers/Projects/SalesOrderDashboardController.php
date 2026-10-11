<?php

namespace App\Http\Controllers\Projects;

// ══════════════════════════════════════════════════════════════════
//  SalesOrderDashboardController
//
//  Projects & Contracts > Sales Orders Dashboard (September 2026,
//  per Mahmoud — "same design and colors as the Accounting
//  Dashboard"). One single scrolling page: backlog overview,
//  status mix, invoicing progress, and full-drill-down Top Sales
//  Orders / Top Customers tables.
//
//  Zero business logic here — SalesOrderDashboardService does every
//  calculation.
//
//  Routes (under auth + active.company):
//    GET /projects/sales-orders/dashboard → index()
//
//  @layer    Controller
//  @depends  SalesOrderDashboardService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Services\Projects\SalesOrderDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalesOrderDashboardController extends Controller
{
    public function __construct(private SalesOrderDashboardService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Projects/SalesOrders/Dashboard', [
            'data' => $this->service->build($companyId),
            'urls' => [
                'salesOrdersIndex' => route('projects.sales-orders.index'),
            ],
        ]);
    }
}
