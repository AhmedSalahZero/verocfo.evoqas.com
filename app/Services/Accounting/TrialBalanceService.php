<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  TrialBalanceService
//
//  Accounting > Financial Reports > Trial Balance (Financial Reports
//  Module Structure v1.0, Section 3.1) — one row per postable Chart
//  of Accounts account, each with Beginning Balance / Total Debit /
//  Total Credit / Ending Balance, exactly the shape confirmed with
//  Mahmoud (2026-09). Supports two shapes on top of the same engine:
//
//    - Single period  — one 4-number block, for one chosen date range
//    - Comparative     — the same 4-number block repeated once per
//      period (Monthly = the company's real accounting_periods for
//      one fiscal year; Quarterly = those same periods grouped into
//      4), so the person can trace an account across months side by
//      side, per Mahmoud's explicit request.
//
//  Deliberately does NOT invent its own definition of "a month" —
//  periods are read directly from accounting_periods, the same table
//  FiscalYearService/period-closing already treats as the single
//  source of truth for what a period's real start/end date is. This
//  matters because fiscal years don't have to start in January, and
//  multiple fiscal years can be open at once (Building Bible §26) —
//  a hardcoded calendar-month grid would silently disagree with the
//  company's own accounting calendar.
//
//  Performance (Financial Reports v1.0 §7 applies): computing N
//  periods for every account by calling LedgerStatementService's
//  per-account totals() in a loop would be (accounts × periods)
//  queries — for a company with ~75+ seeded accounts and a 12-month
//  comparative view, that's 900+ queries. Instead this service runs
//  exactly ONE query for the opening balance (grouped by account,
//  covering everything before the first period) and ONE query PER
//  PERIOD (grouped by account, covering only that period's own date
//  range) — 1 + N queries total, regardless of how many accounts
//  exist. Each period's own beginning balance is then just the prior
//  period's ending balance, carried forward in PHP.
//
//  Opening balances: ob_coa_balances (Building Bible: opening
//  balances are never journaled) is folded into the very first
//  period's beginning balance the same way LedgerStatementService
//  does for General Ledger — see that class's v1.2 notes. Only
//  included when no cost center filter is applied, for the same
//  reason: ob_coa_balances has no cost_center_id.
//
//  @layer    Service
//  @depends  JournalEntry, JournalEntryLine, ChartOfAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use Illuminate\Support\Collection;

