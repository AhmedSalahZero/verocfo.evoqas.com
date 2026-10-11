<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SupplierBalanceController
//
//  Payable-side mirror of CustomerBalanceController. Read-only
//  throughout — zero business logic, delegated to
//  SupplierBalanceService.
//
//  Routes (under auth + active.company):
//    GET  /accounting/supplier-balances            → index()
//    GET  /accounting/supplier-balances/invoices    → invoiceReport()
//    GET  /accounting/supplier-balances/statement   → statement()
//
//  @layer    Controller
//  @depends  SupplierBalanceService, Partner, CurrencyService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Services\Accounting\SupplierBalanceService;
use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierBalanceController extends Controller
{
    public function __construct(
        private SupplierBalanceService $service,
        private CurrencyService $currencyService,
    ) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $currencyId = $request->filled('currency_id') ? $request->integer('currency_id') : null;
        $duesFilter = $request->input('dues', 'all');

        return Inertia::render('Accounting/SupplierBalances/Index', [
            'tabs'      => $this->service->currencyTabs($companyId),
            'suppliers' => $this->service->suppliersTable($companyId, $currencyId, $duesFilter, $request->input('search')),
            'filters'   => [
                'currency_id' => $currencyId,
                'dues'        => $duesFilter,
                'search'      => $request->input('search'),
            ],
        ]);
    }

    public function invoiceReport(Request $request)
    {
        $companyId = $this->companyId($request);
        $partnerId = $request->integer('partner_id');
        $currencyId = $request->integer('currency_id');

        $partner = Partner::findOrFail($partnerId);
        $currency = $this->currencyService->activeCurrenciesOrdered($companyId)->firstWhere('id', $currencyId);

        return Inertia::render('Accounting/SupplierBalances/InvoiceReport', [
            'partner'  => $partner->only(['id', 'code', 'name_en', 'name_ar']),
            'currency' => $currency,
            'bills'    => $this->service->invoicesReport($companyId, $partnerId, $currencyId),
        ]);
    }

    public function statement(Request $request)
    {
        $companyId = $this->companyId($request);
        $partnerId = $request->integer('partner_id') ?: null;
        $currencyId = $request->filled('currency_id') ? $request->integer('currency_id') : null;
        $startDate = $request->input('start_date') ?: now()->startOfYear()->toDateString();
        $endDate = $request->input('end_date') ?: now()->toDateString();

        $movements = $partnerId
            ? $this->service->statement($companyId, $partnerId, $currencyId, $startDate, $endDate)
            : [];

        return Inertia::render('Accounting/SupplierBalances/Statement', [
            'suppliers' => Partner::ofType('supplier')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
            'currencies' => $this->currencyService->activeCurrenciesOrdered($companyId),
            'movements'  => $movements,
            'filters'    => [
                'partner_id'  => $partnerId,
                'currency_id' => $currencyId,
                'start_date'  => $startDate,
                'end_date'    => $endDate,
            ],
        ]);
    }
}
