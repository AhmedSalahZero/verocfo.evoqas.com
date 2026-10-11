<?php

namespace App\Services\Analytics;

// ══════════════════════════════════════════════════════════════════
//  ExpenseAnalysisService
//
//  Analytics > Expense Analysis — Business Analytical Engine pillar.
//  Ported from CashVero's CFO Tools ExpenseAnalysisController, with
//  two changes from the original:
//
//  1. Data source is the real GENERAL LEDGER (journal_entry_lines),
//     not an Excel upload. Every expense — from a Supplier Bill, a
//     Cash Expense Payment, a Payroll Run, a Depreciation Run, an
//     Accrual, or a plain Manual Journal Entry — posts to a real
//     expense account, so reading the ledger directly means nothing
//     is missed. CFO Tools could only ever see what was typed into
//     its upload.
//
//  2. CFO Tools' 3-level Category / Sub-category / Item breakdown
//     doesn't map onto Vero CFO's Chart of Accounts as-is — COA has
//     exactly 2 meaningful levels for expenses: the 6 fixed P&L
//     categories (chart_of_accounts.account_type / level 2) and the
//     specific postable account underneath each one (level 3 —
//     whether system-seeded like "551 Interest on Loans" or
//     company-created like "54001 Office Rent"). There is no genuine
//     3rd level to split "Sub-category" from "Item," so this service
//     offers 2 breakdown levels — Category and Account — covering
//     the same ground CFO Tools' 3 reports covered.
//
//  Revenue ("% of revenue", break-even) is net sales in functional
//  currency, net of credit / debit notes — the same SalesLinesQuery
//  figure Sales Analysis uses (Audit H13, Sept 2026).
//
//  Cancelled transactions are left out COMPLETELY (Audit H10, Sept
//  2026). Reversing a journal entry flags the original 'reversed' and
//  posts a mirror entry on the same date. This report used to keep
//  only 'posted' rows — so it dropped the original but kept the
//  mirror, and every cancelled expense showed up as a NEGATIVE
//  expense. Now both halves are excluded: the original (status
//  'reversed') AND its mirror (reverses_journal_entry_id set). Totals
//  are identical to counting both halves, and the report no longer
//  shows cancelled items at all.
//
//  DIMENSIONS:
//    category     → chart_of_accounts.account_type (the 6 fixed P&L categories)
//    account      → chart_of_accounts.code / name_en (the specific postable account)
//    cost_center  → cost_centers.name_en
//
//  Single metric — expense amount — since every expense account is
//  debit-normal: amount = SUM(debit) - SUM(credit) (Rule 4 — bcmath
//  for every PHP-side combination; SQL SUM() is the aggregation step
//  itself, same convention as SalesAnalysisService).
//
//  Report types (6 — same ground as CFO Tools' 6, adapted per note above):
//    category_breakdown, account_breakdown, trend, min_avg_max,
//    period_comparison, breakeven
//
//  @layer    Service
//  @depends  JournalEntryLine, JournalEntry, ChartOfAccount, CostCenter,
//            CustomerInvoiceLine (for revenue context)
// ══════════════════════════════════════════════════════════════════

