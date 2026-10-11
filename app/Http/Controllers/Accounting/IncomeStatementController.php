<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  IncomeStatementController
//
//  Accounting > Financial Reports > Income Statement — Financial
//  Reports Module Structure v1.0, Section 3.4. One page: mode picker
//  (Single / Monthly / Quarterly, same three view modes as Trial
//  Balance — see BuildsComparativePeriods) + the report itself,
//  rebuilt on every filter change.
//
//  Single mode's one period is a real date RANGE (start_date/end_date
//  from the request, defaulting to "this fiscal year to date").
//
//  Zero business logic here — IncomeStatementService does the math.
//
//  Routes (under auth + active.company):
//    GET /accounting/income-statement → index()
//
//  @layer    Controller
//  @depends  IncomeStatementService, CostCenter, FiscalYear
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Accounting\Concerns\BuildsComparativePeriods;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Models\FiscalYear;
use App\Services\Accounting\IncomeStatementService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IncomeStatementController extends Controller
{
    use BuildsComparativePeriods;

    public function __construct(private IncomeStatementService $service) {}

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

        return Inertia::render('Accounting/IncomeStatement/Index', [
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