class TrialBalanceService
{
    /**
     * Builds the full report. $periods is an ordered array of
     * ['key' => string, 'label' => string, 'start_date' => 'Y-m-d',
     * 'end_date' => 'Y-m-d'] — one entry for Single mode, up to 12
     * for Monthly, up to 4 for Quarterly. Periods MUST be
     * chronological and non-overlapping — the caller (controller)
     * is responsible for that, this service trusts the order given.
     *
     * Returns:
     *   [
     *     'periods' => the same $periods array, echoed back for the
     *                  Vue page's column headers,
     *     'rows'    => one row per account (only accounts with any
     *                  non-zero figure across the whole range, unless
     *                  $showAllAccounts), each:
     *                  ['id','code','name_en','name_ar','class',
     *                   'periods' => [periodKey => ['beginning',
     *                   'debit','credit','ending']]],
     *     'totals'  => one grand-total row, same 'periods' shape,
     *                  summing every account's debit/credit per
     *                  period — the arithmetic proof (Debit total ==
     *                  Credit total for every period) a Trial Balance
     *                  exists to show.
     *   ]
     */
    public function build(
        int $companyId,
        array $periods,
        ?int $costCenterId = null,
        bool $showAllAccounts = false
    ): array {
        if (empty($periods)) {
            return ['periods' => [], 'rows' => [], 'totals' => []];
        }

        $accounts = ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->postable()
            ->active()
            ->orderBy('class')
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'class', 'normal_balance'])
            ->keyBy('id');

        if ($accounts->isEmpty()) {
            return ['periods' => $periods, 'rows' => [], 'totals' => []];
        }

        // ── 1. Opening balance per account, before the FIRST period ──
        // One query, every account at once.
        $runningBalance = $this->openingBalancesByAccount(
            $companyId,
            $periods[0]['start_date'],
            $accounts,
            $costCenterId
        );

        // ── 2. Walk each period, one grouped query per period ───────
        // Plain PHP array, not a Collection — a Collection's
        // ArrayAccess doesn't support nested writes like
        // $accountPeriods[$id][$key] = [...] (it returns copies, not
        // references, so the assignment never actually lands and
        // Laravel throws "Indirect modification of overloaded
        // element" instead of silently failing).
        $accountPeriods = [];
        foreach ($accounts as $accountId => $account) {
            $accountPeriods[$accountId] = [];
        }

        foreach ($periods as $period) {
            $movements = $this->movementsByAccount(
                $companyId,
                $period['start_date'],
                $period['end_date'],
                $costCenterId
            );

            foreach ($accounts as $accountId => $account) {
                $beginning = $runningBalance[$accountId] ?? '0.00';
                $debit     = $movements[$accountId]['debit']  ?? '0.00';
                $credit    = $movements[$accountId]['credit'] ?? '0.00';
                $ending    = $this->applyMovement($beginning, $debit, $credit, $account->normal_balance);

                $accountPeriods[$accountId][$period['key']] = [
                    'beginning' => $beginning,
                    'debit'     => $debit,
                    'credit'    => $credit,
                    'ending'    => $ending,
                ];

                $runningBalance[$accountId] = $ending;
            }
        }

        // ── 3. Assemble rows, dropping all-zero accounts unless asked ──
        $rows = $accounts->map(function ($account) use ($accountPeriods, $showAllAccounts) {
            $periodsForRow = $accountPeriods[$account->id];

            if (! $showAllAccounts && $this->isAllZero($periodsForRow)) {
                return null;
            }

            return [
                'id'      => $account->id,
                'code'    => $account->code,
                'name_en' => $account->name_en,
                'name_ar' => $account->name_ar,
                'class'   => $account->class,
                'periods' => $periodsForRow,
            ];
        })->filter()->values()->all();

        // ── 4. Grand-total row — the balancing proof ─────────────────
        $totals = [];
        foreach ($periods as $period) {
            $sumDebit = '0.00';
            $sumCredit = '0.00';
            $begDr = $begCr = $endDr = $endCr = '0.00';
            foreach ($accountPeriods as $accountId => $periodsForAccount) {
                $p = $periodsForAccount[$period['key']];
                $sumDebit  = bcadd($sumDebit, $p['debit'], 2);
                $sumCredit = bcadd($sumCredit, $p['credit'], 2);

                // L-26: closing/opening balances placed on their real
                // Dr/Cr side (a credit-normal account with a negative
                // balance is a debit balance, and vice versa) so the
                // totals row can prove Closing Dr == Closing Cr.
                $creditNormal = $accounts[$accountId]->normal_balance === ChartOfAccount::BALANCE_CREDIT;
                $isCreditSide = fn (string $amt) => $creditNormal
                    ? bccomp($amt, '0', 2) >= 0
                    : bccomp($amt, '0', 2) < 0;

                $beg = ltrim($p['beginning'], '-');
                $end = ltrim($p['ending'], '-');
                if ($isCreditSide($p['beginning'])) { $begCr = bcadd($begCr, $beg, 2); } else { $begDr = bcadd($begDr, $beg, 2); }
                if ($isCreditSide($p['ending']))    { $endCr = bcadd($endCr, $end, 2); } else { $endDr = bcadd($endDr, $end, 2); }
            }
            $totals[$period['key']] = [
                'debit' => $sumDebit, 'credit' => $sumCredit,
                'beginning_debit' => $begDr, 'beginning_credit' => $begCr,
                'ending_debit' => $endDr, 'ending_credit' => $endCr,
            ];
        }

        return ['periods' => $periods, 'rows' => $rows, 'totals' => $totals];
    }

    // ── Private ──────────────────────────────────────────────────

    /**
     * Every account's net balance strictly before $beforeDate, in one
     * query, PLUS each account's ob_coa_balances row (opening
     * balances are never journaled — folded in separately, same as
     * LedgerStatementService). Returned already converted to each
     * account's own signed convention (positive = normal balance
     * side). Skips ob_coa_balances entirely when cost-center filtered
     * — that table has no cost_center_id to filter by.
     */
    private function openingBalancesByAccount(int $companyId, string $beforeDate, Collection $accounts, ?int $costCenterId): array
    {
        // ACC-002 fix (confirmed with Mahmoud, 2026-09) — see
        // AccountBalanceCalculator::balancesAsOf() header for the full
        // reasoning. A reversed entry and its mirror must both be
        // counted so they net to zero; excluding the reversed row
        // applies the transaction a second time instead of cancelling
        // it.
        $query = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.company_id', $companyId)
            ->where('journal_entries.entry_date', '<', $beforeDate);

        if ($costCenterId) {
            $query->where('journal_entry_lines.cost_center_id', $costCenterId);
        }

        $sums = $query
            ->selectRaw('journal_entry_lines.chart_of_account_id as account_id, COALESCE(SUM(journal_entry_lines.debit), 0) as total_debit, COALESCE(SUM(journal_entry_lines.credit), 0) as total_credit')
            ->groupBy('journal_entry_lines.chart_of_account_id')
            ->get()
            ->keyBy('account_id');

        // OB-001 fix (2026-09) — ob_coa_balances is NOT folded in here
        // any more. Opening balances now post a real journal entry
        // (OpeningBalanceService::postOpeningEntry), so the ledger query
        // above already contains them. Adding the row on top as well
        // would count every opening balance twice.

        $result = [];
        foreach ($accounts as $accountId => $account) {
            $debit  = (string) ($sums[$accountId]->total_debit  ?? '0.00');
            $credit = (string) ($sums[$accountId]->total_credit ?? '0.00');
            $balance = $this->signedNet($debit, $credit, $account->normal_balance);

            $result[$accountId] = $balance;
        }

        return $result;
    }

    /**
     * Every account's raw (unsigned) debit/credit sum strictly WITHIN
     * one period, in one query — sign conversion happens per-account
     * afterward in applyMovement(), since two accounts in the same
     * period can have opposite normal balances.
     */
    private function movementsByAccount(int $companyId, string $startDate, string $endDate, ?int $costCenterId): array
    {
        // ACC-002 fix (confirmed with Mahmoud, 2026-09) — see
        // AccountBalanceCalculator::balancesAsOf() header for the full
        // reasoning.
        $query = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.company_id', $companyId)
            ->whereBetween('journal_entries.entry_date', [$startDate, $endDate]);

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

    /**
     * debit - credit for a debit-normal account, credit - debit for a
     * credit-normal account — the same polarity rule
     * LedgerStatementService::signedExpression() encodes in SQL, here
     * applied in PHP to an already-summed pair.
     */
    private function signedNet(string $debit, string $credit, string $normalBalance): string
    {
        return $normalBalance === ChartOfAccount::BALANCE_CREDIT
            ? bcsub($credit, $debit, 2)
            : bcsub($debit, $credit, 2);
    }

    private function applyMovement(string $beginning, string $debit, string $credit, string $normalBalance): string
    {
        return bcadd($beginning, $this->signedNet($debit, $credit, $normalBalance), 2);
    }

    /**
     * True when every figure across every period for this account is
     * zero — beginning, debit, credit, AND ending all need checking,
     * since an account could have equal debit/credit movement that
     * nets to zero for the period but the account still had real
     * activity worth showing.
     */
    private function isAllZero(array $periodsForRow): bool
    {
        foreach ($periodsForRow as $p) {
            foreach (['beginning', 'debit', 'credit', 'ending'] as $field) {
                if (bccomp($p[$field], '0.00', 2) !== 0) {
                    return false;
                }
            }
        }

        return true;
    }
}
