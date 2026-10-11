<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CustomerBalanceController
//
//  HTTP layer for the Customer Balances screen (Accounting →
//  Customers Receivables → Customer Balances, per Mahmoud, September
//  2026) — a currency-tabbed summary of every customer's net AR
//  position, drilling into a per-customer Invoices Report and a
//  Customer Statement. Read-only throughout — zero business logic,
//  delegated to CustomerBalanceService, which itself never writes
//  anything.
//
//  Routes (under auth + active.company):
//    GET  /accounting/customer-balances            → index()
//    GET  /accounting/customer-balances/invoices    → invoiceReport()
//    GET  /accounting/customer-balances/statement   → statement()
//
//  @layer    Controller
//  @depends  CustomerBalanceService, Partner, CurrencyService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\Partner;
use App\Services\Accounting\CustomerBalanceService;
use App\Services\Accounting\InternalSettlementService;
use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CustomerBalanceController extends Controller
{
    public function __construct(
        private CustomerBalanceService $service,
        private CurrencyService $currencyService,
        private InternalSettlementService $internalSettlementService,
    ) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $currencyId = $request->filled('currency_id') ? $request->integer('currency_id') : null;
        $duesFilter = $request->input('dues', 'all');

        $customers = $this->service->customersTable($companyId, $currencyId, $duesFilter, $request->input('search'));

        // "Internal Settle" — per Mahmoud, September 2026: only ever
        // offered on a specific currency tab (never Main Currency —
        // no cross-currency netting), and only for a partner who is
        // ALSO a supplier with a due balance in that SAME currency.
        // Gating lives on the customer side deliberately (his own
        // words: no due invoice from the customer, no internal
        // settlement, even if they're owed money as a supplier).
        if ($currencyId) {
            $eligiblePartnerIds = collect($this->internalSettlementService->eligiblePartnerCurrencyPairs($companyId))
                ->where('currency_id', $currencyId)
                ->pluck('partner_id')
                ->all();

            $customers = array_map(function ($c) use ($eligiblePartnerIds) {
                $c['can_internal_settle'] = in_array($c['partner_id'], $eligiblePartnerIds, true);
                return $c;
            }, $customers);
        }

        return Inertia::render('Accounting/CustomerBalances/Index', [
            'tabs'      => $this->service->currencyTabs($companyId),
            'customers' => $customers,
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

        return Inertia::render('Accounting/CustomerBalances/InvoiceReport', [
            'partner'  => $partner->only(['id', 'code', 'name_en', 'name_ar']),
            'currency' => $currency,
            'invoices' => $this->service->invoicesReport($companyId, $partnerId, $currencyId),
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

        return Inertia::render('Accounting/CustomerBalances/Statement', [
            'customers' => Partner::ofType('customer')->active()->orderBy('name_en')->get(['id', 'code', 'name_en', 'name_ar']),
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
