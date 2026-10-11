<?php

namespace App\Http\Controllers\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankStatementController
//
//  Financial Institutions > Statement > Bank Statement — the first,
//  and reference, report in the new Statement section (confirmed
//  with Mahmoud, 2026-09). A running-balance ledger read DIRECTLY
//  from the real journal entries via LedgerStatementService — never
//  a parallel table mirroring the GL. See that service's header for
//  the full reasoning.
//
//  v1.1 — "every bank account open in the application" includes
//  Overdraft-family credit facilities (a separate table from
//  bank_accounts). See resolveAccount()/creditFacilityOptions().
//
//  v1.2 (2026-09, Mahmoud) — a Credit Facility's statement has a
//  different structure from a bank account's: two extra columns,
//  Limit and (available) Room, and the page must show them even when
//  there is zero activity in the selected date range — a facility
//  always has a limit and a room, transaction or not. Also: an
//  Overdraft account is a LIABILITY on the books (a drawdown CREDITS
//  it, a repayment DEBITS it — real, correct double-entry, see the
//  Settlement Engine), so its running balance uses the OPPOSITE sign
//  convention from a bank/safe account's — see
//  LedgerStatementService::SIGN_LIABILITY. Room = facility_limit -
//  ending_balance (amount currently owed), using the facility's
//  CURRENT limit — this app doesn't yet look up the limit that was in
//  force at a past date if the facility has since been renewed (a
//  known, separately-flagged gap, not something silently guessed at
//  here).
//
//  Also (Mahmoud): end_date can never be after today — enforced here
//  as well as client-side, since server-side validation is the one
//  that actually matters.
//
//  No Reviewed column (reconciliation postponed) and no inline-edit
//  Actions column (CashVero-only, deliberately not ported) — see
//  LedgerStatementService header.
//
//  Zero business logic here — reads delegate to LedgerStatementService,
//  writes don't exist (this is a read-only report).
//
//  Routes (under auth + active.company):
//    GET  /statements/bank                → index()   picker form
//    GET  /statements/bank/result          → result()  the statement itself
//    GET  /statements/bank/export          → exportExcel()
//
//  @layer    Controller
//  @depends  LedgerStatementService, BankAccount, CreditFacility
// ══════════════════════════════════════════════════════════════════

use App\Exports\BankStatementExport;
use App\Http\Controllers\Controller;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Services\Accounting\ForeignCurrencyLedgerService;
use App\Services\Accounting\LedgerStatementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

class BankStatementController extends Controller
{
    const SOURCE_BANK_ACCOUNT    = 'bank_account';
    const SOURCE_CREDIT_FACILITY = 'credit_facility';

    public function __construct(
        private LedgerStatementService $statementService,
        private ForeignCurrencyLedgerService $foreignLedger,
    ) {}

    public function index(Request $request)
    {
        $companyId = $this->companyId($request);

        return Inertia::render('Statements/BankStatement/Index', [
            'bankAccounts'     => $this->bankAccountOptions($companyId),
            'creditFacilities' => $this->creditFacilityOptions($companyId),
        ]);
    }

    public function result(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $this->validateRequest($request);

        $account = $this->resolveAccount($companyId, $validated['source_type'], $validated['source_id']);

        if (! $account['coa_account_id']) {
            return back()->with('error', 'This account has no Chart of Accounts link — nothing to report.');
        }

        $rows = $this->statementService->paginate(
            $companyId,
            $account['coa_account_id'],
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            50,
            $account['balance_sign'],
            null,
            $account['is_native']
        );

        $totals = $this->statementService->totals(
            $companyId,
            $account['coa_account_id'],
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $account['balance_sign'],
            null,
            $account['is_native']
        );

        // H-25 — a foreign-currency account is reported in ITS OWN currency
        // (so it can be ticked against the bank's statement); lines whose
        // foreign amount was never recorded are counted so the page can warn.
        $totals['unknown_foreign_count'] = $account['is_native']
            ? $this->statementService->unknownForeignCount($companyId, $account['coa_account_id'], $validated['start_date'] ?? null, $validated['end_date'] ?? null)
            : 0;

        // Limit/Room only exist for a Credit Facility — a bank
        // account has neither. Always present (even with zero
        // transactions this period) since a facility's limit and
        // room aren't dependent on activity in the selected range.
        if ($account['source_type'] === self::SOURCE_CREDIT_FACILITY) {
            $totals['facility_limit'] = $account['facility_limit'];
            $totals['available_room'] = bcsub($account['facility_limit'], $totals['ending_balance'], 2);
        }

        return Inertia::render('Statements/BankStatement/Result', [
            'account' => $account,
            'rows'    => $rows,
            'totals'  => $totals,
            'filters' => [
                'source_type' => $validated['source_type'],
                'source_id'   => $validated['source_id'],
                'start_date'  => $validated['start_date'] ?? null,
                'end_date'    => $validated['end_date'] ?? null,
            ],
        ]);
    }

