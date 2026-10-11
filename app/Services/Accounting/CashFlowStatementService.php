<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  CashFlowStatementService
//
//  Accounting > Financial Reports > Cash Flow Statement (Financial
//  Reports Module Structure v1.0, Section 3.6) — INDIRECT method,
//  confirmed with Mahmoud (2026-09), over one or more comparative
//  periods (Single / Monthly / Quarterly — same $periods contract as
//  Trial Balance / Income Statement / Balance Sheet). Each period is
//  a genuine movement range: Net Income for that range, + non-cash
//  addbacks, +/- the Balance Sheet movement between that range's
//  start and end.
//
//  ══ Financing vs. Operating within Current Liabilities — corrected
//     2026-09 after review, account by account, against the actual
//     seeded COA (ChartOfAccountSeeder::seedLiabilities()) ══
//  chart_of_accounts.account_type alone is NOT enough to separate
//  trade payables from short-term borrowing — "Current Liabilities"
//  covers both. Reviewed every account under 22x and classified each
//  one explicitly, by code:
//
//    Operating (trade/operational — stays in the working-capital walk):
//      221 Accounts Payable (+221001/221002), 222 Notes Payable
//      (+222001), 226 Taxes Payable (+226001-226005), 227 LG
//      Contingent Liabilities (dormant, always zero), 228 Accrued
//      Expenses, 231 Intercompany Payables (see note below), 232
//      Other Creditors, 233 GRNI, 235 Advances from Customers, 236
//      Deferred Revenue, 237 Direct Labor Clearing, 238 Manufacturing
//      Overhead Absorbed, 239 Salaries Payable, 240 Social Insurance
//      Payable, 241 End of Service Benefits Provision.
//
//    Financing (short-term borrowing / capital-related — moved OUT
//    of Operating, into Financing, by code prefix — see
//    FINANCING_LIABILITY_CODE_PREFIXES below):
//      223 Short-Term Credit Facilities incl. every overdraft type
//      and Import LC (223001-223006), 224 Factoring Liabilities incl.
//      both with/without recourse (224001-224002), 225 Current
//      Portion of Long-Term Loans, 229 Dividend Payable, 230
//      Shareholders Current Accounts, 234 Lease Liabilities — Current
//      Portion (the current-year slice of long-term lease debt, 213).
//
//    231 Intercompany Payables is left as Operating, matching its
//    asset-side counterpart 139 Intercompany Receivables — genuinely
//    ambiguous (could be a trade balance or a related-party loan)
//    without more detail than the COA itself carries. Flagged here
//    deliberately rather than silently guessed either way.
//
//  Investing — Fixed Assets (111/112/114, i.e. everything under
//  account_type 'Fixed Assets' — Accumulated Depreciation is its own
//  separate account_type and never appears here, so it's correctly
//  not double-counted against the D&A addback) and Long-Term Assets
//  (121/122/123).
//
//  Cash/Bank accounts (codes starting 131/132) are excluded from the
//  Operating working-capital walk — they're what the WHOLE statement
//  explains the movement of. Their beginning/ending balance is the
//  reconciliation check at the bottom, per period.
//
//  AUDIT C6 FIX (Sept 2026):
//    • 314 Retained Earnings / 315 were EXCLUDED from the walk. But
//      real postings do hit 314 — the migrated opening retained
//      earnings, dividend declarations (Dr 314 / Cr 229), prior-period
//      adjustments — and dropping them left the statement unable to
//      reconcile to the cash movement. Their LEDGER movement is now
//      included under Financing like every other equity account.
//      This cannot double-count profit: net income is taken from the
//      revenue/expense accounts, and no closing entry ever posts
//      profit into 314/315 under Option A.
//    • Balances now come from the shared AccountBalanceCalculator.
//      This service's private copy still skipped 'reversed' journal
//      entries — the ACC-002 bug already fixed everywhere else — so a
//      reversal counted only its mirror half and showed a cancelled
//      transaction as a negative movement.
//
//  AUDIT H17 / H19 (Sept 2026):
//    • Contra current assets (149 ECL allowance, 150 obsolescence —
//      credit-normal) are walked like liabilities: an increase is a
//      non-cash charge added back.
//    • Accumulated depreciation released on disposal (or added by an
//      impairment) gets its own non-cash Investing row — see
//      accumulatedDepreciationAdjustmentRow().
//
//  bcmath throughout (Rule 4). Read-only.
//
//  @layer    Service
//  @depends  ChartOfAccount, JournalEntry, JournalEntryLine,
//            IncomeStatementService, AccountBalanceCalculator
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Carbon\Carbon;

