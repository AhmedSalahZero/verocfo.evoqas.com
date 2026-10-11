<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CashFlowStatementController
//
//  Accounting > Financial Reports > Cash Flow Statement — Financial
//  Reports Module Structure v1.0, Section 3.6. Indirect method,
//  confirmed with Mahmoud (2026-09). One page: mode picker (Single /
//  Monthly / Quarterly, same three view modes as Trial Balance — see
//  BuildsComparativePeriods) + the report itself. Zero business logic
//  — see CashFlowStatementService for the account-by-account
//  Operating/Investing/Financing classification.
//
//  Routes (under auth + active.company):
//    GET /accounting/cash-flow-statement → index()
//
//  @layer    Controller
//  @depends  CashFlowStatementService, CostCenter, FiscalYear
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Accounting\Concerns\BuildsComparativePeriods;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Services\Accounting\CashFlowStatementService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CashFlowStatementController extends Controller
{
    use BuildsComparativePeriods;

    public function __construct(private CashFlowStatementService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $this->validatePeriodRequest($request, [
            'from_date' => ['nullable', 'date'],
            'to_date'   => ['nullable', 'date'],
        ]);

        $fromDate = $validated['from_date'] ?? $this->defaultFromDate($companyId);
        $toDate   = $validated['to_date'] ?? now()->toDateString();

        $singlePeriod = [
            'key'        => 'single',
            'label'      => \Carbon\Carbon::parse($fromDate)->format('d-m-Y').' to '.\Carbon\Carbon::parse($toDate)->format('d-m-Y'),
            'start_date' => $fromDate,
            'end_date'   => $toDate,
        ];

        $periods = $this->buildPeriods($companyId, $validated, $singlePeriod);

        return Inertia::render('Accounting/CashFlowStatement/Index', [
            'report' => $periods
                ? $this->service->build($companyId, $periods, $validated['cost_center_id'] ?? null)
                : null,
            'filters' => array_merge($validated, ['from_date' => $fromDate, 'to_date' => $toDate]),
            'costCenters' => CostCenter::active()->orderBy('code')->get(['id', 'code', 'name_en']),
            'fiscalYears' => $this->fiscalYearOptions($companyId),
        ]);
    }

    private function defaultFromDate(int $companyId): string
    {
        $fy = FiscalYear::where('company_id', $companyId)
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->first();

        return $fy?->start_date->toDateString() ?? now()->startOfYear()->toDateString();
    }
}
