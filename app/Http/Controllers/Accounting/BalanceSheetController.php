<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  BalanceSheetController
//
//  Accounting > Financial Reports > Balance Sheet — Financial
//  Reports Module Structure v1.0, Section 3.5. One page: mode picker
//  (Single / Monthly / Quarterly, same three view modes as Trial
//  Balance — see BuildsComparativePeriods) + the report itself.
//
//  A Balance Sheet is a snapshot, not a range — Single mode's one
//  period uses the same as-of date for both start_date and end_date
//  (BalanceSheetService only ever reads end_date); Monthly/Quarterly
//  show one snapshot per real accounting period's end date, side by
//  side, exactly like Trial Balance's Ending column repeated.
//
//  Year-end closing rules (Option A — nothing posted, 314/315
//  computed live) live entirely in BalanceSheetService.
//
//  Routes (under auth + active.company):
//    GET /accounting/balance-sheet → index()
//
//  @layer    Controller
//  @depends  BalanceSheetService, CostCenter
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Accounting\Concerns\BuildsComparativePeriods;
use App\Http\Controllers\Controller;
use App\Models\CostCenter;
use App\Services\Accounting\BalanceSheetService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BalanceSheetController extends Controller
{
    use BuildsComparativePeriods;

    public function __construct(private BalanceSheetService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $this->validatePeriodRequest($request, [
            'as_of_date' => ['nullable', 'date'],
        ]);

        $asOfDate = $validated['as_of_date'] ?? now()->toDateString();

        $singlePeriod = [
            'key'        => 'single',
            'label'      => 'As of '.\Carbon\Carbon::parse($asOfDate)->format('d-m-Y'),
            'start_date' => $asOfDate,
            'end_date'   => $asOfDate,
        ];

        $periods = $this->buildPeriods($companyId, $validated, $singlePeriod);

        return Inertia::render('Accounting/BalanceSheet/Index', [
            'report' => $periods
                ? $this->service->build($companyId, $periods, $validated['cost_center_id'] ?? null)
                : null,
            'filters' => array_merge($validated, ['as_of_date' => $asOfDate]),
            'costCenters' => CostCenter::active()->orderBy('code')->get(['id', 'code', 'name_en']),
            'fiscalYears' => $this->fiscalYearOptions($companyId),
        ]);
    }
}
