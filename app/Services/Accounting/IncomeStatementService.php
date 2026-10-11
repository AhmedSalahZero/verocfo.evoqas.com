<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  IncomeStatementService
//
//  Accounting > Financial Reports > Income Statement (Financial
//  Reports Module Structure v1.0, Section 3.4) — revenue minus the
//  six fixed P&L expense categories, over one or more comparative
//  periods (Single / Monthly / Quarterly — same three view modes as
//  Trial Balance, and the same contract: $periods is an ordered
//  array of ['key','label','start_date','end_date'] built by the
//  controller from the company's real accounting_periods rows,
//  never a hardcoded calendar-month grid).
//
//  Classification comes straight from chart_of_accounts.account_type
//  (see ChartOfAccountSeeder::seedExpenses()/seedRevenue()).
//
//  Performance: one query PER PERIOD (not per account × period —
//  same discipline as TrialBalanceService::movementsByAccount()),
//  regardless of how many accounts exist.
//
//  netIncomeForRange() and expenseCategoryTotalForRange() are
//  single-range helpers (no periods array) reused directly by
//  BalanceSheetService (315/314, computed live — Option A) and
//  CashFlowStatementService (indirect method, per-period).
//
//  AUDIT H19 (Sept 2026) — subtotals down to Profit After Tax:
//      Revenue
//    − Cost of Goods Sold                      → Gross Profit
//    − Sales, Marketing, G&A, Depreciation     → Operating Profit (EBIT)
//    − Finance Expenses (incl. FX losses)      → Profit Before Tax
//    − Income Tax (account_type 'Income Tax',  → Profit After Tax
//      accounts 591 / 592 — NOT one of the six
//      expense categories, shown on its own
//      line below Profit Before Tax, IAS 12)
//  Finance Expenses now come after Depreciation & Amortization so the
//  operating lines sit together above Operating Profit. (M8: interest /
//  investment income and FX gains, accounts 42x / 43x, now come AFTER
//  Operating Profit — see below.)
//  'net_income' is Profit After Tax (unchanged meaning for every
//  consumer — the Balance Sheet and Cash Flow already took profit
//  after every expense account, tax included).
//
//  AUDIT M8 (Oct 2026) — Gross Profit is Sales Revenue (accounts 41x)
//  less Cost of Goods Sold, and nothing else. "Other income" (42x:
//  scrap, commission, interest, disposal gains) and FX gains (43x) used
//  to sit in Revenue and inflate Gross Profit. They are now reported in
//  their own block AFTER Operating Profit, then Finance Expenses (which
//  carry FX losses) follow. Profit Before Tax and Profit After Tax are
//  unchanged — only where the subtotals fall has moved:
//      Sales Revenue − COGS                       = Gross Profit
//      Gross Profit − Sales/Marketing/G&A/D&A     = Operating Profit
//      Operating Profit + Other income & FX gains
//                       − Finance Expenses        = Profit Before Tax
//  'revenue' (all revenue) is still returned for the Balance Sheet,
//  Cash Flow and any other consumer.
//
//  bcmath throughout (Rule 4). Read-only.
//
//  @layer    Service
//  @depends  ChartOfAccount, JournalEntry, JournalEntryLine
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;

class IncomeStatementService
{
    private const EXPENSE_ORDER = [
        'Cost of Goods Sold',
        'Sales Expenses',
        'Marketing Expenses',
        'General & Administrative Expenses',
        'Depreciation & Amortization',
        'Finance Expenses',
    ];

    private const FINANCE_TYPE    = 'Finance Expenses';
    public const  INCOME_TAX_TYPE = 'Income Tax';

    private const GROSS_PROFIT_AFTER = 'Cost of Goods Sold';

    // M8 — revenue accounts under 41 (Sales Revenue) feed Gross Profit;
    // everything else in the revenue class is other income / FX gain.
    private const SALES_CODE_PREFIX = '41';

