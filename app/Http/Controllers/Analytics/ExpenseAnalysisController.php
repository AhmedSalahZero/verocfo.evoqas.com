<?php

namespace App\Http\Controllers\Analytics;

// ══════════════════════════════════════════════════════════════════
//  ExpenseAnalysisController
//
//  Analytics > Expense Analysis. Two screens:
//    - Dashboard  → full analysis rebuild (September 2026, per
//                   Mahmoud): KPIs, Run-Rate Projection, Category
//                   Breakdown, Top Expense Items, Min/Avg/Max per
//                   Category, and Analyst Notes. Visual language
//                   matches the Sales Dashboard's `fid-`/`sa-` classes.
//    - Reports    → 6 report types, filter-driven
//
//  Zero business logic here — ExpenseAnalysisService does all the
//  math, ExpenseDashboardNoteService owns the notes CRUD. Every
//  method stays under 15 lines (Rule 3).
//
//  Routes (under auth + active.company + module:analytics,*,expense_analysis):
//    GET    /analytics/expenses                    → dashboard()
//    GET    /analytics/expenses/data                → dashboardData()   (AJAX, all sections)
//    POST   /analytics/expenses/notes                → storeNote()
//    PUT    /analytics/expenses/notes/{note}          → updateNote()
//    DELETE /analytics/expenses/notes/{note}          → destroyNote()
//    GET    /analytics/expenses/reports              → reportsPage()
//    POST   /analytics/expenses/reports/run           → runReport()       (AJAX)
//    GET    /analytics/expenses/reports/export         → exportReport()    (browser file download)
//    GET    /analytics/expenses/breakeven             → breakevenPage()
//    POST   /analytics/expenses/breakeven/calculate    → breakevenCalculate() (AJAX)
//
//  @layer    Controller
//  @depends  ExpenseAnalysisService, ExpenseDashboardNoteService, ExpenseAnalysisExport
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Models\ExpenseDashboardNote;
use App\Services\Analytics\ExpenseAnalysisService;
use App\Services\Analytics\ExpenseDashboardNoteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class ExpenseAnalysisController extends Controller
{
    public function __construct(
        private ExpenseAnalysisService $service,
        private ExpenseDashboardNoteService $notes,
    ) {}

    private const REPORT_TYPES = [
        ['key' => 'category_breakdown', 'label' => 'Category Breakdown', 'description' => 'Expense by P&L category (COGS, Sales, Marketing, G&A, Finance, D&A)'],
        ['key' => 'account_breakdown',  'label' => 'Account Breakdown',  'description' => 'Expense by specific account, optionally within one category'],
        ['key' => 'trend',              'label' => 'Trend Over Time',    'description' => 'Monthly, quarterly, semi-annual or annual trend'],
        ['key' => 'min_avg_max',        'label' => 'Min / Avg / Max',    'description' => 'Volatility of each account or category month to month'],
        ['key' => 'period_comparison',  'label' => 'Period Comparison',  'description' => 'Compare 2-5 date ranges side by side'],
    ];

    // ── Dashboard ──────────────────────────────────────────────

    public function dashboard(Request $request)
    {
        $companyId = $this->companyId($request);
        [$from, $to] = $this->defaultRange($companyId);

        return Inertia::render('Analytics/Expenses/Dashboard', [
            'defaultFrom' => $from, 'defaultTo' => $to, 'hasData' => $this->hasExpenses($companyId),
        ]);
    }

    public function dashboardData(Request $request)
    {
        $companyId = $this->companyId($request);
        $v = $request->validate(['date_from' => ['required', 'date'], 'date_to' => ['required', 'date']]);
        [$from, $to] = [$v['date_from'], $v['date_to']];

        return response()->json([
            'kpis'            => $this->service->dashboardKpis($companyId, $from, $to),
            'run_rate'        => $this->service->runRateProjection($companyId, $from, $to),
            'trend'           => $this->service->trend($companyId, $v + ['period' => 'monthly'])['rows'],
            'category_breakdown' => $this->service->categoryBreakdown($companyId, $v)['rows'],
            'top_items'       => $this->service->topExpenseItems($companyId, $from, $to),
            'min_avg_max'     => $this->service->minAvgMax($companyId, ['date_from' => $from, 'date_to' => $to, 'dimension1' => 'category'])['rows'],
            'notes'           => $this->notes->forRange($companyId, $from, $to),
        ]);
    }

    // ── Analyst Notes ─────────────────────────────────────────────

    public function storeNote(Request $request)
    {
        $companyId = $this->companyId($request);
        $v = $request->validate(['date_from' => ['required', 'date'], 'date_to' => ['required', 'date'], 'note' => ['required', 'string', 'max:10000']]);

        return response()->json($this->notes->create($companyId, $request->user()->id, $v));
    }

    public function updateNote(Request $request, ExpenseDashboardNote $note)
    {
        $v = $request->validate(['note' => ['required', 'string', 'max:10000']]);

        return response()->json($this->notes->update($note, $request->user()->id, $v['note']));
    }

    public function destroyNote(Request $request, ExpenseDashboardNote $note)
    {
        $this->notes->delete($note, $request->user()->id);

        return response()->json(['deleted' => true]);
    }

    // ── Reports Page ───────────────────────────────────────────

    public function reportsPage(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Analytics/Expenses/Reports', [
            'hasData' => $this->hasExpenses($companyId),
            'dimensions' => ExpenseAnalysisService::DIMENSIONS,
            'reportTypes' => self::REPORT_TYPES,
            'categories' => $this->categoryOptions(),
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
        $filename  = 'Expense_Analysis_' . $filters['report_type'] . '_' . now()->format('Ymd_His') . '.xlsx';

        return Excel::download(new \App\Exports\Analytics\ExpenseAnalysisExport($result), $filename);
    }

    // ── Breakeven Page ─────────────────────────────────────────

    public function breakevenPage(Request $request)
    {
        $companyId = $this->companyId($request);
        [$from, $to] = $this->defaultRange($companyId);

        return Inertia::render('Analytics/Expenses/Breakeven', [
            'defaultFrom' => $from, 'defaultTo' => $to, 'hasData' => $this->hasExpenses($companyId),
        ]);
    }

    public function breakevenCalculate(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $request->validate(['date_from' => ['required', 'date'], 'date_to' => ['required', 'date']]);

        return response()->json($this->service->breakeven($companyId, $validated['date_from'], $validated['date_to']));
    }

    // ── Helpers ────────────────────────────────────────────────

    private function buildReport(int $companyId, array $filters): array
    {
        return match ($filters['report_type']) {
            'category_breakdown' => $this->service->categoryBreakdown($companyId, $filters),
            'account_breakdown'  => $this->service->accountBreakdown($companyId, $filters),
            'trend'               => $this->service->trend($companyId, $filters),
            'min_avg_max'         => $this->service->minAvgMax($companyId, $filters),
            'period_comparison'   => $this->service->periodComparison($companyId, $filters),
            default               => ['error' => 'Unknown report type'],
        };
    }

    private function validateReportFilters(Request $request): array
    {
        return $request->validate([
            'report_type' => ['required', 'string'],
            'date_from'   => ['required', 'date'],
            'date_to'     => ['required', 'date'],
            'period'      => ['nullable', 'in:monthly,quarterly,semi_annually,annually'],
            'dimension1'  => ['nullable', 'string'],
            'category'    => ['nullable', 'string'],
            'account_id'  => ['nullable', 'integer'],
            'periods'     => ['nullable', 'array'],
        ]);
    }

    private function hasExpenses(int $companyId): bool
    {
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_entry_lines.chart_of_account_id')
            ->where('journal_entries.company_id', $companyId)->where('journal_entries.status', 'posted')
            ->where('chart_of_accounts.class', 'expenses')->exists();
    }

    private function categoryOptions(): array
    {
        return DB::table('chart_of_accounts')
            ->where('class', 'expenses')->where('section', 'income_statement')->where('level', 2)
            ->orderBy('code')->pluck('account_type')->unique()->values()->all();
    }

    private function defaultRange(int $companyId): array
    {
        $maxDate = DB::table('journal_entries')->where('company_id', $companyId)->where('status', 'posted')->max('entry_date');
        $maxDate = $maxDate ?? now()->toDateString();

        return [date('Y', strtotime($maxDate)) . '-01-01', $maxDate];
    }
}
