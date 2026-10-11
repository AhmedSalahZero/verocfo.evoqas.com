<?php

use App\Models\JournalEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000284_backfill_opening_balance_journal_entries
//
//  OB-001 companion. OpeningBalanceService::post() now writes a real
//  journal entry, and the five reporting services that used to fold
//  ob_coa_balances into an account's balance by hand no longer do —
//  keeping both would double every opening balance.
//
//  That leaves one gap on a database already in use: a company whose
//  opening balances were posted BEFORE this change has ob_coa_balances
//  rows, no journal entry, and now nothing folding them in either. Its
//  opening position would simply vanish from every report.
//
//  This writes the missing entry for exactly those sessions —
//  is_posted, has ob_coa_balances rows, has no opening entry yet —
//  reproducing what post() would have written: one line per row, on
//  the side that row states, dated the session's own as_of_date.
//
//  Deliberately raw inserts rather than JournalEntryService::post():
//  a backfill must reproduce history as it was, not re-run today's
//  guards against it. The period covering a two-year-old opening date
//  may well be closed by now, and refusing on that basis would leave
//  the company with no opening balances at all — which is the very
//  thing this exists to prevent. A session that does not balance is
//  skipped and reported instead of being forced through.
//
//  @layer    Migration
//  @depends  ob_sessions, ob_coa_balances, journal_entries
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        $sessions = DB::table('ob_sessions')->where('is_posted', true)->get();
        if ($sessions->isNotEmpty()) {
            \App\Support\LedgerRewriteGuard::assertAllowed('migration 000284 (back-filling opening-balance journal entries)'); // M-39
        }

        foreach ($sessions as $session) {
            $already = DB::table('journal_entries')
                ->where('company_id', $session->company_id)
                ->where('source_type', JournalEntry::SOURCE_OPENING_BALANCE)
                ->exists();

            if ($already) {
                continue;
            }

            $rows = DB::table('ob_coa_balances')
                ->where('company_id', $session->company_id)
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $debit  = '0.00';
            $credit = '0.00';
            foreach ($rows as $r) {
                if ($r->entry_side === 'debit') {
                    $debit = bcadd($debit, (string) $r->amount, 2);
                } else {
                    $credit = bcadd($credit, (string) $r->amount, 2);
                }
            }

            // Never write an unbalanced entry. A session that was
            // posted before validate() enforced this stays as it is,
            // visibly missing its entry, rather than being papered
            // over with a broken one.
            if (bccomp($debit, $credit, 2) !== 0) {
                echo "  SKIPPED company {$session->company_id}: opening balances do not net to zero "
                    ."(debit {$debit} vs credit {$credit}) — post an entry for it by hand.\n";
                continue;
            }

            $asOf   = substr((string) $session->as_of_date, 0, 10);
            $period = substr($asOf, 0, 4).'/'.substr($asOf, 5, 2);

            $seq = (int) DB::table('journal_entries')
                ->where('company_id', $session->company_id)
                ->where('entry_number', 'like', "JE/{$period}/%")
                ->selectRaw("COALESCE(MAX(CAST(SUBSTRING_INDEX(entry_number, '/', -1) AS UNSIGNED)), 0) as n")
                ->value('n');

            $entryId = DB::table('journal_entries')->insertGetId([
                'company_id'   => $session->company_id,
                'entry_number' => sprintf('JE/%s/%05d', $period, $seq + 1),
                'entry_date'   => $asOf,
                'source_type'  => JournalEntry::SOURCE_OPENING_BALANCE,
                'source_id'    => $session->id,
                'journal_type' => JournalEntry::TYPE_AUTOMATED,
                'narration'    => "Opening balances migrated as of {$asOf}",
                'status'       => JournalEntry::STATUS_POSTED,
                'created_by'   => $session->posted_by,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);

            $sort = 0;
            foreach ($rows as $r) {
                DB::table('journal_entry_lines')->insert([
                    'company_id'          => $session->company_id,
                    'journal_entry_id'    => $entryId,
                    'chart_of_account_id' => $r->account_id,
                    'cost_center_id'      => null,
                    'debit'               => $r->entry_side === 'debit'  ? $r->amount : 0,
                    'credit'              => $r->entry_side === 'credit' ? $r->amount : 0,
                    'description'         => 'Opening balance as of '.$asOf,
                    'sort_order'          => ++$sort,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
            }

            echo "  Company {$session->company_id}: opening entry written, {$rows->count()} lines, {$debit}.\n";
        }
    }

    public function down(): void
    {
        // Intentionally not reversed: deleting a company's opening
        // entry would leave it with no opening position at all, which
        // is strictly worse than the state this migration repaired.
    }
};
