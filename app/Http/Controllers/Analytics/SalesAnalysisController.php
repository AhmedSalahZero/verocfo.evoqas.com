<?php

namespace App\Http\Controllers\Analytics;

// ══════════════════════════════════════════════════════════════════
//  SalesAnalysisController
//
//  Analytics > Sales Analysis. Two screens:
//    - Dashboard  → full CFO-Tools-parity dashboard: Sales Update KPIs,
//                   Sales Breakdown Analysis, Top Achievers (with
//                   Takeaways drill-down), Customer Nature Analysis,
//                   and Analyst Notes. (Auto Insights & Alerts
//                   intentionally skipped per Mahmoud, September 2026.)
//    - Reports    → the 8 CFO-Tools-style report types, filter-driven
//
//  Zero business logic here — SalesAnalysisService does all the math,
//  SalesDashboardNoteService owns the notes CRUD. Every method stays
//  under 15 lines (Rule 3).
//
//  Routes (under auth + active.company + module:analytics,*,sales_analysis):
//    GET    /analytics/sales                        → dashboard()
//    GET    /analytics/sales/data                    → dashboardData()      (AJAX, all sections)
//    GET    /analytics/sales/achiever-takeaway        → achieverTakeaway()   (AJAX, Takeaways popup)
//    POST   /analytics/sales/notes                    → storeNote()
//    PUT    /analytics/sales/notes/{note}              → updateNote()
//    DELETE /analytics/sales/notes/{note}              → destroyNote()
//    GET    /analytics/sales/reports                  → reportsPage()
//    POST   /analytics/sales/reports/run               → runReport()        (AJAX)
//    GET    /analytics/sales/reports/export            → exportReport()     (browser file download, not AJAX)
//
//  @layer    Controller
//  @depends  SalesAnalysisService, SalesDashboardNoteService, SalesAnalysisExport
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\SalesDashboardNote;
use App\Services\Analytics\SalesAnalysisService;
use App\Services\Analytics\SalesDashboardNoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class SalesAnalysisController extends Controller
{
    public function __construct(
        private SalesAnalysisService $service,
        private SalesDashboardNoteService $notes,
    ) {}

    private const REPORT_TYPES = [
        ['key' => 'single_dimension',  'label' => 'Single Dimension',   'description' => 'Revenue by one dimension e.g. Customer, Zone'],
        ['key' => 'matrix',            'label' => 'Matrix (2D)',        'description' => 'Two dimensions cross-tabulated e.g. Zone × Product'],
        ['key' => 'ranking',           'label' => 'Ranking',            'description' => 'Ranks one dimension per another, with drill-down'],
        ['key' => 'customer_nature',   'label' => 'Customer Nature',    'description' => 'New, Repeating, Active, Stop, Dead, Reactivated'],
        ['key' => 'period_comparison', 'label' => 'Period Comparison',  'description' => 'Compare 2-5 date ranges side by side'],
        ['key' => 'trend',             'label' => 'Trend Over Time',    'description' => 'Monthly, quarterly, semi-annual or annual trend'],
        ['key' => 'two_factors_trend', 'label' => 'Two Factors Trend',  'description' => 'A dimension trend split by a second dimension'],
        ['key' => 'invoice_analysis',  'label' => 'Invoice Analysis',   'description' => 'Average invoice value, line items, large invoices'],
    ];

    // ── Dashboard ──────────────────────────────────────────────

    public function dashboard(Request $request)
    {
        $companyId = $this->companyId($request);
        [$from, $to] = $this->defaultRange($companyId);

        return Inertia::render('Analytics/Sales/Dashboard', [
            'defaultFrom' => $from,
            'defaultTo'   => $to,
            'hasData'     => $this->hasInvoices($companyId),
        ]);
    }

    public function dashboardData(Request $request)
    {
        $companyId = $this->companyId($request);
        $v = $request->validate(['date_from' => ['required', 'date'], 'date_to' => ['required', 'date']]);
        [$from, $to] = [$v['date_from'], $v['date_to']];

        return response()->json([
            'sales_update'     => $this->service->salesUpdate($companyId, $from, $to),
            'breakdowns'       => $this->service->breakdowns($companyId, $from, $to),
            'top_achievers'    => $this->service->topAchievers($companyId, $from, $to),
            'customer_nature'  => $this->service->customerNature($companyId, ['date_from' => $from, 'date_to' => $to]),
            'notes'            => $this->notes->forRange($companyId, $from, $to),
        ]);
    }

    // ── Top Achiever "Takeaways" popup ───────────────────────────

    public function achieverTakeaway(Request $request)
    {
        $companyId = $this->companyId($request);
        $v = $request->validate([
            'dimension'      => ['required', 'string'],
            'date_from'      => ['required', 'date'],
            'date_to'        => ['required', 'date'],
            'selected_label' => ['nullable', 'string'],
        ]);

        return response()->json($this->service->achieverTakeaway($companyId, $v));
    }

    // ── Analyst Notes ─────────────────────────────────────────────

    public function storeNote(Request $request)
    {
        $companyId = $this->companyId($request);
        $v = $request->validate(['date_from' => ['required', 'date'], 'date_to' => ['required', 'date'], 'note' => ['required', 'string', 'max:10000']]);

        return response()->json($this->notes->create($companyId, $request->user()->id, $v));
    }

    public function updateNote(Request $request, SalesDashboardNote $note)
    {
        $v = $request->validate(['note' => ['required', 'string', 'max:10000']]);

        return response()->json($this->notes->update($note, $request->user()->id, $v['note']));
    }

    public function destroyNote(Request $request, SalesDashboardNote $note)
    {
        $this->notes->delete($note, $request->user()->id);

        return response()->json(['deleted' => true]);
    }

    // ── Reports Page ───────────────────────────────────────────

    public function reportsPage(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Analytics/Sales/Reports', [
            'hasData'     => $this->hasInvoices($companyId),
            'dimensions'  => SalesAnalysisService::DIMENSIONS,
            'metrics'     => SalesAnalysisService::METRICS,
            'reportTypes' => self::REPORT_TYPES,
        ]);
    }

    public function runReport(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $this->validateReportFilters($request);

        return response()->json($this->buildReport($companyId, $filters));
    }

    public function exportReport(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $this->validateReportFilters($request);
        $result    = $this->buildReport($companyId, $filters);
        $filename  = 'Sales_Analysis_' . $filters['report_type'] . '_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new \App\Exports\Analytics\SalesAnalysisExport($result), $filename);
    }

    // ── Helpers ────────────────────────────────────────────────

    private function buildReport(int $companyId, array $filters): array
    {
        return match ($filters['report_type']) {
            'single_dimension'  => $this->service->singleDimension($companyId, $filters),
            'matrix'            => $this->service->matrix($companyId, $filters),
            'ranking'           => $this->service->ranking($companyId, $filters),
            'customer_nature'   => $this->service->customerNature($companyId, $filters),
            'period_comparison' => $this->service->periodComparison($companyId, $filters),
            'trend'             => $this->service->trend($companyId, $filters),
            'two_factors_trend' => $this->service->twoFactorsTrend($companyId, $filters),
            'invoice_analysis'  => $this->service->invoiceAnalysis($companyId, $filters),
            default             => ['error' => 'Unknown report type'],
        };
    }

    private function validateReportFilters(Request $request): array
    {
        $validated = $request->validate([
            'report_type'        => ['required', 'string'],
            'date_from'          => ['required', 'date'],
            'date_to'            => ['required', 'date'],
            'period'             => ['nullable', 'in:monthly,quarterly,semi_annually,annually'],
            'dimension1'         => ['nullable', 'string'],
            'dimension2'         => ['nullable', 'string'],
            'metric'             => ['nullable', 'string'],
            'top_n'              => ['nullable', 'integer', 'min:1', 'max:500'],
            'periods'            => ['nullable', 'array'],
            'invoice_threshold'  => ['nullable', 'numeric', 'min:0'],
        ]);

        return $validated;
    }

    private function hasInvoices(int $companyId): bool
    {
        return DB::table('customer_invoices')
            ->where('company_id', $companyId)->where('status', 'posted')->exists();
    }

    private function defaultRange(int $companyId): array
    {
        $maxDate = DB::table('customer_invoices')
            ->where('company_id', $companyId)->where('status', 'posted')->max('invoice_date');
        $maxDate = $maxDate ?? now()->toDateString();

        return [date('Y', strtotime($maxDate)) . '-01-01', $maxDate];
    }
}
