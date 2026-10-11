<?php

namespace App\Http\Controllers\Projects;

// ══════════════════════════════════════════════════════════════════
//  ContractDashboardController
//
//  Projects & Contracts > Contract Dashboard. Thin controller — all
//  aggregation lives in ContractDashboardService::build(). Ported
//  from CashVero's ContractDashboardController, same shape: the
//  `start_date`/`end_date` filter is a PERIOD for the trend/billing
//  sections, but `end_date` also doubles as the AS-OF date for
//  status/aging/alerts — same dual meaning CashVero's own version
//  uses (see that controller's own docblock).
//
//  Read-only. Export re-runs the service rather than reading
//  anything the page already computed, and writes raw numeric
//  values (not comma-formatted display strings) so Excel's own
//  formatting and =SUM() totals work on real numbers — same
//  reasoning as CashVero's own export() method.
//
//  Routes (under auth + active.company, prefix projects):
//    GET  /dashboard         → index()   (projects.dashboard)
//    GET  /dashboard/export  → export()  (projects.dashboard.export)
//
//  @layer    Controller
//  @depends  ContractDashboardService, ContractDashboardExport
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Exports\ContractDashboardExport;
use App\Http\Controllers\Controller;
use App\Services\ContractDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ContractDashboardController extends Controller
{
    public function __construct(private ContractDashboardService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        $data = $this->service->build($companyId, $request->get('start_date'), $request->get('end_date'));

        return Inertia::render('Projects/Dashboard/ContractStatus', [
            ...$data,
            'contractsIndexUrl' => route('projects.index'),
            'filterUrl'         => route('projects.dashboard'),
            'exportUrl'         => route('projects.dashboard.export'),
        ]);
    }

    public function export(Request $request)
    {
        $companyId = $this->companyId($request);

        $data = $this->service->build($companyId, $request->get('start_date'), $request->get('end_date'));

        $currency = $request->get('currency');
        $rows = collect($data['details']['all']);
        if ($currency) {
            $rows = $rows->where('currency', strtoupper($currency))->values();
        }

        if ($rows->isEmpty()) {
            return back()->with('fail', __('No Data Found'));
        }

        $headings = [
            '#', 'Customer', 'Code', 'Name', 'Currency', 'Status',
            'Start Date', 'End Date', 'Invoices',
            'Contract Value', 'Invoiced', 'Remaining', 'Utilization %',
            'Billed (incl. tax)', 'Collected', 'Uncollected',
        ];

        $sheetRows = $rows->values()->map(function (array $row, int $index) {
            $value = Money::round($row['amount']); // Audit L5: exact strings; Excel receives numbers via SafeValueBinder
            return [
                '#' => $index + 1,
                'Customer' => $row['customer_name'],
                'Code' => $row['code'],
                'Name' => $row['name'],
                'Currency' => $row['currency'],
                'Status' => $row['status_label'],
                'Start Date' => $row['start_date'],
                'End Date' => $row['end_date'],
                'Invoices' => $row['invoice_count'],
                'Contract Value' => $value,
                'Invoiced' => Money::round($row['invoiced']),
                'Remaining' => Money::round($row['remaining']),
                'Utilization %' => Money::isPositive($value)
                    ? Money::round(bcmul(bcdiv(Money::normalize($row['invoiced']), $value, 8), '100', 8))
                    : '0.00',
                'Billed (incl. tax)' => Money::round($row['billed']),
                'Collected' => Money::round($row['collected']),
                'Uncollected' => Money::round($row['uncollected']),
            ];
        });

        $fileNameParts = array_filter(['Contract-Dashboard', $currency, $data['startDate'], $data['endDate']]);
        $fileName = preg_replace('/[^A-Za-z0-9\-]+/', '-', implode('-', $fileNameParts)).'.xlsx';

        return (new ContractDashboardExport($headings, $sheetRows))->download($fileName);
    }
}
