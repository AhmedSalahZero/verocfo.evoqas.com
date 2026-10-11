<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LgStatementController
//
//  Financial Institutions > Statement > LG / Cash Cover Statement.
//  A movement log read directly from lg_issuances + lg_issuance_history
//  — see LgStatementService header for the full reasoning. Read-only:
//  no writes happen anywhere in this controller.
//
//  Routes (under auth + active.company):
//    GET  /statements/lg               → index()    picker form
//    GET  /statements/lg/result        → result()   the statement
//    GET  /statements/lg/export        → exportExcel()
//
//  @layer    Controller
//  @depends  LgStatementService
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Exports\LgStatementExport;
use App\Http\Controllers\Controller;
use App\Services\FinancialInstitutions\LgStatementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class LgStatementController extends Controller
{
    public function __construct(private LgStatementService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Statements/LgStatement/Index', [
            'banks'         => $this->service->bankOptions($companyId),
            'beneficiaries' => $this->service->beneficiaryOptions($companyId),
            'lgTypes'       => $this->service->lgTypeOptions($companyId),
        ]);
    }

    public function result(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $this->validateFilters($request);

        $rows = $this->service->movements($companyId, $filters);

        return Inertia::render('Statements/LgStatement/Result', [
            'rows'    => $rows,
            'totals'  => $this->totals($rows),
            'filters' => $filters,
        ]);
    }

    public function exportExcel(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $this->validateFilters($request);

        $rows = $this->service->movements($companyId, $filters);

        return Excel::download(new LgStatementExport($rows), 'lg-cash-cover-statement.xlsx');
    }

    // ── Private ──────────────────────────────────────────────────

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'bank_id'                => ['nullable', 'integer'],
            'beneficiary_partner_id' => ['nullable', 'integer'],
            'lg_type'                => ['nullable', 'string'],
            'group_by'               => ['nullable', 'in:beneficiary,bank,none'],
            'start_date'             => ['nullable', 'date'],
            'end_date'               => ['nullable', 'date', 'before_or_equal:today'],
            'include_reversed'       => ['nullable', 'boolean'],
        ]);
    }

    private function totals($rows): array
    {
        return [
            'movement_count'  => $rows->count(),
            'total_issued'    => Money::sum($rows->where('event_type', 'issuance')->pluck('amount')),
            'cancelled_count' => (int) $rows->where('event_type', 'cancel')->count(),
            'liquidated_count'=> (int) $rows->where('event_type', 'liquidate')->count(),
        ];
    }
}
