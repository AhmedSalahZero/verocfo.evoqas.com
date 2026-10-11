<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LedgerStatementService
//
//  The shared engine behind every "Statement" screen that is a true
//  running-balance ledger (Bank Statement, Safe Statement, Cash Cover
//  Statement, and — as of the Overdraft addition — the Credit
//  Facility side of Bank Statement too). Confirmed with Mahmoud
//  (2026-09): these read DIRECTLY from the real journal entries —
//  never a second, parallel table mirroring the GL the way CashVero's
//  per-account-type *_bank_statements tables do. One source of truth.
//  A statement is simply: every posted journal_entry_line on one
//  Chart of Accounts row, in date order, with a running balance.
//
//  v1.1 (2026-09, Mahmoud) — an Overdraft facility's own COA account
//  is a LIABILITY, not an asset like a bank/safe account, and the
//  Settlement Engine's own journal entries are correct standard
//  double-entry for that: a drawdown CREDITS the facility (increases
//  what's owed), a repayment DEBITS it (decreases what's owed). Using
//  the asset-account formula unmodified on a liability account would
//  show End Balance as the NEGATIVE of the actual amount owed —
//  technically defensible but inconsistent with outstanding_balance
//  everywhere else in the app (always shown as a positive "amount
//  owed"). So every method here now takes a $balanceSign parameter:
//    'asset'     (default) — end_balance = opening + SUM(debit - credit)
//    'liability' — end_balance = opening + SUM(credit - debit)
//  Bank/Safe/Cash Cover statements never pass this — they stay
//  'asset', unchanged. Only the Credit Facility side of Bank
//  Statement passes 'liability'.
//
//  Deliberately NOT built here (confirmed out of scope for now):
//    - Bank reconciliation ("Reviewed" column in CashVero's UI) —
//      postponed per Mahmoud.
//    - Inline editing of a statement row (CashVero's pencil icon) —
//      dropped entirely; a wrong posted amount is corrected by
//      reopening the transaction that posted it, same discipline as
//      the OD Settlement Engine.
//
//  Every screen built on this supplies its own Chart of Accounts
//  row(s) to read plus the correct $balanceSign for that account —
//  this service has no opinion on what a "bank" or a "facility" is,
//  only how to read one COA row's ledger correctly for either
//  polarity.
//
//  v1.2 (General Ledger, 2026-09) — two additions needed the moment
//  this engine gets pointed at ANY account instead of just Bank/Safe:
//
//  (a) Opening balances. Bank/Safe accounts never carried a manually-
//  entered opening balance in practice, so the gap was invisible
//  until now. `ob_coa_balances` (Building Bible: opening balances are
//  never journaled — a separate one-time table) must be added into
//  openingBalance()'s result, or every account that DID start with a
//  real opening balance (AR, AP, Capital, Retained Earnings...) would
//  report a General Ledger that's short by exactly that amount. Only
//  added when $costCenterId is null — ob_coa_balances has no cost
//  center column (opening balances were entered before cost-center-
//  level posting existed), so a cost-center-filtered view can't
//  correctly attribute it to one cost center and deliberately leaves
//  it out rather than guess.
//
//  (b) Cost center filter. Every method now takes an optional
//  $costCenterId — when given, only journal_entry_lines rows posted
//  to that cost center are included. Existing Bank/Safe/Facility
//  callers never pass one, so their behavior is unchanged.
//
//  v1.3 (Audit H-25, Oct 2026) — NATIVE-CURRENCY MODE. A USD bank
//  account used to be reported in EGP (the ledger's currency), so it
//  could never be ticked against the bank's own USD statement. Every
//  method now takes a trailing $native flag. When true, debit, credit
//  and the running balance are the line's FOREIGN amount
//  (journal_entry_lines.amount_currency: + debit / − credit — see
//  ForeignCurrencyLedgerService). Lines whose foreign amount is not
//  known (NULL) count as zero and are reported through
//  unknownForeignCount() so the screen can warn about them.
//
//  @layer    Service
//  @depends  JournalEntry, JournalEntryLine, ChartOfAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\JournalEntryLine;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LedgerStatementService
{
    const SIGN_ASSET     = 'asset';
    const SIGN_LIABILITY = 'liability';

    /**
     * The signed expression used everywhere below — 'debit - credit'
     * for an asset account, 'credit - debit' for a liability account.
     * Centralized here so the two never drift apart across methods.
     */
    private function signedExpression(string $balanceSign, bool $native = false): string
    {
        if ($native) {
            return $balanceSign === self::SIGN_LIABILITY
                ? '(0 - COALESCE(journal_entry_lines.amount_currency, 0))'
                : 'COALESCE(journal_entry_lines.amount_currency, 0)';
        }

        return $balanceSign === self::SIGN_LIABILITY
            ? 'journal_entry_lines.credit - journal_entry_lines.debit'
            : 'journal_entry_lines.debit - journal_entry_lines.credit';
    }

    /** SQL for the debit / credit columns — functional amounts, or the foreign amount in native mode. */
    private function debitExpr(bool $native): string
    {
        return $native ? 'GREATEST(COALESCE(journal_entry_lines.amount_currency, 0), 0)' : 'journal_entry_lines.debit';
    }

    private function creditExpr(bool $native): string
    {
        return $native ? 'GREATEST(0 - COALESCE(journal_entry_lines.amount_currency, 0), 0)' : 'journal_entry_lines.credit';
    }

    /**
     * Native mode only: how many posted lines on this account (in the
     * range) have NO foreign amount recorded — they are counted as
     * zero in the statement, so the screen must warn the user.
     */
    public function unknownForeignCount(int $companyId, int $coaAccountId, ?string $startDate, ?string $endDate): int
    {
        return (int) JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.company_id', $companyId)
            ->where('journal_entry_lines.chart_of_account_id', $coaAccountId)
            ->whereBetween('journal_entries.entry_date', [$startDate ?: '2000-01-01', $endDate ?: now()->format('Y-m-d')])
            ->whereNull('journal_entry_lines.amount_currency')
            ->count();
    }

    /**
     * Net of every posted debit/credit on this account strictly
     * before $beforeDate, PLUS the account's one-time opening balance
     * from ob_coa_balances (if any) — the true opening balance for a
     * statement that starts on $beforeDate. Opening-balance journal
     * entries, if they ever exist as real postings, are included the
     * same as any other line automatically; ob_coa_balances is a
     * separate table (never journaled — see class header) and must be
     * added on top explicitly, or it's silently missing.
     */
    public function openingBalance(int $companyId, int $coaAccountId, string $beforeDate, string $balanceSign = self::SIGN_ASSET, ?int $costCenterId = null, bool $native = false): string
    {
        // ACC-002 fix (confirmed with Mahmoud, 2026-09) — see
        // AccountBalanceCalculator::balancesAsOf() header for the full
        // reasoning.
        $query = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.company_id', $companyId)
            ->where('journal_entry_lines.chart_of_account_id', $coaAccountId)
            ->where('journal_entries.entry_date', '<', $beforeDate);

        if ($costCenterId) {
            $query->where('journal_entry_lines.cost_center_id', $costCenterId);
        }

        $net = $query->selectRaw('COALESCE(SUM('.$this->signedExpression($balanceSign, $native).'), 0) as net')->value('net');
        $balance = (string) ($net ?? '0.00');

        // OB-001 fix (2026-09) — ob_coa_balances used to be added on
        // top here. It is a real journal entry now, so the query above
        // already carries it; adding it again would double the opening
        // balance on every account statement.

        return $balance;
    }

    /**
     * The statement itself, one COA row, date-ranged, paginated with
     * a correct running balance computed in SQL (a MySQL window
     * function) so the running balance stays correct across pages —
     * summing only the current page in PHP would be wrong the moment
     * there's a second page.
     *
     * Each returned row: id, entry_date, debit, credit, end_balance,
     * beginning_balance (derived from end_balance and the row's own
     * signed movement), narration, description, entry_number,
     * journal_entry_id.
     */
    public function paginate(
        int $companyId,
        int $coaAccountId,
        ?string $startDate,
        ?string $endDate,
        int $perPage = 50,
        string $balanceSign = self::SIGN_ASSET,
        ?int $costCenterId = null,
        bool $native = false
    ): LengthAwarePaginator {
        $startDate = $startDate ?: '2000-01-01';
        $endDate   = $endDate ?: now()->format('Y-m-d');

        $opening = $this->openingBalance($companyId, $coaAccountId, $startDate, $balanceSign, $costCenterId, $native);
        $signed  = $this->signedExpression($balanceSign, $native);

        // ACC-002 fix (confirmed with Mahmoud, 2026-09) — a reversed
        // line stays visible on the printed statement alongside its
        // mirror, so the reader can see "this was posted, then
        // corrected" rather than the original silently vanishing. See
        // AccountBalanceCalculator::balancesAsOf() header for the full
        // reasoning.
        $paginator = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.company_id', $companyId)
            ->where('journal_entry_lines.chart_of_account_id', $coaAccountId)
            ->whereBetween('journal_entries.entry_date', [$startDate, $endDate])
            ->when($costCenterId, fn ($q) => $q->where('journal_entry_lines.cost_center_id', $costCenterId))
            ->select([
                'journal_entry_lines.id',
                'journal_entries.entry_date',
                DB::raw($this->debitExpr($native).' as debit'),
                DB::raw($this->creditExpr($native).' as credit'),
                'journal_entries.narration',
                'journal_entry_lines.description',
                'journal_entries.entry_number',
                'journal_entries.id as journal_entry_id',
                'journal_entries.source_type',
                'journal_entries.source_id',
            ])
            ->selectRaw(
                '? + SUM('.$signed.') OVER '.
                '(ORDER BY journal_entries.entry_date, journal_entry_lines.id '.
                'ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as end_balance',
                [$opening]
            )
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entry_lines.id')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->getCollection()->transform(function ($row) use ($balanceSign) {
            $row->beginning_balance = $this->beginningBalanceOf($row, $balanceSign);

            return $row;
        });

        return $paginator;
    }

    /**
     * Same shape as paginate(), but every row, unpaginated — for the
     * Excel export, which needs the whole range, not one page.
     */
    public function all(
        int $companyId,
        int $coaAccountId,
        ?string $startDate,
        ?string $endDate,
        string $balanceSign = self::SIGN_ASSET,
        ?int $costCenterId = null,
        bool $native = false
    ): \Illuminate\Support\Collection {
        $startDate = $startDate ?: '2000-01-01';
        $endDate   = $endDate ?: now()->format('Y-m-d');

        $opening = $this->openingBalance($companyId, $coaAccountId, $startDate, $balanceSign, $costCenterId, $native);
        $signed  = $this->signedExpression($balanceSign, $native);

        // ACC-002 fix (confirmed with Mahmoud, 2026-09) — kept
        // consistent with paginate() above, so the Excel export never
        // disagrees with the on-screen statement.
        $rows = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.company_id', $companyId)
            ->where('journal_entry_lines.chart_of_account_id', $coaAccountId)
            ->whereBetween('journal_entries.entry_date', [$startDate, $endDate])
            ->when($costCenterId, fn ($q) => $q->where('journal_entry_lines.cost_center_id', $costCenterId))
            ->select([
                'journal_entry_lines.id',
                'journal_entries.entry_date',
                DB::raw($this->debitExpr($native).' as debit'),
                DB::raw($this->creditExpr($native).' as credit'),
                'journal_entries.narration',
                'journal_entry_lines.description',
                'journal_entries.entry_number',
            ])
            ->selectRaw(
                '? + SUM('.$signed.') OVER '.
                '(ORDER BY journal_entries.entry_date, journal_entry_lines.id '.
                'ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as end_balance',
                [$opening]
            )
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entry_lines.id')
            ->get();

        return $rows->map(function ($row) use ($balanceSign) {
            $row->beginning_balance = $this->beginningBalanceOf($row, $balanceSign);

            return $row;
        });
    }

    /**
     * Unpaginated totals over the same date range, for the summary
     * cards (Total Debit / Total Credit / Ending Balance) — computed
     * as a single SQL sum, independent of pagination, so the cards
     * are always right regardless of which page is showing. Returned
     * even when there are zero rows in range — an Overdraft facility
     * with no activity this period still has a real Beginning/Ending
     * Balance (0.00, or whatever it opened at) and, for a facility,
     * a real Limit/Room the caller adds on top of this.
     */
    public function totals(
        int $companyId,
        int $coaAccountId,
        ?string $startDate,
        ?string $endDate,
        string $balanceSign = self::SIGN_ASSET,
        ?int $costCenterId = null,
        bool $native = false
    ): array {
        $startDate = $startDate ?: '2000-01-01';
        $endDate   = $endDate ?: now()->format('Y-m-d');

        $opening = $this->openingBalance($companyId, $coaAccountId, $startDate, $balanceSign, $costCenterId, $native);

        // ACC-002 fix (confirmed with Mahmoud, 2026-09) — kept
        // consistent with paginate()/all() above, so the header
        // totals never disagree with the detail lines underneath them.
        $sums = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.company_id', $companyId)
            ->where('journal_entry_lines.chart_of_account_id', $coaAccountId)
            ->whereBetween('journal_entries.entry_date', [$startDate, $endDate])
            ->when($costCenterId, fn ($q) => $q->where('journal_entry_lines.cost_center_id', $costCenterId))
            ->selectRaw('COALESCE(SUM('.$this->debitExpr($native).'), 0) as total_debit, COALESCE(SUM('.$this->creditExpr($native).'), 0) as total_credit, COUNT(*) as transaction_count')
            ->first();

        $totalDebit  = (string) ($sums->total_debit ?? '0.00');
        $totalCredit = (string) ($sums->total_credit ?? '0.00');
        $movement    = $balanceSign === self::SIGN_LIABILITY
            ? bcsub($totalCredit, $totalDebit, 2)
            : bcsub($totalDebit, $totalCredit, 2);
        $endingBalance = bcadd($opening, $movement, 2);

        return [
            'beginning_balance' => $opening,
            'total_debit'       => $totalDebit,
            'total_credit'      => $totalCredit,
            'ending_balance'    => $endingBalance,
            'transaction_count' => (int) ($sums->transaction_count ?? 0),
        ];
    }

    // ── Private ──────────────────────────────────────────────────

    /**
     * A row's own beginning balance, derived from its end_balance and
     * its own signed movement — never a second query.
     */
    private function beginningBalanceOf(object $row, string $balanceSign): string
    {
        return $balanceSign === self::SIGN_LIABILITY
            ? bcadd(bcsub((string) $row->end_balance, (string) $row->credit, 2), (string) $row->debit, 2)
            : bcadd(bcsub((string) $row->end_balance, (string) $row->debit, 2), (string) $row->credit, 2);
    }
}
