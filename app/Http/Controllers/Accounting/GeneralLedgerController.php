<?php

namespace App\Http\Controllers\Accounting;

// ══════════════════════════════════════════════════════════════════
//  GeneralLedgerController
//
//  Accounting > General Ledger > General Ledger — Financial Reports
//  Phase 5, Section 3.2 (see VeroCFO_Financial_Reports_Module_Structure
//  v1.0). Pick any postable Chart of Accounts row, pick a date range,
//  optionally narrow to one cost center — see every posted journal
//  line that hit it, in date order, with a running balance. Same
//  "picker → result → export" shape as Bank Statement, reusing the
//  same shared engine (LedgerStatementService) rather than a second,
//  parallel query — see that service's header for the full reasoning
//  and its v1.2 notes on the opening-balance and cost-center additions
//  made specifically so this controller could exist.
//
//  Unlike Bank Statement (always an asset account) a General Ledger
//  account can be ANY class — asset, liability, equity, revenue, or
//  expense — so the correct $balanceSign is resolved per account from
//  its own `normal_balance` column (debit → SIGN_ASSET convention,
//  credit → SIGN_LIABILITY convention) rather than assumed.
//
//  Zero business logic here — reads delegate to LedgerStatementService
//  and CostCenterService; this is a read-only report, nothing here
//  ever writes.
//
//  Routes (under auth + active.company):
//    GET  /accounting/general-ledger         → index()   picker form
//    GET  /accounting/general-ledger/result  → result()  the ledger itself
//    GET  /accounting/general-ledger/export  → exportExcel()
//
//  @layer    Controller
//  @depends  LedgerStatementService, CostCenterService, ChartOfAccount
// ══════════════════════════════════════════════════════════════════

use App\Exports\GeneralLedgerExport;
use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\CostCenter;
use App\Services\Accounting\LedgerStatementService;
use App\Services\CostCenterService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class GeneralLedgerController extends Controller
{
    public function __construct(
        private LedgerStatementService $statementService,
        private CostCenterService $costCenterService,
    ) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Accounting/GeneralLedger/Index', [
            'accounts'    => $this->accountOptions($companyId),
            'costCenters' => $this->costCenterOptions($companyId),
        ]);
    }

    public function result(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $this->validateRequest($request);

        $account = $this->resolveAccount($companyId, $validated['account_id']);

        $rows = $this->statementService->paginate(
            $companyId,
            $account['id'],
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            50,
            $account['balance_sign'],
            $validated['cost_center_id'] ?? null
        );

        $totals = $this->statementService->totals(
            $companyId,
            $account['id'],
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $account['balance_sign'],
            $validated['cost_center_id'] ?? null
        );

        $costCenter = ! empty($validated['cost_center_id'])
            ? CostCenter::find($validated['cost_center_id'])
            : null;

        return Inertia::render('Accounting/GeneralLedger/Result', [
            'account'    => $account,
            'costCenter' => $costCenter ? ['id' => $costCenter->id, 'code' => $costCenter->code, 'name_en' => $costCenter->name_en, 'name_ar' => $costCenter->name_ar] : null,
            'rows'       => $rows,
            'totals'     => $totals,
            'filters'    => [
                'account_id'     => $validated['account_id'],
                'cost_center_id' => $validated['cost_center_id'] ?? null,
                'start_date'     => $validated['start_date'] ?? null,
                'end_date'       => $validated['end_date'] ?? null,
            ],
        ]);
    }

    public function exportExcel(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $this->validateRequest($request);

        $account = $this->resolveAccount($companyId, $validated['account_id']);

        $rows = $this->statementService->all(
            $companyId,
            $account['id'],
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $account['balance_sign'],
            $validated['cost_center_id'] ?? null
        );

        $label = $account['code'].' '.$account['name_en'];

        return Excel::download(
            new GeneralLedgerExport($rows, $label),
            'general-ledger-'.$account['code'].'.xlsx'
        );
    }

    // ── Private ──────────────────────────────────────────────────

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'account_id'     => ['required', 'integer'],
            'cost_center_id' => ['nullable', 'integer'],
            'start_date'     => ['nullable', 'date'],
            // An "as of" report never shows into the future.
            'end_date'       => ['nullable', 'date'],
        ]);
    }

    /**
     * Resolves one account into the common shape the Vue page and the
     * export both read — 'balance_sign' is derived from the account's
     * own normal_balance (debit-normal accounts use the asset-style
     * debit-minus-credit formula; credit-normal accounts use the
     * liability-style credit-minus-debit formula), never assumed.
     */
    private function resolveAccount(int $companyId, int $accountId): array
    {
        $account = ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->postable()
            ->findOrFail($accountId);

        return [
            'id'            => $account->id,
            'code'          => $account->code,
            'name_en'       => $account->name_en,
            'name_ar'       => $account->name_ar,
            'class'         => $account->class,
            'balance_sign'  => $account->normal_balance === ChartOfAccount::BALANCE_CREDIT
                ? LedgerStatementService::SIGN_LIABILITY
                : LedgerStatementService::SIGN_ASSET,
        ];
    }

    private function accountOptions(int $companyId): array
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->postable()
            ->active()
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'class'])
            ->map(fn ($a) => [
                'id'      => $a->id,
                'code'    => $a->code,
                'name_en' => $a->name_en,
                'name_ar' => $a->name_ar,
                'class'   => $a->class,
            ])
            ->values()
            ->all();
    }

    private function costCenterOptions(int $companyId): array
    {
        return $this->costCenterService->listForDropdown($companyId)
            ->map(fn ($cc) => [
                'id'      => $cc->id,
                'code'    => $cc->code,
                'name_en' => $cc->name_en,
                'name_ar' => $cc->name_ar,
            ])
            ->values()
            ->all();
    }
}
