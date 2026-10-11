<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  DedupeBankAccountNumbers
//
//  One-off console command. account_number on both bank_accounts and
//  credit_facilities had no uniqueness validation until the August
//  2026 fix (see UniqueBankAccountNumber + the Form Requests that now
//  use it). Any duplicate rows created before that fix will now fail
//  the moment someone re-saves them through the UI, even though
//  nothing about their own data changed.
//
//  This command finds every account number repeated at the same bank
//  for the same company — across BOTH tables combined, since a real
//  bank account number is one physical thing regardless of which
//  Vero CFO table it was typed into — and CLEARS account_number
//  (sets it to NULL) on every duplicate row except the oldest. It
//  does NOT delete the underlying Bank Account or Credit Facility
//  record itself — those are real financial/contract records, and
//  Rule 15.2 (no hard deletes on financial data) applies here even
//  though this is a cleanup script, not a user action. Clearing the
//  number is enough to resolve the collision; the user can then type
//  the correct distinct number back in for whichever row actually
//  needs one.
//
//  Every change writes a before/after row to audit_log (Rule 5).
//
//  Defaults to a dry run — nothing is changed unless --force is
//  passed. Safe to re-run.
//
//  Usage:
//    php artisan bankaccounts:dedupe-numbers            (report only)
//    php artisan bankaccounts:dedupe-numbers --force     (apply)
//
//  @layer    Console Command
//  @depends  BankAccount, CreditFacility, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DedupeBankAccountNumbers extends Command
{
    protected $signature = 'bankaccounts:dedupe-numbers {--force : Actually clear the duplicates. Without this flag, only reports what would change.}';

    protected $description = 'Finds account numbers repeated at the same bank (across bank_accounts and credit_facilities) and clears the duplicate ones, keeping the oldest record.';

    /** @var array<string,class-string> */
    private const TABLE_MODELS = [
        'bank_accounts'     => BankAccount::class,
        'credit_facilities' => CreditFacility::class,
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $rows = collect();

        foreach (array_keys(self::TABLE_MODELS) as $table) {
            DB::table($table)
                ->select('id', 'company_id', 'bank_id', 'account_number', 'created_at')
                ->whereNotNull('account_number')
                ->where('account_number', '!=', '')
                ->orderBy('created_at')
                ->get()
                ->each(fn ($row) => $rows->push((object) [
                    'table'          => $table,
                    'id'             => $row->id,
                    'company_id'     => $row->company_id,
                    'bank_id'        => $row->bank_id,
                    'account_number' => $row->account_number,
                    'created_at'     => $row->created_at,
                ]));
        }

        $groups = $rows
            ->groupBy(fn ($r) => $r->company_id . '|' . $r->bank_id . '|' . $r->account_number)
            ->filter(fn ($group) => $group->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('No duplicate account numbers found. Nothing to do.');
            return self::SUCCESS;
        }

        $cleared = 0;

        foreach ($groups as $group) {
            $sorted = $group->sortBy('created_at')->values();
            $keep   = $sorted->first();

            $this->line("Account number '{$keep->account_number}' (company #{$keep->company_id}, bank #{$keep->bank_id}) appears {$sorted->count()} times:");

            foreach ($sorted as $i => $row) {
                $label = $i === 0
                    ? 'KEEP (oldest)'
                    : ($force ? 'CLEARING' : 'would clear');
                $this->line("  - {$row->table} #{$row->id} (created {$row->created_at}) → {$label}");
            }

            if ($force) {
                foreach ($sorted->slice(1) as $row) {
                    $this->clearRow($row);
                    $cleared++;
                }
            }
        }

        $this->newLine();

        if (! $force) {
            $this->warn('Dry run only — no changes made. Re-run with --force to clear the duplicate account numbers.');
        } else {
            $this->info("Done. Cleared account_number on {$cleared} duplicate row(s). The oldest record in each group kept its number.");
        }

        return self::SUCCESS;
    }

    private function clearRow(object $row): void
    {
        DB::transaction(function () use ($row) {
            $before = (array) DB::table($row->table)->where('id', $row->id)->first();

            DB::table($row->table)->where('id', $row->id)->update([
                'account_number' => null,
                'updated_at'     => now(),
            ]);

            AuditLog::create([
                'company_id'     => $row->company_id,
                'user_id'        => null,
                'action'         => 'dedupe_bank_account_number',
                'auditable_type' => self::TABLE_MODELS[$row->table],
                'auditable_id'   => $row->id,
                'before'         => $before,
                'after'          => array_merge($before, ['account_number' => null]),
                'created_at'     => now(),
            ]);
        });
    }
}
