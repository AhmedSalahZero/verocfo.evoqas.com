<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  BalanceSheetService
//
//  Accounting > Financial Reports > Balance Sheet (Financial Reports
//  Module Structure v1.0, Section 3.5) — every account grouped by
//  chart_of_accounts.account_type, over one or more comparative
//  periods (Single / Monthly / Quarterly, same $periods contract as
//  Trial Balance / Income Statement). A Balance Sheet is a snapshot,
//  not a range — each period's column is the balance AS OF that
//  period's end_date; period.start_date is not used here at all.
//
//  ══ Year-end closing — Option A (confirmed with Mahmoud, 2026-09) ══
//  No automatic year-end closing entry is ever posted. 314 (Retained
//  Earnings) and 315 (Current Year Profit/Loss) are therefore
//  computed live, PER PERIOD COLUMN (each column's own as-of date):
//
//    315 = 315's own ledger balance (normally zero)
//          + IncomeStatementService::netIncomeForRange() from the
//          start of the fiscal year covering that column's date,
//          through that date.
//    314 = 314's own LEDGER balance as of that date
//          + net income of every fiscal year that ENDED before that
//          column's fiscal year started (closed or not — ACC-001).
//
//  AUDIT C6 FIX (Sept 2026): 314 used to be rebuilt from income and
//  expense accounts ONLY — its real ledger balance was thrown away.
//  Anything genuinely posted to 314 therefore vanished from the
//  Balance Sheet: the migrated retained earnings in the opening
//  entry, dividend declarations (Dr 314 / Cr 229), prior-period
//  adjustments. A company that migrated in with accumulated profits
//  got a Balance Sheet out by exactly that amount. The ledger
//  balance of 314 (and 315) is now always added in, and the two
//  lines are shown even if 314/315 has been split into sub-accounts
//  or made non-postable.
//
//  NOT built here (out of scope, flagged not silently skipped):
//    - 234's current/long-term split as a supplementary disclosure
//      note (Rule 29.5's liabilitySplitAsOf()-style logic).
//    - Foreign-currency dual view (N/A — functional-currency by
//      construction, IFRS requirement).
//
//  bcmath throughout (Rule 4). Read-only.
//
//  Sept 2026 — balancesAsOf() extracted to AccountBalanceCalculator
//  (confirmed refactor, logic unchanged) so it can be reused outside
//  this service — see that class's header. This service now injects
//  it rather than computing balances inline.
//
//  AUDIT H19 (Sept 2026): credit-normal accounts inside an asset
//  section (149 Allowance for Expected Credit Losses, 150 Provision
//  for Inventory Obsolescence) are contra assets — buildSection()
//  shows them as negatives so they reduce Current Assets.
//
//  @layer    Service
//  @depends  ChartOfAccount, JournalEntry, JournalEntryLine,
//            FiscalYear, IncomeStatementService,
//            AccountBalanceCalculator
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;

class BalanceSheetService
{
    private const ASSET_ORDER     = ['Current Assets', 'Fixed Assets', 'Long-Term Assets'];
    private const LIABILITY_ORDER = ['Current Liabilities', 'Long-term Liabilities'];

    private const ACCOUNT_TYPE_ACCUMULATED_DEPRECIATION = 'Accumulated Depreciation';

    private const CODE_RETAINED_EARNINGS   = '314';
    private const CODE_CURRENT_YEAR_PROFIT = '315';

    public function __construct(
        private IncomeStatementService $incomeStatementService,
        private AccountBalanceCalculator $balanceCalculator,
    )
    {
    }