class CashFlowStatementService
{
    private const CASH_CODE_PREFIXES = ['131', '132'];
    private const INVESTING_TYPES = ['Fixed Assets', 'Long-Term Assets'];

    // See class header — reviewed against the real COA, not inferred
    // from account_type. Every code here starts a Current Liabilities
    // account that is really short-term borrowing/capital, not trade.
    private const FINANCING_LIABILITY_CODE_PREFIXES = ['223', '224', '225', '229', '230', '234'];

    public function __construct(
        private IncomeStatementService $incomeStatementService,
        private AccountBalanceCalculator $balanceCalculator,
    ) {
    }

    /**
     * Returns:
     *   [
     *     'periods' => the same $periods array, echoed back,
     *     'net_income' => [periodKey => '...'],
     *     'depreciation_amortization' => [periodKey => '...'],
     *     'operating' => ['rows' => [...], 'totals' => [periodKey => '...']],
     *     'investing' => ['rows' => [...], 'totals' => [periodKey => '...']],
     *     'financing' => ['rows' => [...], 'totals' => [periodKey => '...']],
     *     'net_change_in_cash' => [periodKey => '...'],
     *     'cash_beginning' => [periodKey => '...'], 'cash_ending' => [periodKey => '...'],
     *     'actual_change_in_cash' => [periodKey => '...'],
     *     'is_reconciled' => [periodKey => bool],
     *     'reconciliation_difference' => [periodKey => '...'],
     *   ]
     * Each row: ['id','code','name_en','name_ar','periods' => [periodKey => cashImpact]].
     */
    public function build(int $companyId, array $periods, ?int $costCenterId = null): array
    {
        if (empty($periods)) {
            return [
                'periods' => [], 'net_income' => [], 'depreciation_amortization' => [],
                'operating' => ['rows' => [], 'totals' => []], 'investing' => ['rows' => [], 'totals' => []],
                'financing' => ['rows' => [], 'totals' => []], 'net_change_in_cash' => [],
                'cash_beginning' => [], 'cash_ending' => [], 'actual_change_in_cash' => [],
                'is_reconciled' => [], 'reconciliation_difference' => [],
            ];
        }

        $periodKeys   = array_column($periods, 'key');
        $zeroByPeriod = array_fill_keys($periodKeys, '0.00');

        $accounts = ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->postable()->active()
            ->whereIn('class', [ChartOfAccount::CLASS_ASSETS, ChartOfAccount::CLASS_LIABILITIES, ChartOfAccount::CLASS_EQUITY])
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'class', 'account_type', 'normal_balance'])
            ->keyBy('id');

        // ── Net income + D&A addback, per period ────────────────
        $netIncomeByPeriod = $zeroByPeriod;
        $daByPeriod = $zeroByPeriod;
        foreach ($periods as $period) {
            $netIncomeByPeriod[$period['key']] = $this->incomeStatementService->netIncomeForRange($companyId, $period['start_date'], $period['end_date'], $costCenterId);
            $daByPeriod[$period['key']] = $this->incomeStatementService->expenseCategoryTotalForRange($companyId, 'Depreciation & Amortization', $period['start_date'], $period['end_date'], $costCenterId);
        }

        // ── Beginning/ending Balance Sheet balances, per period ──
        $beginningByPeriod = [];
        $endingByPeriod    = [];
        foreach ($periods as $period) {
            $beginningDate = Carbon::parse($period['start_date'])->subDay()->toDateString();
            $beginningByPeriod[$period['key']] = $this->balancesAsOf($companyId, $beginningDate, $accounts, $costCenterId);
            $endingByPeriod[$period['key']]    = $this->balancesAsOf($companyId, $period['end_date'], $accounts, $costCenterId);
        }

        // ── Operating ────────────────────────────────────────────
        $operatingRows = [];
        $operatingTotals = $zeroByPeriod;
        foreach ($periodKeys as $key) {
            $operatingTotals[$key] = bcadd($netIncomeByPeriod[$key], $daByPeriod[$key], 2);
        }

        foreach ($accounts->where('account_type', 'Current Assets') as $account) {
            if ($this->isCashAccount($account->code)) {
                continue;
            }
            // Audit H19: a contra asset (credit-normal: 149 ECL allowance,
            // 150 obsolescence) behaves like a liability — its increase is
            // a non-cash charge added back, not cash consumed.
            $isContra = $account->normal_balance === ChartOfAccount::BALANCE_CREDIT;
            $this->accumulateDeltaRows($account, $periods, $beginningByPeriod, $endingByPeriod, $isContra, $operatingRows, $operatingTotals);
        }
        foreach ($accounts->where('account_type', 'Current Liabilities') as $account) {
            if ($this->isFinancingLiability($account->code)) {
                continue; // handled in Financing below
            }
            $this->accumulateDeltaRows($account, $periods, $beginningByPeriod, $endingByPeriod, true, $operatingRows, $operatingTotals);
        }

        // ── Investing ────────────────────────────────────────────
        $investingRows = [];
        $investingTotals = $zeroByPeriod;
        foreach (self::INVESTING_TYPES as $type) {
            foreach ($accounts->where('account_type', $type) as $account) {
                $this->accumulateDeltaRows($account, $periods, $beginningByPeriod, $endingByPeriod, false, $investingRows, $investingTotals);
            }
        }

        // Audit H17: accumulated depreciation moves for reasons other than
        // the D&A charge already added back above — it is RELEASED when an
        // asset is sold or scrapped, and INCREASED by an impairment. Neither
        // is cash. Without this row, selling an asset left the statement
        // out by the asset's accumulated depreciation.
        //   row = change in accumulated depreciation − D&A charge
        // (disposal: negative, so Investing shows the net book value
        //  leaving; impairment: positive, adding back the non-cash loss).
        $this->accumulatedDepreciationAdjustmentRow($accounts, $periods, $beginningByPeriod, $endingByPeriod, $daByPeriod, $investingRows, $investingTotals);

        // ── Financing ────────────────────────────────────────────
        $financingRows = [];
        $financingTotals = $zeroByPeriod;
        foreach ($accounts->where('account_type', 'Long-term Liabilities') as $account) {
            $this->accumulateDeltaRows($account, $periods, $beginningByPeriod, $endingByPeriod, true, $financingRows, $financingTotals);
        }
        foreach ($accounts->where('account_type', 'Current Liabilities') as $account) {
            if (! $this->isFinancingLiability($account->code)) {
                continue;
            }
            $this->accumulateDeltaRows($account, $periods, $beginningByPeriod, $endingByPeriod, true, $financingRows, $financingTotals);
        }
        foreach ($accounts->where('class', ChartOfAccount::CLASS_EQUITY) as $account) {
            $this->accumulateDeltaRows($account, $periods, $beginningByPeriod, $endingByPeriod, true, $financingRows, $financingTotals);
        }

        // ── Net change + reconciliation, per period ─────────────
        $netChangeInCash = [];
        foreach ($periodKeys as $key) {
            $netChangeInCash[$key] = bcadd(bcadd($operatingTotals[$key], $investingTotals[$key], 2), $financingTotals[$key], 2);
        }

        $cashAccountIds = $accounts->filter(fn ($a) => $this->isCashAccount($a->code))->keys();
        $cashBeginning = $zeroByPeriod;
        $cashEnding    = $zeroByPeriod;
        foreach ($periodKeys as $key) {
            foreach ($cashAccountIds as $id) {
                $cashBeginning[$key] = bcadd($cashBeginning[$key], $beginningByPeriod[$key][$id] ?? '0.00', 2);
                $cashEnding[$key]    = bcadd($cashEnding[$key], $endingByPeriod[$key][$id] ?? '0.00', 2);
            }
        }

        $actualChange = [];
        $reconciliationDifference = [];
        $isReconciled = [];
        foreach ($periodKeys as $key) {
            $actualChange[$key] = bcsub($cashEnding[$key], $cashBeginning[$key], 2);
            $reconciliationDifference[$key] = bcsub($netChangeInCash[$key], $actualChange[$key], 2);
            $isReconciled[$key] = bccomp($reconciliationDifference[$key], '0.00', 2) === 0;
        }

        return [
            'periods'    => $periods,
            'net_income' => $netIncomeByPeriod,
            'depreciation_amortization' => $daByPeriod,
            'operating'  => ['rows' => $operatingRows, 'totals' => $operatingTotals],
            'investing'  => ['rows' => $investingRows, 'totals' => $investingTotals],
            'financing'  => ['rows' => $financingRows, 'totals' => $financingTotals],
            'net_change_in_cash'    => $netChangeInCash,
            'cash_beginning'        => $cashBeginning,
            'cash_ending'           => $cashEnding,
            'actual_change_in_cash' => $actualChange,
            'is_reconciled'         => $isReconciled,
            'reconciliation_difference' => $reconciliationDifference,
        ];
    }

    /**
     * Computes this account's cash impact for every period (ending −
     * beginning, sign-flipped for an asset since an asset increase
     * consumes cash; a liability/equity increase provides cash as-is)
     * and appends a row + accumulates into $totals if ANY period is
     * non-zero — shared by the Operating/Investing/Financing walks
     * above so the sign rule is written in exactly one place.
     */
    private function accumulateDeltaRows(ChartOfAccount $account, array $periods, array $beginningByPeriod, array $endingByPeriod, bool $increaseProvidesCash, array &$rows, array &$totals): void
    {
        $rowPeriods = [];
        $anyNonZero = false;
        foreach ($periods as $period) {
            $key = $period['key'];
            $delta = bcsub($endingByPeriod[$key][$account->id] ?? '0.00', $beginningByPeriod[$key][$account->id] ?? '0.00', 2);
            $cashImpact = $increaseProvidesCash ? $delta : bcmul($delta, '-1', 2);
            $rowPeriods[$key] = $cashImpact;
            if (bccomp($cashImpact, '0.00', 2) !== 0) {
                $anyNonZero = true;
                $totals[$key] = bcadd($totals[$key], $cashImpact, 2);
            }
        }

        if ($anyNonZero) {
            $rows[] = [
                'id' => $account->id, 'code' => $account->code,
                'name_en' => $account->name_en, 'name_ar' => $account->name_ar,
                'periods' => $rowPeriods,
            ];
        }
    }

    /** Audit H17 — see the call site in build(). */
    private function accumulatedDepreciationAdjustmentRow($accounts, array $periods, array $beginningByPeriod, array $endingByPeriod, array $daByPeriod, array &$rows, array &$totals): void
    {
        $adAccounts = $accounts->where('account_type', 'Accumulated Depreciation');
        if ($adAccounts->isEmpty()) {
            return;
        }

        $rowPeriods = [];
        $anyNonZero = false;
        foreach ($periods as $period) {
            $key = $period['key'];
            $delta = '0.00';
            foreach ($adAccounts as $account) {
                $delta = bcadd($delta, bcsub($endingByPeriod[$key][$account->id] ?? '0.00', $beginningByPeriod[$key][$account->id] ?? '0.00', 2), 2);
            }
            $impact = bcsub($delta, $daByPeriod[$key] ?? '0.00', 2);
            $rowPeriods[$key] = $impact;
            if (bccomp($impact, '0.00', 2) !== 0) {
                $anyNonZero = true;
                $totals[$key] = bcadd($totals[$key], $impact, 2);
            }
        }

        if ($anyNonZero) {
            $rows[] = [
                'id' => null, 'code' => null,
                'name_en' => 'Accumulated depreciation on assets disposed / impaired (non-cash)',
                'name_ar' => 'مجمع إهلاك الأصول المستبعدة / المضمحلة (غير نقدي)',
                'periods' => $rowPeriods,
            ];
        }
    }

    private function isCashAccount(string $code): bool
    {
        foreach (self::CASH_CODE_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isFinancingLiability(string $code): bool
    {
        foreach (self::FINANCING_LIABILITY_CODE_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every account's balance as of a date — the ONE shared balance
     * engine (AccountBalanceCalculator), same as the Balance Sheet, so
     * the two statements can never disagree (Audit C6).
     */
    private function balancesAsOf(int $companyId, string $asOfDate, $accounts, ?int $costCenterId): array
    {
        return $this->balanceCalculator->balancesAsOf($companyId, $asOfDate, $accounts, $costCenterId);
    }
}
