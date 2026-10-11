<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  DashboardController
//
//  Financial Institutions > Dashboard — replaces the previous bare
//  closure (`Route::get('/', fn () => inertia('Dashboard'))`) with
//  the 3-tab structure Mahmoud asked to match from CashVero: Cash
//  Status / LG & LC Status / Cash Forecast, sharing one
//  DashboardTabs.vue bar (see that component's header).
//
//  Cash Forecast (forecast()) is real as of this version — Invoice
//  Aging + Cheque Aging are faithful ports of CashVero's own bucket
//  boundaries; Cash Flow Projection is a deliberately scoped-down v1
//  (see CashFlowProjectionService's header for exactly what's
//  included/excluded and why — confirmed with Mahmoud, Sept 2026).
//
//  Read-only throughout — no writes happen anywhere in this
//  controller.
//
//  Routes (under auth + active.company, prefix financial-institutions):
//    GET  /                                    → cashStatus()   (fi.dashboard — the existing nav link)
//    GET  /dashboard/lglc-status                → lglcStatus()   (fi.dashboard.lglc)
//    GET  /dashboard/forecast                   → forecast()     (fi.dashboard.forecast)
//    GET  /dashboard/cash-status/facilities      → movementFacilities() (JSON, bank picker)
//    GET  /dashboard/cash-status/movement        → movementChart()      (JSON, chart data)
//
//  @layer    Controller
//  @depends  CashStatusDashboardService, LgLcStatusDashboardService,
//            InvoiceAgingService, ChequeAgingService, CashFlowProjectionService
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Services\FinancialInstitutions\CashFlowProjectionService;
use App\Services\FinancialInstitutions\CashStatusDashboardService;
use App\Services\FinancialInstitutions\ChequeAgingService;
use App\Services\FinancialInstitutions\InvoiceAgingService;
use App\Services\FinancialInstitutions\LgLcStatusDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        private CashStatusDashboardService $cashStatusService,
        private LgLcStatusDashboardService $lgLcService,
        private InvoiceAgingService $invoiceAgingService,
        private ChequeAgingService $chequeAgingService,
        private CashFlowProjectionService $cashFlowProjectionService,
    ) {}

    public function cashStatus(Request $request)
    {
        $companyId = $this->companyId($request);
        $date = $request->input('date', now()->toDateString());

        $data = $this->cashStatusService->build($companyId, $date);

        return Inertia::render('FinancialInstitutions/Dashboard/CashStatus', [
            ...$data,
            'date'   => $date,
            'odTypeLabels' => CashStatusDashboardService::OD_TYPES,
            'filterUrl' => route('fi.dashboard'),
            'dashboardTabUrls' => $this->tabUrls(),
        ]);
    }

    public function lglcStatus(Request $request)
    {
        $companyId = $this->companyId($request);
        $currencies = $this->lgLcService->currencies($companyId);

        $data = $this->lgLcService->build($companyId, $currencies);

        return Inertia::render('FinancialInstitutions/Dashboard/LgLcStatus', [
            ...$data,
            'filterUrl' => route('fi.dashboard.lglc'),
            'dashboardTabUrls' => $this->tabUrls(),
        ]);
    }

    public function forecast(Request $request)
    {
        $companyId = $this->companyId($request);
        $currencies = $this->cashStatusService->currencies($companyId);

        $validated = $request->validate([
            'report_interval' => ['nullable', 'in:daily,weekly,monthly'],
            'start_date'      => ['nullable', 'date'],
            'end_date'        => ['nullable', 'date', 'after:start_date'],
            'currencies'      => ['nullable', 'array'],
        ]);

        $reportInterval = $validated['report_interval'] ?? 'weekly';
        $today = now();
        // Default range: today ± nothing given → today to +3 months,
        // same default CashVero itself applies on first entry with no
        // dates chosen yet (see its own Forecast.vue comment).
        $startDate = $validated['start_date'] ?? $today->toDateString();
        $endDate   = $validated['end_date'] ?? $today->copy()->addMonths(3)->toDateString();

        $asOfCurrencies = $validated['currencies'] ?? $currencies;

        $cashFlowReport = $this->cashFlowProjectionService->build($companyId, $startDate, $endDate, $reportInterval, $asOfCurrencies);
        $invoiceAging   = $this->invoiceAgingService->build($companyId, $today->toDateString(), $asOfCurrencies);
        $chequeAging    = $this->chequeAgingService->build($companyId, $today->toDateString(), $asOfCurrencies);

        return Inertia::render('FinancialInstitutions/Dashboard/Forecast', [
            'selectedCurrencies'        => $currencies,
            'cashFlowReport'            => $cashFlowReport,
            'invoiceAging'              => $invoiceAging,
            'chequeAging'               => $chequeAging,
            'reportInterval'            => $reportInterval,
            'selectedReportInterval'    => $reportInterval,
            'cashFlowStartDate'         => $startDate,
            'cashFlowEndDate'           => $endDate,
            'pastDueCustomerInvoices'   => collect($invoiceAging['customer'] ?? [])->map(fn ($c) => $c['past_due'] ?? [])->all(),
            // Audit H15: keyed by currency (was one flat list mixing currencies).
            'pastDueSupplierInvoices'   => collect($invoiceAging['supplier'] ?? [])->map(fn ($c) => $c['past_due'] ?? [])->all(),
            'pastDueInstallments'       => $this->cashStatusService->pastDueInstallments($companyId, $today->toDateString()),
            'filterUrl'                 => route('fi.dashboard.forecast'),
            'dashboardTabUrls'          => $this->tabUrls(),
        ]);
    }

    /** JSON — facilities at one bank, for the Bank Movement chart's picker. */
    public function movementFacilities(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $request->validate([
            'facility_type' => ['required', 'string'],
            'currency'      => ['required', 'string'],
            'bank_id'       => ['required', 'integer'],
        ]);

        return response()->json([
            'data' => $this->cashStatusService->facilitiesForMovementPicker(
                $companyId, $validated['facility_type'], $validated['currency'], $validated['bank_id']
            ),
        ]);
    }

    /** JSON — Bank Movement chart rows for one facility. */
    public function movementChart(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $request->validate([
            'facility_id' => ['required', 'integer'],
            'start_date'  => ['nullable', 'date'],
            'end_date'    => ['nullable', 'date'],
        ]);

        return response()->json([
            'chart_data' => $this->cashStatusService->movementChart(
                $companyId, $validated['facility_id'], $validated['start_date'] ?? null, $validated['end_date'] ?? null
            ),
        ]);
    }

    // ── Private ──────────────────────────────────────────────────

    private function tabUrls(): array
    {
        return [
            'cash'     => route('fi.dashboard'),
            'lglc'     => route('fi.dashboard.lglc'),
            'forecast' => route('fi.dashboard.forecast'),
        ];
    }
}