    /**
     * Returns:
     *   [
     *     'periods'     => the same $periods array, echoed back,
     *     'assets'      => ['sections' => [...], 'totals' => [periodKey => '...']],
     *     'liabilities' => ['sections' => [...], 'totals' => [periodKey => '...']],
     *     'equity'      => ['sections' => [...], 'totals' => [periodKey => '...']],
     *     'total_liabilities_and_equity' => [periodKey => '...'],
     *     'is_balanced' => [periodKey => bool],
     *     'difference'  => [periodKey => '...'],
     *   ]
     * Each section: ['account_type' => ..., 'rows' => [...], 'totals' => [periodKey => '...']].
     * Each row: ['id','code','name_en','name_ar','periods' => [periodKey => amount]].
     */
    public function build(int $companyId, array $periods, ?int $costCenterId = null): array
    {
        if (empty($periods)) {
            return [
                'periods' => [], 'assets' => ['sections' => [], 'totals' => []],
                'liabilities' => ['sections' => [], 'totals' => []], 'equity' => ['sections' => [], 'totals' => []],
                'total_liabilities_and_equity' => [], 'is_balanced' => [], 'difference' => [],
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

        // C6: 314 / 315 are looked up on their own (not only among the
        // postable, active accounts) so their live lines always appear.
        $retainedEarningsAccount  = $this->equityAccount($companyId, self::CODE_RETAINED_EARNINGS);
        $currentYearProfitAccount = $this->equityAccount($companyId, self::CODE_CURRENT_YEAR_PROFIT);
        $accounts = $accounts->reject(fn ($a) => in_array($a->code, [self::CODE_RETAINED_EARNINGS, self::CODE_CURRENT_YEAR_PROFIT], true));

        // Their own ledger balances (what was really posted to them).
        $equityLedgerAccounts = collect([$retainedEarningsAccount, $currentYearProfitAccount])
            ->filter()
            ->keyBy('id');

        // ── One balances-as-of query per period, keyed by each period's end_date ──
        $balancesByPeriod = [];
        foreach ($periods as $period) {
            $balancesByPeriod[$period['key']] = $this->balancesAsOf($companyId, $period['end_date'], $accounts, $costCenterId);
        }

        // ── 314/315, computed live, per period column ───────────
        $currentYearProfitByPeriod = $zeroByPeriod;
        $retainedEarningsByPeriod  = $zeroByPeriod;
        foreach ($periods as $period) {
            [$cyp, $re] = $this->computeEquityRollForward($companyId, $period['end_date'], $costCenterId);

            // C6: add what was actually posted to 314 / 315.
            $ledger = $this->balancesAsOf($companyId, $period['end_date'], $equityLedgerAccounts, $costCenterId);
            if ($retainedEarningsAccount) {
                $re = bcadd($re, $ledger[$retainedEarningsAccount->id] ?? '0.00', 2);
            }
            if ($currentYearProfitAccount) {
                $cyp = bcadd($cyp, $ledger[$currentYearProfitAccount->id] ?? '0.00', 2);
            }

            $currentYearProfitByPeriod[$period['key']] = $cyp;
            $retainedEarningsByPeriod[$period['key']]  = $re;
        }

        // ── Assets ───────────────────────────────────────────────
        $accumulatedDepreciationByPeriod = $this->typeTotals($accounts, $balancesByPeriod, $periodKeys, self::ACCOUNT_TYPE_ACCUMULATED_DEPRECIATION);
        $assetSections = [];
        $totalAssets   = $zeroByPeriod;
        foreach (self::ASSET_ORDER as $type) {
            $section = $this->buildSection($accounts, $balancesByPeriod, $periodKeys, $type);
            if (! $section) {
                continue;
            }
            if ($type === 'Fixed Assets' && $this->anyNonZero($accumulatedDepreciationByPeriod)) {
                $negated = [];
                foreach ($periodKeys as $key) {
                    $negated[$key] = bcmul($accumulatedDepreciationByPeriod[$key], '-1', 2);
                    $section['totals'][$key] = bcsub($section['totals'][$key], $accumulatedDepreciationByPeriod[$key], 2);
                }
                $section['rows'][] = [
                    'id' => null, 'code' => null,
                    'name_en' => 'Less: Accumulated Depreciation & Amortization',
                    'name_ar' => 'يخصم: مجمع الإهلاك والاستهلاك',
                    'periods' => $negated,
                ];
            }
            foreach ($periodKeys as $key) {
                $totalAssets[$key] = bcadd($totalAssets[$key], $section['totals'][$key], 2);
            }
            $assetSections[] = $section;
        }

        // ── Liabilities ──────────────────────────────────────────
        $liabilitySections = [];
        $totalLiabilities  = $zeroByPeriod;
        foreach (self::LIABILITY_ORDER as $type) {
            $section = $this->buildSection($accounts, $balancesByPeriod, $periodKeys, $type);
            if (! $section) {
                continue;
            }
            foreach ($periodKeys as $key) {
                $totalLiabilities[$key] = bcadd($totalLiabilities[$key], $section['totals'][$key], 2);
            }
            $liabilitySections[] = $section;
        }

        // ── Equity ───────────────────────────────────────────────
        $equityRows  = [];
        $totalEquity = $zeroByPeriod;
        foreach ($accounts->where('class', ChartOfAccount::CLASS_EQUITY) as $account) {
            $rowPeriods = [];
            $anyNonZero = false;
            foreach ($periodKeys as $key) {
                $amount = $balancesByPeriod[$key][$account->id] ?? '0.00';
                $rowPeriods[$key] = $amount;
                if (bccomp($amount, '0.00', 2) !== 0) {
                    $anyNonZero = true;
                    $totalEquity[$key] = bcadd($totalEquity[$key], $amount, 2);
                }
            }
            if ($anyNonZero) {
                $equityRows[] = $this->row($account, $rowPeriods);
            }
        }

        if ($retainedEarningsAccount && $this->anyNonZero($retainedEarningsByPeriod)) {
            foreach ($periodKeys as $key) {
                $totalEquity[$key] = bcadd($totalEquity[$key], $retainedEarningsByPeriod[$key], 2);
            }
            $equityRows[] = $this->row($retainedEarningsAccount, $retainedEarningsByPeriod);
        }
        if ($currentYearProfitAccount && $this->anyNonZero($currentYearProfitByPeriod)) {
            foreach ($periodKeys as $key) {
                $totalEquity[$key] = bcadd($totalEquity[$key], $currentYearProfitByPeriod[$key], 2);
            }
            $equityRows[] = $this->row($currentYearProfitAccount, $currentYearProfitByPeriod);
        }

        $totalLiabilitiesAndEquity = [];
        $isBalanced = [];
        $difference = [];
        foreach ($periodKeys as $key) {
            $totalLiabilitiesAndEquity[$key] = bcadd($totalLiabilities[$key], $totalEquity[$key], 2);
            $difference[$key]  = bcsub($totalAssets[$key], $totalLiabilitiesAndEquity[$key], 2);
            $isBalanced[$key]  = bccomp($difference[$key], '0.00', 2) === 0;
        }

        return [
            'periods'     => $periods,
            'assets'      => ['sections' => $assetSections, 'totals' => $totalAssets],
            'liabilities' => ['sections' => $liabilitySections, 'totals' => $totalLiabilities],
            'equity'      => ['sections' => [['account_type' => 'Equity', 'rows' => $equityRows, 'totals' => $totalEquity]], 'totals' => $totalEquity],
            'total_liabilities_and_equity' => $totalLiabilitiesAndEquity,
            'is_balanced' => $isBalanced,
            'difference'  => $difference,
        ];
    }

    /** [currentYearProfit, retainedEarnings] as of one date — see class header. */
    private function computeEquityRollForward(int $companyId, string $asOfDate, ?int $costCenterId): array
    {
        $currentFy = FiscalYear::where('company_id', $companyId)
            ->where('start_date', '<=', $asOfDate)
            ->where('end_date', '>=', $asOfDate)
            ->first();

        if (! $currentFy) {
            return ['0.00', '0.00'];
        }

        $currentYearProfit = $this->incomeStatementService->netIncomeForRange(
            $companyId, $currentFy->start_date->toDateString(), $asOfDate, $costCenterId
        );

        // ACC-001 fix (confirmed with Mahmoud, 2026-09) — a prior year
        // contributes to Retained Earnings once it has ENDED, whether
        // or not it has been formally closed.
        //
        // This used to filter on status = CLOSED. A year that had ended
        // but was still open therefore fell between the two equity
        // lines: Current Year Profit covers only the current year, and
        // Retained Earnings covered only formally-closed ones, so that
        // year's profit was reported nowhere and the Balance Sheet
        // showed Assets != Liabilities + Equity by exactly its net
        // income. Confirmed on live data: company 27 was out by
        // 444,354,796.99 — the sum of FY2024 and FY2025 to the cent.
        //
        // Formal closing remains a separate control: FiscalYearService
        // still refuses postings into a closed year. What it no longer
        // does is decide whether profit that has already been earned
        // exists. This is what makes the accounting identity hold under
        // the "Option A" model this system uses, where no closing
        // entries are ever posted and 314/315 are computed live.
        $priorEndedFys = FiscalYear::where('company_id', $companyId)
            ->where('end_date', '<', $currentFy->start_date)
            ->get(['start_date', 'end_date']);

        // Only the profit of ended years is summed here. The migrated
        // retained-earnings figure arrives through the opening journal
        // entry into 314 itself, and is added by build() from 314's
        // ledger balance (Audit C6). The earlier comment here said
        // netIncomeForRange() "already sees" it — it does not: that
        // function reads revenue and expense accounts only.
        $retainedEarnings = '0.00';
        foreach ($priorEndedFys as $fy) {
            $retainedEarnings = bcadd(
                $retainedEarnings,
                $this->incomeStatementService->netIncomeForRange($companyId, $fy->start_date->toDateString(), $fy->end_date->toDateString(), $costCenterId),
                2
            );
        }

        return [$currentYearProfit, $retainedEarnings];
    }

    /**
     * 314 or 315 for this company (system row or company row), with the
     * columns the balance engine needs. Null if the chart has no such
     * account. Not limited to postable/active on purpose (C6).
     */
    private function equityAccount(int $companyId, string $code): ?ChartOfAccount
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->where('code', $code)
            ->first(['id', 'code', 'name_en', 'name_ar', 'class', 'account_type', 'normal_balance']);
    }

    private function buildSection($accounts, array $balancesByPeriod, array $periodKeys, string $type): ?array
    {
        $typeAccounts = $accounts->where('account_type', $type);
        if ($typeAccounts->isEmpty()) {
            return null;
        }

        $rows  = [];
        $totals = array_fill_keys($periodKeys, '0.00');
        foreach ($typeAccounts as $account) {
            $rowPeriods = [];
            $anyNonZero = false;
            // Audit H19: a CONTRA asset (credit-normal account inside an
            // asset section — 149 Allowance for ECL, 150 Inventory
            // Obsolescence) reduces the section: shown and summed negative.
            $isContra = $account->class === ChartOfAccount::CLASS_ASSETS
                && $account->normal_balance === ChartOfAccount::BALANCE_CREDIT;
            foreach ($periodKeys as $key) {
                $amount = $balancesByPeriod[$key][$account->id] ?? '0.00';
                if ($isContra) {
                    $amount = bcmul($amount, '-1', 2);
                }
                $rowPeriods[$key] = $amount;
                if (bccomp($amount, '0.00', 2) !== 0) {
                    $anyNonZero = true;
                    $totals[$key] = bcadd($totals[$key], $amount, 2);
                }
            }
            if ($anyNonZero) {
                $rows[] = $this->row($account, $rowPeriods);
            }
        }

        if (empty($rows)) {
            return null;
        }

        return ['account_type' => $type, 'rows' => $rows, 'totals' => $totals];
    }

    private function typeTotals($accounts, array $balancesByPeriod, array $periodKeys, string $type): array
    {
        $totals = array_fill_keys($periodKeys, '0.00');
        foreach ($accounts->where('account_type', $type) as $account) {
            foreach ($periodKeys as $key) {
                $totals[$key] = bcadd($totals[$key], $balancesByPeriod[$key][$account->id] ?? '0.00', 2);
            }
        }

        return $totals;
    }

    private function anyNonZero(array $byPeriod): bool
    {
        foreach ($byPeriod as $amount) {
            if (bccomp($amount, '0.00', 2) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every account's net balance as of (INCLUSIVE) $asOfDate.
     *
     * Extracted to AccountBalanceCalculator (Sept 2026) — confirmed
     * refactor, logic unchanged — so Annual Plan / Business Plan &
     * Valuation's Opening Balance step can reuse the exact same
     * "one shared balance engine" this report already established,
     * instead of a second implementation of the same query. See that
     * class's header for the full rationale.
     */
    private function balancesAsOf(int $companyId, string $asOfDate, $accounts, ?int $costCenterId): array
    {
        return $this->balanceCalculator->balancesAsOf($companyId, $asOfDate, $accounts, $costCenterId);
    }

    private function row(ChartOfAccount $account, array $periodAmounts): array
    {
        return [
            'id'      => $account->id,
            'code'    => $account->code,
            'name_en' => $account->name_en,
            'name_ar' => $account->name_ar,
            'periods' => $periodAmounts,
        ];
    }
}