use App\Models\JournalEntryLine;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ExpenseAnalysisService
{
    public const DIMENSIONS = [
        'category'    => 'Category (P&L Type)',
        'account'     => 'Account',
        'cost_center' => 'Cost Center',
    ];

    private const AMOUNT_EXPR = 'SUM(journal_entry_lines.debit - journal_entry_lines.credit)';

    // ── Base query — every report starts here ───────────────────
    // Live (not cancelled) journal entries only, only real expense accounts
    // (class = 'expenses', section = 'income_statement' — excludes
    // e.g. Cost of Sales contra accounts or balance-sheet accruals).

    private function baseQuery(int $companyId, string $dateFrom, string $dateTo)
    {
        return JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_entry_lines.chart_of_account_id')
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'journal_entry_lines.cost_center_id')
            ->where('journal_entries.company_id', $companyId)
            // Audit H10 — exclude BOTH halves of a cancelled entry.
            ->where('journal_entries.status', 'posted')
            ->whereNull('journal_entries.reverses_journal_entry_id')
            ->where('chart_of_accounts.class', 'expenses')
            ->where('chart_of_accounts.section', 'income_statement')
            ->whereBetween('journal_entries.entry_date', [$dateFrom, $dateTo]);
    }

    private function dimensionExpr(string $dimension): array
    {
        return match ($dimension) {
            'account'     => ["CONCAT(chart_of_accounts.code, ' — ', chart_of_accounts.name_en)", 'chart_of_accounts.id'],
            'cost_center' => ["COALESCE(cost_centers.name_en, 'Unassigned')", 'cost_centers.id'],
            default       => ['chart_of_accounts.account_type', 'chart_of_accounts.account_type'], // category
        };
    }

    private function periodExpressions(string $period): array
    {
        return match ($period) {
            'quarterly'     => ["CONCAT(YEAR(journal_entries.entry_date), '-Q', QUARTER(journal_entries.entry_date))", 'YEAR(journal_entries.entry_date) * 10 + QUARTER(journal_entries.entry_date)'],
            'semi_annually' => ["CONCAT(YEAR(journal_entries.entry_date), '-H', IF(MONTH(journal_entries.entry_date) <= 6, 1, 2))", 'YEAR(journal_entries.entry_date) * 10 + IF(MONTH(journal_entries.entry_date) <= 6, 1, 2)'],
            'annually'      => ['YEAR(journal_entries.entry_date)', 'YEAR(journal_entries.entry_date)'],
            default         => ["DATE_FORMAT(journal_entries.entry_date, '%Y-%b')", "DATE_FORMAT(journal_entries.entry_date, '%Y%m') + 0"], // monthly
        };
    }

    // Revenue for the same range — used for "% of revenue" context.
    // Audit H13: the SAME net-sales figure Sales Analysis uses
    // (functional currency, net of credit / debit notes — see
    // SalesLinesQuery), not raw invoice lines in mixed currencies.
    private function revenueForRange(int $companyId, string $dateFrom, string $dateTo): float
    {
        return (float) SalesLinesQuery::netSales($companyId, $dateFrom, $dateTo);
    }

    // ── Report: Category Breakdown ────────────────────────────────

    public function categoryBreakdown(int $companyId, array $filters): array
    {
        $rows = $this->baseQuery($companyId, $filters['date_from'], $filters['date_to'])
            ->selectRaw('chart_of_accounts.account_type as label, ' . self::AMOUNT_EXPR . ' as value')
            ->groupBy('chart_of_accounts.account_type')
            ->orderByDesc('value')->get();

        return $this->withShares($rows, $companyId, $filters, 'category_breakdown');
    }

    // ── Report: Account Breakdown ─────────────────────────────────
    // Optional 'category' filter narrows to accounts within one of
    // the 6 P&L types (the drill-down CFO Tools' item_breakdown gave
    // via its category dropdown).

    public function accountBreakdown(int $companyId, array $filters): array
    {
        $query = $this->baseQuery($companyId, $filters['date_from'], $filters['date_to']);
        if (!empty($filters['category'])) {
            $query->where('chart_of_accounts.account_type', $filters['category']);
        }

        $rows = $query
            ->selectRaw("CONCAT(chart_of_accounts.code, ' — ', chart_of_accounts.name_en) as label, chart_of_accounts.account_type as category, " . self::AMOUNT_EXPR . ' as value')
            ->groupBy('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name_en', 'chart_of_accounts.account_type')
            ->orderByDesc('value')->get();

        return $this->withShares($rows, $companyId, $filters, 'account_breakdown');
    }

    private function withShares(Collection $rows, int $companyId, array $filters, string $type): array
    {
        $totalExpense = (float) Money::sum($rows->pluck('value'));
        $revenue = $this->revenueForRange($companyId, $filters['date_from'], $filters['date_to']);

        $out = $rows->map(function ($r) use ($totalExpense, $revenue) {
            return [
                'label' => $r->label,
                'category' => $r->category ?? null,
                'value' => (float) $r->value,
                'pct_of_expense' => $totalExpense > 0 ? (float) bcmul(bcdiv((string) $r->value, (string) $totalExpense, 6), '100', 2) : 0.0,
                'pct_of_revenue' => $revenue > 0 ? (float) bcmul(bcdiv((string) $r->value, (string) $revenue, 6), '100', 2) : 0.0,
            ];
        });

        return ['type' => $type, 'rows' => $out, 'total_expense' => $totalExpense, 'total_revenue' => $revenue];
    }

    // ── Report: Trend Over Time ───────────────────────────────────
    // Optional 'category' or 'account_id' filter narrows the trend
    // to one P&L type or one specific account.

    public function trend(int $companyId, array $filters): array
    {
        [$periodLabel, $periodSort] = $this->periodExpressions($filters['period'] ?? 'monthly');
        $query = $this->baseQuery($companyId, $filters['date_from'], $filters['date_to']);
        if (!empty($filters['category'])) $query->where('chart_of_accounts.account_type', $filters['category']);
        if (!empty($filters['account_id'])) $query->where('chart_of_accounts.id', $filters['account_id']);

        $rows = $query
            ->selectRaw("$periodLabel as period, $periodSort as sort_key, " . self::AMOUNT_EXPR . ' as value')
            ->groupBy(DB::raw($periodLabel), DB::raw($periodSort))
            ->orderBy('sort_key')->get();

        // Revenue per period (for revenue_pct — same idea as CFO Tools' trend)
        [$revLabel, $revSort] = $this->periodExpressions($filters['period'] ?? 'monthly');
        $revenueByPeriod = SalesLinesQuery::from($companyId, $filters['date_from'], $filters['date_to'])
            ->selectRaw(str_replace('journal_entries.entry_date', 'sl.doc_date', $revLabel) . ' as period, SUM(sl.net_amount) as rev')
            ->groupBy('period')->pluck('rev', 'period');

        $prev = null;
        $out = $rows->map(function ($r) use (&$prev, $revenueByPeriod) {
            $val = (float) $r->value;
            $rev = (float) ($revenueByPeriod[$r->period] ?? 0);
            $growth = ($prev !== null && $prev > 0) ? (float) bcmul(bcdiv((string) ($val - $prev), (string) $prev, 6), '100', 2) : null;
            $prev = $val;
            return [
                'period' => $r->period, 'value' => $val, 'growth_rate' => $growth,
                'revenue_pct' => $rev > 0 ? (float) bcmul(bcdiv((string) $val, (string) $rev, 6), '100', 2) : 0.0,
            ];
        });

        return ['type' => 'trend', 'rows' => $out];
    }

    // ── Report: Min / Avg / Max ───────────────────────────────────
    // Per dimension value (category or account), the min/avg/max of
    // its own MONTHLY totals across the range — flags volatile items.

    public function minAvgMax(int $companyId, array $filters): array
    {
        $dimension = $filters['dimension1'] ?? 'account';
        [$labelExpr, $groupExpr] = $this->dimensionExpr($dimension);

        $monthly = $this->baseQuery($companyId, $filters['date_from'], $filters['date_to'])
            ->selectRaw("$labelExpr as label, DATE_FORMAT(journal_entries.entry_date, '%Y-%m') as month, " . self::AMOUNT_EXPR . ' as value')
            ->groupBy(DB::raw($groupExpr), DB::raw($labelExpr), DB::raw("DATE_FORMAT(journal_entries.entry_date, '%Y-%m')"))
            ->get();

        $rows = $monthly->groupBy('label')->map(function ($group, $label) {
            $values = $group->pluck('value')->map(fn ($v) => (float) $v);
            return [
                'label' => $label,
                'total' => (float) Money::sum($group->pluck('value')),
                'min' => $values->min(), 'avg' => round($values->avg(), 2), 'max' => $values->max(),
                'months_active' => $values->count(),
            ];
        })->sortByDesc('total')->values();

        return ['type' => 'min_avg_max', 'dimension' => $dimension, 'rows' => $rows];
    }

    // ── Report: Period Comparison ─────────────────────────────────

    public function periodComparison(int $companyId, array $filters): array
    {
        $dimension = $filters['dimension1'] ?? 'category';
        [$labelExpr, $groupExpr] = $this->dimensionExpr($dimension);
        $periods = $filters['periods'];

        $rows = collect();
        foreach ($periods as $p) {
            $periodRows = $this->baseQuery($companyId, $p['from'], $p['to'])
                ->selectRaw("$labelExpr as label, " . self::AMOUNT_EXPR . ' as value')
                ->groupBy(DB::raw($groupExpr), DB::raw($labelExpr))->get()->keyBy('label');
            $rows->put($p['label'], $periodRows);
        }

        $allLabels = $rows->flatMap(fn ($p) => $p->keys())->unique()->values();
        $table = $allLabels->map(function ($label) use ($rows, $periods) {
            $line = ['label' => $label];
            $prevValue = null;
            foreach ($periods as $p) {
                $val = (float) ($rows[$p['label']][$label]->value ?? 0);
                $line[$p['label']] = $val;
                if ($prevValue !== null) {
                    $line['change_vs_prev'] = $prevValue > 0 ? (float) bcmul(bcdiv((string) ($val - $prevValue), (string) $prevValue, 6), '100', 2) : null;
                }
                $prevValue = $val;
            }
            return $line;
        });

        return ['type' => 'period_comparison', 'dimension' => $dimension, 'periods' => array_column($periods, 'label'), 'rows' => $table];
    }

    // ── Report: Breakeven Analysis ────────────────────────────────
    // Same Pearson-correlation method as CFO Tools: for each expense
    // ACCOUNT, collect its (expense, revenue) pair per month across
    // the range. r >= 0.65 → moves with revenue → VARIABLE cost.
    // r < 0.65 → independent → FIXED cost. Then:
    //   CM Ratio      = (Revenue - Variable Costs) / Revenue
    //   Breakeven     = Fixed Costs / CM Ratio
    //   Safety Margin = Actual Revenue - Breakeven Revenue

    public function breakeven(int $companyId, string $dateFrom, string $dateTo): array
    {
        $revenueByMonth = SalesLinesQuery::from($companyId, $dateFrom, $dateTo)
            ->selectRaw("DATE_FORMAT(sl.doc_date, '%Y-%m') as month, SUM(sl.net_amount) as rev")
            ->groupBy('month')->pluck('rev', 'month');

        $expenseByAccountMonth = $this->baseQuery($companyId, $dateFrom, $dateTo)
            ->selectRaw("CONCAT(chart_of_accounts.code, ' — ', chart_of_accounts.name_en) as account, DATE_FORMAT(journal_entries.entry_date, '%Y-%m') as month, " . self::AMOUNT_EXPR . ' as value')
            ->groupBy('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_accounts.name_en', DB::raw("DATE_FORMAT(journal_entries.entry_date, '%Y-%m')"))
            ->get()->groupBy('account');

        $totalFixed = '0';
        $totalVariable = '0';
        $classification = [];

        foreach ($expenseByAccountMonth as $account => $rows) {
            $pairs = $rows->map(fn ($r) => ['expense' => (float) $r->value, 'revenue' => (float) ($revenueByMonth[$r->month] ?? 0)]);
            $total = (float) Money::sum($pairs->pluck('expense'));
            $r = $this->pearsonCorrelation($pairs->pluck('expense'), $pairs->pluck('revenue'));
            $isVariable = $r >= 0.65;
            $classification[] = ['account' => $account, 'total' => $total, 'correlation' => round($r, 3), 'classification' => $isVariable ? 'variable' : 'fixed'];
            $totalFixed = $isVariable ? $totalFixed : bcadd($totalFixed, (string) $total, 2);
            $totalVariable = $isVariable ? bcadd($totalVariable, (string) $total, 2) : $totalVariable;
        }

        $totalRevenue = Money::sum($revenueByMonth->values());
        $cmRatio = bccomp($totalRevenue, '0', 2) > 0 ? bcdiv(bcsub($totalRevenue, $totalVariable, 2), $totalRevenue, 6) : '0';
        $breakevenRevenue = bccomp($cmRatio, '0', 6) > 0 ? bcdiv($totalFixed, $cmRatio, 2) : '0';
        $safetyMargin = bcsub($totalRevenue, $breakevenRevenue, 2);

        return [
            'type' => 'breakeven',
            'items' => collect($classification)->sortByDesc('total')->values(),
            'total_fixed' => (float) $totalFixed, 'total_variable' => (float) $totalVariable,
            'total_revenue' => (float) $totalRevenue, 'cm_ratio' => (float) bcmul($cmRatio, '100', 2),
            'breakeven_revenue' => (float) $breakevenRevenue, 'safety_margin' => (float) $safetyMargin,
        ];
    }

    private function pearsonCorrelation(Collection $x, Collection $y): float
    {
        $n = $x->count();
        if ($n < 2) return 0.0;
        $meanX = $x->avg(); $meanY = $y->avg();
        $num = 0; $denX = 0; $denY = 0;
        foreach (range(0, $n - 1) as $i) {
            $dx = $x[$i] - $meanX; $dy = $y[$i] - $meanY;
            $num += $dx * $dy; $denX += $dx ** 2; $denY += $dy ** 2;
        }
        $den = sqrt($denX * $denY);
        return $den > 0 ? $num / $den : 0.0;
    }

    // ── Dashboard: Run-Rate Projection ────────────────────────────────
    // Same method CFO Tools used, ported onto the real ledger: only
    // shown for a Jan-1-to-today (year-to-date) range. Projects the
    // full calendar year's expense from what's actually posted so far.
    //
    //   Trend method (default) — Projected Full Year = YTD Actual +
    //     (Months Remaining × trailing 3-month average, with IQR
    //     outlier months excluded so a one-off spike doesn't skew it).
    //   Seasonal method (used once 2 full prior calendar years of
    //     posted expense exist) — accounts for spend that's naturally
    //     loaded into specific months (e.g. year-end bonuses):
    //     Projected Full Year = YTD Actual ÷ (average % of the full
    //     year that had typically occurred by this same point in
    //     those prior years).
    //
    // Always returns which method was used and the plain-English
    // reasoning, so this is never a black box on the dashboard.

    public function runRateProjection(int $companyId, string $dateFrom, string $dateTo): array
    {
        $from = Carbon::parse($dateFrom);
        $to = Carbon::parse($dateTo);
        $year = $from->year;
        $yearEnd = Carbon::create($year, 12, 31);

        if (!$from->isSameDay(Carbon::create($year, 1, 1))) return ['applicable' => false, 'reason' => 'not_ytd'];
        if ($to->greaterThanOrEqualTo($yearEnd)) return ['applicable' => false, 'reason' => 'full_year'];

        $monthlyRows = $this->baseQuery($companyId, $dateFrom, $dateTo)
            ->selectRaw("DATE_FORMAT(journal_entries.entry_date, '%Y-%m') as month, " . self::AMOUNT_EXPR . ' as total')
            ->groupBy('month')->orderBy('month')->get();

        $monthsElapsed = $monthlyRows->count();
        if ($monthsElapsed === 0) return ['applicable' => false, 'reason' => 'no_data'];
        $remainingMonths = 12 - $monthsElapsed;
        if ($remainingMonths <= 0) return ['applicable' => false, 'reason' => 'full_year'];

        $ytdActual = (float) Money::sum($monthlyRows->pluck('total'));
        $values = $monthlyRows->pluck('total')->map(fn ($v) => (float) $v)->values();

        $outlierMonths = [];
        if ($values->count() >= 4) {
            $sorted = $values->sort()->values()->toArray();
            $q1 = $this->percentile($sorted, 25);
            $q3 = $this->percentile($sorted, 75);
            $iqr = $q3 - $q1;
            $lowerFence = $q1 - 1.5 * $iqr;
            $upperFence = $q3 + 1.5 * $iqr;
            foreach ($monthlyRows as $row) {
                if ((float) $row->total > $upperFence || (float) $row->total < $lowerFence) $outlierMonths[] = $row->month;
            }
        }

        $trailing = $monthlyRows->reverse()->values()->take(3)->filter(fn ($r) => !in_array($r->month, $outlierMonths));
        if ($trailing->isEmpty()) $trailing = $monthlyRows->filter(fn ($r) => !in_array($r->month, $outlierMonths));
        if ($trailing->isEmpty()) $trailing = $monthlyRows;

        $trailingAvg = (float) $trailing->avg('total');
        $projectedTrend = $ytdActual + ($trailingAvg * $remainingMonths);

        $result = [
            'applicable' => true, 'method' => 'trend', 'year' => $year, 'as_of' => $dateTo,
            'months_elapsed' => $monthsElapsed, 'months_remaining' => $remainingMonths,
            'ytd_actual' => round($ytdActual, 2), 'trailing_avg' => round($trailingAvg, 2),
            'trailing_months_used' => $trailing->pluck('month')->values()->all(),
            'outlier_months_excluded' => $outlierMonths,
            'projected_full_year' => round($projectedTrend, 2),
            'projected_remainder' => round($projectedTrend - $ytdActual, 2),
            'years_of_history_used' => 0,
        ];

        $fullPriorYears = [];
        foreach ([$year - 1, $year - 2] as $y) {
            $monthsPresent = (int) $this->baseQuery($companyId, "$y-01-01", "$y-12-31")
                ->selectRaw("COUNT(DISTINCT DATE_FORMAT(journal_entries.entry_date, '%Y-%m')) as c")->value('c');
            if ($monthsPresent === 12) $fullPriorYears[] = $y;
        }

        if (count($fullPriorYears) >= 2) {
            $ratios = [];
            foreach ($fullPriorYears as $y) {
                $yearTotal = (float) Money::round($this->baseQuery($companyId, "$y-01-01", "$y-12-31")->sum(DB::raw('journal_entry_lines.debit - journal_entry_lines.credit')));
                $cutoff = Carbon::create($y, $to->month, 1)->endOfMonth();
                if ($to->day < $cutoff->day) $cutoff->setDay($to->day);
                $elapsedTotal = (float) Money::round($this->baseQuery($companyId, "$y-01-01", $cutoff->format('Y-m-d'))->sum(DB::raw('journal_entry_lines.debit - journal_entry_lines.credit')));
                if ($yearTotal > 0) $ratios[] = $elapsedTotal / $yearTotal;
            }

            if (count($ratios) >= 2) {
                $avgRatio = array_sum($ratios) / count($ratios);
                if ($avgRatio > 0) {
                    $projectedSeasonal = $ytdActual / $avgRatio;
                    $result['method'] = 'seasonal';
                    $result['projected_full_year'] = round($projectedSeasonal, 2);
                    $result['projected_remainder'] = round($projectedSeasonal - $ytdActual, 2);
                    $result['years_of_history_used'] = count($fullPriorYears);
                    $result['seasonal_years'] = $fullPriorYears;
                    $result['seasonal_pct_elapsed_by_now'] = round($avgRatio * 100, 1);
                    $result['trend_method_full_year'] = round($projectedTrend, 2);
                }
            }
        }

        return $result;
    }

    private function percentile(array $sortedValues, float $pct): float
    {
        $count = count($sortedValues);
        if ($count === 0) return 0.0;
        $index = ($pct / 100) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        if ($lower === $upper) return $sortedValues[$lower];

        $fraction = $index - $lower;
        return $sortedValues[$lower] + ($sortedValues[$upper] - $sortedValues[$lower]) * $fraction;
    }

    // ── Dashboard: Top Expense Items ──────────────────────────────────
    // Top 10 accounts by expense amount — reuses accountBreakdown() so
    // the share % is identical to the Reports page.

    public function topExpenseItems(int $companyId, string $dateFrom, string $dateTo): array
    {
        $result = $this->accountBreakdown($companyId, ['date_from' => $dateFrom, 'date_to' => $dateTo]);

        return $result['rows']->take(10)->values()->all();
    }

    // ── Dashboard KPIs ─────────────────────────────────────────────

    public function dashboardKpis(int $companyId, string $dateFrom, string $dateTo): array
    {
        $totalExpense = (float) Money::round($this->baseQuery($companyId, $dateFrom, $dateTo)->sum(DB::raw('journal_entry_lines.debit - journal_entry_lines.credit')));
        $revenue = $this->revenueForRange($companyId, $dateFrom, $dateTo);
        $categoryCount = $this->baseQuery($companyId, $dateFrom, $dateTo)->distinct('chart_of_accounts.account_type')->count('chart_of_accounts.account_type');
        $accountCount = $this->baseQuery($companyId, $dateFrom, $dateTo)->distinct('chart_of_accounts.id')->count('chart_of_accounts.id');

        $monthlyTotals = $this->baseQuery($companyId, $dateFrom, $dateTo)
            ->selectRaw("DATE_FORMAT(journal_entries.entry_date, '%Y-%m') as month, " . self::AMOUNT_EXPR . ' as value')
            ->groupBy('month')->pluck('value');
        $avgMonthly = $monthlyTotals->count() > 0 ? round((float) $monthlyTotals->avg(), 2) : 0.0;

        return [
            'total_expense' => $totalExpense, 'total_revenue' => $revenue,
            'expense_pct_of_revenue' => $revenue > 0 ? (float) bcmul(bcdiv((string) $totalExpense, (string) $revenue, 6), '100', 2) : 0.0,
            'category_count' => $categoryCount, 'account_count' => $accountCount,
            'avg_monthly' => $avgMonthly,
        ];
    }
}
