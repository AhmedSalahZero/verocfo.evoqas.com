<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  AccountBalanceCalculator
//
//  Extracted from BalanceSheetService::balancesAsOf() (Sept 2026) —
//  confirmed refactor, not a redesign. That method computed every
//  account's net balance as of a single date, in one query, but was
//  private — usable only by BalanceSheetService itself. Financial
//  Reports Module Structure v1.0 §2 already established "one shared
//  balance engine, not six independent queries" as the single most
//  important structural decision behind Trial Balance/Balance Sheet/
//  Income Statement/Cash Flow/General Ledger/Account Statement — this
//  extraction applies that same principle to a new consumer: Annual
//  Plan / Business Plan & Valuation's Opening Balance step
//  (PlanOpeningBalanceService), which needs the exact same
//  "every account's balance as of one date" query and must not
//  re-implement it a second time.
//
//  Logic is UNCHANGED from the original private method — same query,
//  same opening-balance folding, same normal-balance sign handling.
//  BalanceSheetService now injects this class and delegates to it
//  instead of computing it inline.
//
//  bcmath throughout (Rule 4). Read-only.
//
//  @layer    Service
//  @depends  ChartOfAccount, JournalEntry, JournalEntryLine
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use Illuminate\Support\Collection;

class AccountBalanceCalculator
{
    /**
     * Every account's net balance as of (INCLUSIVE) $asOfDate, in one
     * query — regardless of how many accounts are passed in.
     *
     * $accounts is a Collection of ChartOfAccount, keyed by account
     * id, each carrying at least 'normal_balance' (used to sign the
     * balance correctly). Returns [accountId => '123.45', ...],
     * bcmath strings, one entry per account passed in (0.00 if no
     * activity and no opening balance).
     */
    public function balancesAsOf(int $companyId, string $asOfDate, Collection $accounts, ?int $costCenterId = null): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        // ACC-002 fix (confirmed with Mahmoud, 2026-09) — a reversed
        // entry is NOT excluded here. Reversing flips the original to
        // status = 'reversed' and posts a mirror-image entry alongside
        // it; both rows must be counted together so they net to zero.
        // Excluding the 'reversed' row (as this used to do) drops only
        // HALF the pair, which applies the transaction a second time
        // instead of cancelling it. journal_entries only ever has two
        // statuses — 'posted' and 'reversed' — so there is nothing
        // else this could ever accidentally start including.
        $query = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.company_id', $companyId)
            ->where('journal_entries.entry_date', '<=', $asOfDate);

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
            $balance = $account->normal_balance === ChartOfAccount::BALANCE_CREDIT
                ? bcsub($credit, $debit, 2)
                : bcsub($debit, $credit, 2);

            $result[$accountId] = $balance;
        }

        return $result;
    }
}
