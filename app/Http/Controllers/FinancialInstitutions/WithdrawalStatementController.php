<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  WithdrawalStatementController
//
//  Financial Institutions > Statement > Withdrawal Statement. A
//  screen on top of facility_withdrawals + its settlement history —
//  no new data, per Mahmoud's confirmation that the Settlement Engine
//  already keeps this table correct. Read-only.
//
//  Routes (under auth + active.company):
//    GET  /statements/withdrawal               → index()
//    GET  /statements/withdrawal/result         → result()
//    GET  /statements/withdrawal/export         → exportExcel()
//
//  @layer    Controller
//  @depends  WithdrawalStatementService
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Exports\WithdrawalStatementExport;
use App\Http\Controllers\Controller;
use App\Services\FinancialInstitutions\WithdrawalStatementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class WithdrawalStatementController extends Controller
{
    public function __construct(private WithdrawalStatementService $service) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Statements/WithdrawalStatement/Index', [
            'banks'      => $this->service->bankOptions($companyId),
            'facilities' => $this->service->facilityOptions($companyId),
        ]);
    }

    public function result(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $this->validateFilters($request);

        $rows = $this->service->withdrawals($companyId, $filters);

        return Inertia::render('Statements/WithdrawalStatement/Result', [
            'rows'    => $rows,
            'totals'  => $this->totals($rows),
            'filters' => $filters,
        ]);
    }

    public function exportExcel(Request $request)
    {
        $companyId = $this->companyId($request);
        $filters   = $this->validateFilters($request);

        $rows = $this->service->withdrawals($companyId, $filters);

        return Excel::download(new WithdrawalStatementExport($rows), 'withdrawal-statement.xlsx');
    }

    // ── Private ──────────────────────────────────────────────────

    private function validateFilters(Request $request): array
    {
        return $request->validate([
            'bank_id'             => ['nullable', 'integer'],
            'credit_facility_id'  => ['nullable', 'integer'],
            'status'              => ['nullable', 'in:open,partial,settled,overdue'],
            'group_by'            => ['nullable', 'in:bank,facility,none'],
            'start_date'          => ['nullable', 'date'],
            'end_date'            => ['nullable', 'date', 'before_or_equal:today'],
        ]);
    }

    private function totals($rows): array
    {
        return [
            'withdrawal_count' => $rows->count(),
            'total_amount'     => Money::sum($rows->pluck('amount')),
            'total_remaining'  => Money::sum($rows->pluck('remaining_amount')),
            'overdue_count'    => $rows->where('is_overdue', true)->count(),
        ];
    }
}
