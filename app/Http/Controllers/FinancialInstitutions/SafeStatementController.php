<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  SafeStatementController
//
//  Financial Institutions > Statement > Safe Statement.
//
//  A safe is NOT a bank_accounts row — it's a real, postable Chart of
//  Accounts row under 131 (code LIKE '131%', is_postable = true),
//  already the exact pattern every cash-movement service in this app
//  uses (CustomerPaymentController::safeAccounts(),
//  SupplierPaymentController::safeAccounts(), OtherCollection,
//  OtherPayment, CashExpensePayment — all identical query). Real
//  journal entries already post through these accounts via
//  safe_account_id on those five models, so — unlike LG/LC/Withdrawal
//  — this report reuses LedgerStatementService directly, the exact
//  same running-balance-ledger engine Bank Statement uses, just
//  sourcing its account list from Chart of Accounts instead of
//  bank_accounts/credit_facilities.
//
//  Deliberately its own controller (not folded into
//  BankStatementController) — a safe is a distinct real-world thing
//  from a bank account or an OD facility, and Mahmoud's own comment
//  in routes/web.php already lists "Safe Statement" as its own report
//  in the build order.
//
//  Routes (under auth + active.company):
//    GET  /statements/safe               → index()   picker form
//    GET  /statements/safe/result        → result()  the statement
//    GET  /statements/safe/export        → exportExcel()
//
//  @layer    Controller
//  @depends  LedgerStatementService, ChartOfAccount
// ══════════════════════════════════════════════════════════════════

use App\Exports\SafeStatementExport;
use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Services\Accounting\LedgerStatementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class SafeStatementController extends Controller
{
    public function __construct(private LedgerStatementService $statementService) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Statements/SafeStatement/Index', [
            'safeAccounts' => $this->safeAccountOptions($companyId),
        ]);
    }

    public function result(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $this->validateRequest($request);

        $account = $this->resolveAccount($companyId, $validated['safe_account_id']);

        $rows = $this->statementService->paginate(
            $companyId,
            $account['coa_account_id'],
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            50,
            LedgerStatementService::SIGN_ASSET
        );

        $totals = $this->statementService->totals(
            $companyId,
            $account['coa_account_id'],
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            LedgerStatementService::SIGN_ASSET
        );

        return Inertia::render('Statements/SafeStatement/Result', [
            'account' => $account,
            'rows'    => $rows,
            'totals'  => $totals,
            'filters' => [
                'safe_account_id' => $validated['safe_account_id'],
                'start_date'      => $validated['start_date'] ?? null,
                'end_date'        => $validated['end_date'] ?? null,
            ],
        ]);
    }

    public function exportExcel(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $this->validateRequest($request);

        $account = $this->resolveAccount($companyId, $validated['safe_account_id']);

        $rows = $this->statementService->all(
            $companyId,
            $account['coa_account_id'],
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            LedgerStatementService::SIGN_ASSET
        );

        return Excel::download(
            new SafeStatementExport($rows, $account['name']),
            'safe-statement-'.($account['code'] ?: $validated['safe_account_id']).'.xlsx'
        );
    }

    // ── Private ──────────────────────────────────────────────────

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'safe_account_id' => ['required', 'integer'],
            'start_date'      => ['nullable', 'date'],
            // Mahmoud: end date can never be in the future — same rule
            // as Bank Statement, enforced server-side since that's the
            // check that actually matters.
            'end_date'        => ['nullable', 'date', 'before_or_equal:today'],
        ]);
    }

    private function resolveAccount(int $companyId, int $safeAccountId): array
    {
        $account = ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->where('code', 'like', '131%')
            ->where('is_postable', true)
            ->findOrFail($safeAccountId);

        return [
            'id'             => $account->id,
            'coa_account_id' => $account->id,
            'code'           => $account->code,
            'name'           => $account->name_en,
            'name_ar'        => $account->name_ar,
        ];
    }

    private function safeAccountOptions(int $companyId): array
    {
        // Known Recurring Bug (Master Summary §10): Chart of Accounts
        // is a shared/system-seeded structure per company but the
        // global CompanyScope doesn't special-case it — same
        // withoutGlobalScopes()->forCompany() pattern every other
        // safeAccounts() lookup in this app already uses.
        return ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->where('code', 'like', '131%')
            ->where('is_postable', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name_en'])
            ->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name_en])
            ->values()->all();
    }
}
