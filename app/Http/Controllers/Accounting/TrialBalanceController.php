<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  TrialBalanceController
//
//  Accounting > Financial Reports > Trial Balance — Financial Reports
//  Module Structure v1.0, Section 3.1, built together with General
//  Ledger's shared conventions (account picker, cost center filter,
//  end date never in the future). Three view modes, confirmed with
//  Mahmoud (2026-09):
//
//    - single    — one date range, one 4-column block (the default)
//    - monthly   — one chosen Fiscal Year's real 12 accounting_periods,
//                  side by side, so activity can be traced month to
//                  month
//    - quarterly — the same 12 periods grouped into 4 quarters
//
//  Zero business logic here — periods are assembled from the real
//  FiscalYear/AccountingPeriod records (never a hardcoded calendar-
//  month grid — see TrialBalanceService header for why that matters),
//  then handed to TrialBalanceService, which does the actual
//  balance math.
//
//  Routes (under auth + active.company):
//    GET  /accounting/trial-balance         → index()   picker form
//    GET  /accounting/trial-balance/result  → result()  the report itself
//    GET  /accounting/trial-balance/export  → exportExcel()
//
//  @layer    Controller
//  @depends  TrialBalanceService, CostCenterService, FiscalYear, AccountingPeriod
// ══════════════════════════════════════════════════════════════════

use App\Exports\TrialBalanceExport;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Services\Accounting\TrialBalanceService;
use App\Services\CostCenterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class TrialBalanceController extends Controller
{
    const MODE_SINGLE    = 'single';
    const MODE_MONTHLY   = 'monthly';
    const MODE_QUARTERLY = 'quarterly';

    public function __construct(
        private TrialBalanceService $service,
        private CostCenterService $costCenterService,
    ) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/TrialBalance/Index', [
            'costCenters' => $this->costCenterOptions($companyId),
            'fiscalYears' => $this->fiscalYearOptions($companyId),
        ]);
    }

    public function result(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $this->validateRequest($request);

        $periods = $this->buildPeriods($companyId, $validated);

        if (empty($periods)) {
            return back()->with('error', 'This fiscal year has no periods to report on.');
        }

        $report = $this->service->build(
            $companyId,
            $periods,
            $validated['cost_center_id'] ?? null,
            $validated['show_all'] ?? false
        );

        $costCenter = ! empty($validated['cost_center_id'])
            ? CostCenter::find($validated['cost_center_id'])
            : null;

        return Inertia::render('Accounting/TrialBalance/Result', [
            'report'     => $report,
            'costCenter' => $costCenter ? ['id' => $costCenter->id, 'code' => $costCenter->code, 'name_en' => $costCenter->name_en, 'name_ar' => $costCenter->name_ar] : null,
            'filters'    => $validated,
        ]);
    }

    public function exportExcel(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $this->validateRequest($request);

        $periods = $this->buildPeriods($companyId, $validated);

        $report = $this->service->build(
            $companyId,
            $periods,
            $validated['cost_center_id'] ?? null,
            $validated['show_all'] ?? false
        );

        return Excel::download(
            new TrialBalanceExport($report),
            'trial-balance-'.($validated['mode'] ?? self::MODE_SINGLE).'.xlsx'
        );
    }

    // ── Private ──────────────────────────────────────────────────

    private function validateRequest(Request $request): array
    {
        $validated = $request->validate([
            'mode'            => ['nullable', 'in:single,monthly,quarterly'],
            'cost_center_id'  => ['nullable', 'integer'],
            'show_all'        => ['nullable', 'boolean'],
            'start_date'      => ['nullable', 'date'],
            'end_date'        => ['nullable', 'date'],
            'fiscal_year_id'  => ['nullable', 'integer', 'required_if:mode,monthly,quarterly'],
        ]);

        $validated['mode'] = $validated['mode'] ?? self::MODE_SINGLE;

        return $validated;
    }

    /**
     * Assembles the ordered period list TrialBalanceService needs.
     * Monthly/Quarterly are always read from the company's real
     * accounting_periods rows for the chosen fiscal year — never a
     * hardcoded calendar-month grid (see service header).
     */
    private function buildPeriods(int $companyId, array $validated): array
    {
        if ($validated['mode'] === self::MODE_SINGLE) {
            $end = $validated['end_date'] ?? now()->format('Y-m-d');
            $hasStart = ! empty($validated['start_date']);

            return [[
                'key'        => 'single',
                'label'      => $hasStart
                    ? \Carbon\Carbon::parse($validated['start_date'])->format('d-m-Y').' to '.\Carbon\Carbon::parse($end)->format('d-m-Y')
                    : 'As of '.\Carbon\Carbon::parse($end)->format('d-m-Y'),
                'start_date' => $validated['start_date'] ?? '2000-01-01',
                'end_date'   => $end,
            ]];
        }

        $fiscalYear = FiscalYear::where('company_id', $companyId)
            ->with(['periods' => fn ($q) => $q->orderBy('period_number')])
            ->find($validated['fiscal_year_id']);

        if (! $fiscalYear || $fiscalYear->periods->isEmpty()) {
            return [];
        }

        $monthlyPeriods = $fiscalYear->periods->map(fn ($p) => [
            'key'        => 'p'.$p->period_number,
            'label'      => $p->name,
            'start_date' => $p->start_date->format('Y-m-d'),
            'end_date'   => $p->end_date->format('Y-m-d'),
        ])->values();

        if ($validated['mode'] === self::MODE_MONTHLY) {
            return $monthlyPeriods->all();
        }

        // Quarterly — group the same 12 periods into chunks of 3,
        // in period_number order (already sorted above).
        return $monthlyPeriods->chunk(3)->values()->map(function ($chunk, $i) {
            $chunk = $chunk->values();
            return [
                'key'        => 'q'.($i + 1),
                'label'      => 'Q'.($i + 1),
                'start_date' => $chunk->first()['start_date'],
                'end_date'   => $chunk->last()['end_date'],
            ];
        })->all();
    }

    private function costCenterOptions(int $companyId): array
    {
        return $this->costCenterService->listForDropdown($companyId)
            ->map(fn ($cc) => ['id' => $cc->id, 'code' => $cc->code, 'name_en' => $cc->name_en, 'name_ar' => $cc->name_ar])
            ->values()
            ->all();
    }

    private function fiscalYearOptions(int $companyId): array
    {
        return FiscalYear::where('company_id', $companyId)
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'start_date', 'end_date', 'status'])
            ->map(fn ($fy) => [
                'id'         => $fy->id,
                'name'       => $fy->name,
                'start_date' => $fy->start_date->format('Y-m-d'),
                'end_date'   => $fy->end_date->format('Y-m-d'),
                'status'     => $fy->status,
            ])
            ->values()
            ->all();
    }
}