    public function exportExcel(Request $request)
    {
        $companyId = $this->companyId($request);
        $validated = $this->validateRequest($request);

        $account = $this->resolveAccount($companyId, $validated['source_type'], $validated['source_id']);

        $rows = $this->statementService->all(
            $companyId,
            $account['coa_account_id'],
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            $account['balance_sign'],
            null,
            $account['is_native']
        );

        $label = trim(($account['bank_name'] ?? '').' '.($account['account_number'] ?? '').($account['is_native'] ? ' ('.$account['currency_code'].')' : ''));

        return Excel::download(
            new BankStatementExport(
                $rows,
                $label ?: 'Bank Statement',
                $account['source_type'] === self::SOURCE_CREDIT_FACILITY ? $account['facility_limit'] : null
            ),
            'bank-statement-'.($account['account_number'] ?: $validated['source_id']).'.xlsx'
        );
    }

    // ── Private ──────────────────────────────────────────────────

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'source_type' => ['required', 'in:bank_account,credit_facility'],
            'source_id'   => ['required', 'integer'],
            'start_date'  => ['nullable', 'date'],
            // Mahmoud: end date can never be in the future.
            'end_date'    => ['nullable', 'date', 'before_or_equal:today'],
        ]);
    }

    /**
     * Resolves either source into one common shape the Vue page and
     * the export both read the same way — 'account_number' doubles
     * as the facility's own reference for a credit facility.
     * 'balance_sign' and 'facility_limit' are only meaningful for a
     * credit facility (null for a bank account).
     */
    private function resolveAccount(int $companyId, string $sourceType, int $sourceId): array
    {
        if ($sourceType === self::SOURCE_CREDIT_FACILITY) {
            $facility = CreditFacility::with(['bank:id,name_en,name_ar', 'currency:id,code'])
                ->where('company_id', $companyId)
                ->odFamily()
                ->findOrFail($sourceId);

            return [
                'id'             => $facility->id,
                'source_type'    => self::SOURCE_CREDIT_FACILITY,
                'bank_name'      => $facility->bank?->name_en,
                'account_type'   => $facility->facility_type,
                'account_number' => $facility->facility_reference,
                'currency_code'  => $facility->currency?->code,
                'coa_account_id' => $facility->coa_account_id,
                'balance_sign'   => \App\Services\Accounting\LedgerStatementService::SIGN_LIABILITY,
                'facility_limit' => (string) $facility->facility_limit,
                'is_native'      => $this->isNative($companyId, $facility->coa_account_id),
            ];
        }

        $account = BankAccount::with(['bank:id,name_en,name_ar', 'currency:id,code'])
            ->where('company_id', $companyId)
            ->findOrFail($sourceId);

        return [
            'id'             => $account->id,
            'source_type'    => self::SOURCE_BANK_ACCOUNT,
            'bank_name'      => $account->bank?->name_en,
            'account_type'   => $account->account_type,
            'account_number' => $account->account_number,
            'currency_code'  => $account->currency?->code,
            'coa_account_id' => $account->coa_account_id,
            'balance_sign'   => \App\Services\Accounting\LedgerStatementService::SIGN_ASSET,
            'facility_limit' => null,
            'is_native'      => $this->isNative($companyId, $account->coa_account_id),
        ];
    }

    /** True when this ledger account holds exactly one FOREIGN currency — then the statement is shown in that currency. */
    private function isNative(int $companyId, ?int $coaAccountId): bool
    {
        if (! $coaAccountId) {
            return false;
        }

        return array_key_exists($coaAccountId, $this->foreignLedger->accountCurrencyMap($companyId, [$coaAccountId]));
    }

    private function bankAccountOptions(int $companyId): array
    {
        return BankAccount::where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNotNull('coa_account_id')
            ->with(['bank:id,name_en', 'currency:id,code'])
            ->get(['id', 'bank_id', 'account_number', 'account_type', 'currency_id', 'coa_account_id'])
            ->map(fn ($a) => [
                'id'             => $a->id,
                'bank_name'      => $a->bank?->name_en,
                'account_number' => $a->account_number,
                'account_type'   => $a->account_type,
                'currency_code'  => $a->currency?->code,
            ])
            ->values()
            ->all();
    }

    private function creditFacilityOptions(int $companyId): array
    {
        return CreditFacility::where('company_id', $companyId)
            ->odFamily()
            ->whereNotNull('coa_account_id')
            ->with(['bank:id,name_en', 'currency:id,code'])
            ->get(['id', 'bank_id', 'facility_reference', 'facility_type', 'currency_id', 'coa_account_id'])
            ->map(fn ($f) => [
                'id'             => $f->id,
                'bank_name'      => $f->bank?->name_en,
                'account_number' => $f->facility_reference,
                'account_type'   => $f->facility_type,
                'currency_code'  => $f->currency?->code,
            ])
            ->values()
            ->all();
    }
}
