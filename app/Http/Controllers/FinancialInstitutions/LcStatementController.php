<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LcStatementController
//
//  Financial Institutions > Statement > LC Statement. Same pattern
//  as LgStatementController (see that file's header) — a movement
//  log read directly from letter_of_credit_issuances +
//  lc_issuance_history, never the GL. Read-only.
//
//  Routes (under auth + active.company):
//    GET  /statements/lc               → index()
//    GET  /statements/lc/result        → result()
//    GET  /statements/lc/export        → exportExcel()
//
//  @layer    Controller
//  @depends  LcStatementService
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Exports\LcStatementExport;
use App\Http\Controllers\Controller;
use App\Services\FinancialInstitutions\LcStatementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class LcStatementController extends Controller
{
    public function __construct(private LcStatementService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Statements/LcStatement/Index', [
            'banks'     => $this->service->bankOptions($companyId),
            'suppliers' => $this->service->supplierOptions($companyId),
            'lcTypes'   => $this->service->lcTypeOptions($companyId),
        ]);
    }

    public function result(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $this->validateFilters($request);

        $rows = $this->service->movements($companyId, $filters);

        return Inertia::render('Statements/LcStatement/Result', [
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

        return Excel::download(new LcStatementExport($rows), 'lc-statement.xlsx');
    }

    // ── Private ──────────────────────────────────────────────────

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'bank_id'             => ['nullable', 'integer'],
            'supplier_partner_id' => ['nullable', 'integer'],
            'lc_type'             => ['nullable', 'string'],
            'group_by'            => ['nullable', 'in:supplier,bank,none'],
            'start_date'          => ['nullable', 'date'],
            'end_date'            => ['nullable', 'date', 'before_or_equal:today'],
            'include_reversed'    => ['nullable', 'boolean'],
        ]);
    }

    private function totals($rows): array
    {
        return [
            'movement_count' => $rows->count(),
            'total_issued'   => Money::sum($rows->where('event_type', 'issuance')->pluck('amount')),
            'paid_count'     => (int) $rows->where('event_type', 'mark_paid')->count(),
            'settled_count'  => (int) $rows->where('event_type', 'settlement')->count(),
        ];
    }
}
