<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  AccountingDashboardController
//
//  Accounting > Dashboard — replaces the placeholder stub route
//  (`return inertia('Dashboard')`) that stood in for this since the
//  routes file was first laid out. One single scrolling page, per
//  Mahmoud (September 2026): P&L snapshot, AR/AP with aging and a
//  full-drill-down partner table, Cash & Bank position, and (for
//  inventory-carrying companies) total inventory value — built to
//  match the Financial Institutions Dashboard's visual language.
//
//  Zero business logic here — AccountingDashboardService does every
//  calculation, reusing IncomeStatementService / CustomerBalanceService
//  / SupplierBalanceService / LedgerStatementService / StockLedgerService
//  directly rather than recomputing any of their numbers.
//
//  Routes (under auth + active.company):
//    GET /accounting/dashboard → index()
//
//  @layer    Controller
//  @depends  AccountingDashboardService, Company
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Accounting\AccountingDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AccountingDashboardController extends Controller
{
    public function __construct(private AccountingDashboardService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $company = Company::findOrFail($companyId);

        return Inertia::render('Accounting/Dashboard/Index', [
            'data' => $this->service->build($companyId, $company),
            'urls' => [
                'customerBalances' => route('accounting.customer-balances.index'),
                'supplierBalances' => route('accounting.supplier-balances.index'),
                'incomeStatement'  => route('accounting.pl.index'),
                'trialBalance'     => route('accounting.trial-balance.index'),
                'fiDashboard'      => route('fi.dashboard'),
            ],
        ]);
    }
}
