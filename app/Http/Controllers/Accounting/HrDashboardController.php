<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  HrDashboardController
//
//  HR > Dashboard (September 2026, per Mahmoud — "same pattern" as
//  the Accounting and Sales Orders dashboards). One single scrolling
//  page: payroll cost overview, category mix, top employees by
//  gross pay, and an "as of today" balances section (EOSB provision,
//  tax/social-insurance remittance, outstanding advances & custody).
//
//  Zero business logic here — HrDashboardService does every
//  calculation.
//
//  Routes (under auth + active.company):
//    GET /hr/dashboard → index()
//
//  @layer    Controller
//  @depends  HrDashboardService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Services\Accounting\HrDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HrDashboardController extends Controller
{
    public function __construct(private HrDashboardService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/Payroll/Dashboard', [
            'data' => $this->service->build($companyId),
            'urls' => [
                'employeesIndex' => route('hr.employees.index'),
                'payrollIndex'   => route('hr.payroll.index'),
                'eosbIndex'      => route('hr.eosb-provision.index'),
                'remittanceIndex'=> route('hr.government-remittance.index'),
                'advancesIndex'  => route('hr.advances.index'),
            ],
        ]);
    }
}