    /**
     * Returns:
     *   [
     *     'periods' => the same $periods array, echoed back,
     *     'revenue' => ['rows' => [...], 'totals' => [periodKey => '...']],
     *     'gross_profit'     => [periodKey => '...'],
     *     'expense_sections' => [
     *       ['account_type' => 'Cost of Goods Sold', 'rows' => [...], 'totals' => [periodKey => '...']],
     *       ...
     *     ],
     *     'total_expenses' => [periodKey => '...'],
     *     'net_income'     => [periodKey => '...'],
     *   ]
     * Each row: ['id','code','name_en','name_ar','periods' => [periodKey => amount]] —
     * amount already sign-corrected (positive = normal activity).
     * A row/section is included if it has non-zero activity in ANY
     * period — once included, every period's figure shows, even a
     * zero one, so columns stay aligned.
     */
    public function build(int $companyId, array $periods, ?int $costCenterId = null): array
    {
        if (empty($periods)) {
            return [
                'periods' => [], 'revenue' => ['rows' => [], 'totals' => []],
                'gross_profit' => [], 'expense_sections' => [], 'total_expenses' => [], 'net_income' => [],
                'operating_profit' => [], 'ebit' => [], 'profit_before_tax' => [],
                'income_tax' => ['rows' => [], 'totals' => []], 'profit_after_tax' => [],
                'sales_revenue' => ['rows' => [], 'totals' => []], 'other_income' => ['rows' => [], 'totals' => []],
            ];
        }

        $accounts = ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->postable()->active()
            ->whereIn('class', [ChartOfAccount::CLASS_REVENUE, ChartOfAccount::CLASS_EXPENSES])
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'class', 'account_type', 'normal_balance'])
            ->keyBy('id');

        $periodKeys = array_column($periods, 'key');
        $zeroByPeriod = array_fill_keys($periodKeys, '0.00');

        // ── One query per period ────────────────────────────────
        $movementsByPeriod = [];
        foreach ($periods as $period) {
            $movementsByPeriod[$period['key']] = $this->movementsByAccount($companyId, $period['start_date'], $period['end_date'], $costCenterId);
        }

        // ── Revenue ──────────────────────────────────────────────
        $revenueRows   = [];
        $revenueTotals = $zeroByPeriod;
        // M8 — the same rows, split into Sales (41x) and Other income / FX gains.
        $salesRows   = [];
        $salesTotals = $zeroByPeriod;
        $otherRows   = [];
        $otherTotals = $zeroByPeriod;
        foreach ($accounts->where('class', ChartOfAccount::CLASS_REVENUE) as $account) {
            $isSales = str_starts_with((string) $account->code, self::SALES_CODE_PREFIX);
            $rowPeriods = [];
            $anyNonZero = false;
            foreach ($periods as $period) {
                $amount = $this->signedAmount($movementsByPeriod[$period['key']], $account);
                $rowPeriods[$period['key']] = $amount;
                if (bccomp($amount, '0.00', 2) !== 0) {
                    $anyNonZero = true;
                    $revenueTotals[$period['key']] = bcadd($revenueTotals[$period['key']], $amount, 2);
                    if ($isSales) {
                        $salesTotals[$period['key']] = bcadd($salesTotals[$period['key']], $amount, 2);
                    } else {
                        $otherTotals[$period['key']] = bcadd($otherTotals[$period['key']], $amount, 2);
                    }
                }
            }
            if ($anyNonZero) {
                $built = $this->row($account, $rowPeriods);
                $revenueRows[] = $built;
                if ($isSales) {
                    $salesRows[] = $built;
                } else {
                    $otherRows[] = $built;
                }
            }
        }

        // ── Expenses, grouped by account_type ───────────────────
        $expenseAccounts = $accounts->where('class', ChartOfAccount::CLASS_EXPENSES);
        $byType = $expenseAccounts->groupBy('account_type');
        // H19: Income Tax is never an operating expense section — it is
        // built separately below, under Profit Before Tax.
        $typeOrder = array_values(array_diff(
            array_unique(array_merge(self::EXPENSE_ORDER, $byType->keys()->all())),
            [self::INCOME_TAX_TYPE]
        ));
        // Any category added later sits with the operating ones, above Finance.
        $typeOrder = array_values(array_merge(array_diff($typeOrder, [self::FINANCE_TYPE]), [self::FINANCE_TYPE]));

        $sections          = [];
        $totalExpenses     = $zeroByPeriod;
        $cogsTotalsByPeriod = $zeroByPeriod;
        $financeTotalsByPeriod = $zeroByPeriod;

        foreach ($typeOrder as $type) {
            $typeAccounts = $byType->get($type);
            if (! $typeAccounts) {
                continue;
            }

            $rows = [];
            $sectionTotals = $zeroByPeriod;
            foreach ($typeAccounts as $account) {
                $rowPeriods = [];
                $anyNonZero = false;
                foreach ($periods as $period) {
                    $amount = $this->signedAmount($movementsByPeriod[$period['key']], $account);
                    $rowPeriods[$period['key']] = $amount;
                    if (bccomp($amount, '0.00', 2) !== 0) {
                        $anyNonZero = true;
                        $sectionTotals[$period['key']] = bcadd($sectionTotals[$period['key']], $amount, 2);
                    }
                }
                if ($anyNonZero) {
                    $rows[] = $this->row($account, $rowPeriods);
                }
            }

            if (empty($rows)) {
                continue;
            }

            foreach ($periodKeys as $key) {
                $totalExpenses[$key] = bcadd($totalExpenses[$key], $sectionTotals[$key], 2);
            }
            if ($type === self::GROSS_PROFIT_AFTER) {
                $cogsTotalsByPeriod = $sectionTotals;
            }
            if ($type === self::FINANCE_TYPE) {
                $financeTotalsByPeriod = $sectionTotals;
            }

            $sections[] = ['account_type' => $type, 'rows' => $rows, 'totals' => $sectionTotals];
        }

        // ── H19: Income Tax (591 / 592), below Profit Before Tax ──
        $taxRows   = [];
        $taxTotals = $zeroByPeriod;
        foreach ($byType->get(self::INCOME_TAX_TYPE, collect()) as $account) {
            $rowPeriods = [];
            $anyNonZero = false;
            foreach ($periods as $period) {
                $amount = $this->signedAmount($movementsByPeriod[$period['key']], $account);
                $rowPeriods[$period['key']] = $amount;
                if (bccomp($amount, '0.00', 2) !== 0) {
                    $anyNonZero = true;
                    $taxTotals[$period['key']] = bcadd($taxTotals[$period['key']], $amount, 2);
                }
            }
            if ($anyNonZero) {
                $taxRows[] = $this->row($account, $rowPeriods);
            }
        }

        $grossProfit     = [];
        $operatingProfit = [];
        $profitBeforeTax = [];
        $netIncome       = [];
        foreach ($periodKeys as $key) {
            $grossProfit[$key]     = bcsub($salesTotals[$key], $cogsTotalsByPeriod[$key], 2);
            $profitBeforeTax[$key] = bcsub($revenueTotals[$key], $totalExpenses[$key], 2);
            // Operating Profit = Gross Profit − every operating cost
            // (all expense categories except COGS and Finance).
            $operatingCosts        = bcsub(bcsub($totalExpenses[$key], $cogsTotalsByPeriod[$key], 2), $financeTotalsByPeriod[$key], 2);
            $operatingProfit[$key] = bcsub($grossProfit[$key], $operatingCosts, 2);
            $netIncome[$key]       = bcsub($profitBeforeTax[$key], $taxTotals[$key], 2);
        }

        return [
            'periods'           => $periods,
            'revenue'           => ['rows' => $revenueRows, 'totals' => $revenueTotals],
            'sales_revenue'     => ['rows' => $salesRows, 'totals' => $salesTotals],
            'other_income'      => ['rows' => $otherRows, 'totals' => $otherTotals],
            'gross_profit'      => $grossProfit,
            'expense_sections'  => $sections,
            'total_expenses'    => $totalExpenses,       // before tax
            'operating_profit'  => $operatingProfit,
            'ebit'              => $operatingProfit,     // see class header
            'profit_before_tax' => $profitBeforeTax,
            'income_tax'        => ['rows' => $taxRows, 'totals' => $taxTotals],
            'profit_after_tax'  => $netIncome,
            'net_income'        => $netIncome,           // = Profit After Tax
        ];
    }

    /**
     * Net income only, for a SINGLE date range — no periods array,
     * no row detail. Used by BalanceSheetService (315/314, Option A)
     * and CashFlowStatementService (per-period, internally).
     */
    public function netIncomeForRange(int $companyId, string $fromDate, string $toDate, ?int $costCenterId = null): string
    {
        $accounts = ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->postable()->active()
            ->whereIn('class', [ChartOfAccount::CLASS_REVENUE, ChartOfAccount::CLASS_EXPENSES])
            ->get(['id', 'class', 'normal_balance']);

        if ($accounts->isEmpty()) {
            return '0.00';
        }

        $movements = $this->movementsByAccount($companyId, $fromDate, $toDate, $costCenterId);

        $net = '0.00';
        foreach ($accounts as $account) {
            $amount = $this->signedAmount($movements, $account);
            $net = $account->class === ChartOfAccount::CLASS_REVENUE
                ? bcadd($net, $amount, 2)
                : bcsub($net, $amount, 2);
        }

        return $net;
    }

    /**
     * One expense category's total for a SINGLE date range (e.g.
     * "Depreciation & Amortization" for the Cash Flow indirect
     * method's non-cash addback, per period).
     */
    public function expenseCategoryTotalForRange(int $companyId, string $accountType, string $fromDate, string $toDate, ?int $costCenterId = null): string
    {
        $accounts = ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->postable()->active()
            ->where('class', ChartOfAccount::CLASS_EXPENSES)
            ->where('account_type', $accountType)
            ->get(['id', 'normal_balance']);

        if ($accounts->isEmpty()) {
            return '0.00';
        }

        $movements = $this->movementsByAccount($companyId, $fromDate, $toDate, $costCenterId);

        $total = '0.00';
        foreach ($accounts as $account) {
            $total = bcadd($total, $this->signedAmount($movements, $account), 2);
        }

        return $total;
    }

    // ── Private ──────────────────────────────────────────────────

    private function movementsByAccount(int $companyId, string $fromDate, string $toDate, ?int $costCenterId): array
    {
        // ACC-002 fix (confirmed with Mahmoud, 2026-09) — see
        // AccountBalanceCalculator::balancesAsOf() header for the full
        // reasoning. Note this also feeds BalanceSheetService's equity
        // roll-forward (ACC-001 area) via netIncomeForRange(), so this
        // one fix corrects reversed-entry handling in the Income
        // Statement and in the Balance Sheet's profit figures alike.
        $query = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.company_id', $companyId)
            ->whereBetween('journal_entries.entry_date', [$fromDate, $toDate]);

        if ($costCenterId) {
            $query->where('journal_entry_lines.cost_center_id', $costCenterId);
        }

        return $query
            ->selectRaw('journal_entry_lines.chart_of_account_id as account_id, COALESCE(SUM(journal_entry_lines.debit), 0) as debit, COALESCE(SUM(journal_entry_lines.credit), 0) as credit')
            ->groupBy('journal_entry_lines.chart_of_account_id')
            ->get()
            ->keyBy('account_id')
            ->map(fn ($row) => ['debit' => (string) $row->debit, 'credit' => (string) $row->credit])
            ->all();
    }

    private function signedAmount(array $movements, ChartOfAccount $account): string
    {
        $debit  = $movements[$account->id]['debit']  ?? '0.00';
        $credit = $movements[$account->id]['credit'] ?? '0.00';

        return $account->normal_balance === ChartOfAccount::BALANCE_CREDIT
            ? bcsub($credit, $debit, 2)
            : bcsub($debit, $credit, 2);
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
