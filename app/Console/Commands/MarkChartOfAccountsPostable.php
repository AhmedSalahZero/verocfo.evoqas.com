<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  MarkChartOfAccountsPostable
//
//  One-off console command. August 2026 audit (during the Other
//  Collection / Other Payment build, then extended company-wide at
//  Mahmoud's request) found ten Chart of Accounts accounts seeded as
//  non-postable Level 3 "headers" that never actually had any
//  sub-account underneath them, and no module anywhere in the app
//  that could ever create one automatically — genuinely unusable as
//  seeded, not a deliberate group header (compare 141 Goods In
//  Transit / 233 GRNI, the correct pattern for a childless Level 3
//  account: directly postable, per Building Bible Rule 2's exception
//  list):
//
//    139 Intercompany Receivables      140 Other Debtors
//    230 Shareholders Current Accounts 231 Intercompany Payables
//    232 Other Creditors               121 Long-Term Investments
//    122 Projects Under Progress       123 Other Long-Term Assets
//    136 Prepaid Expenses              211 Long-Term Loans
//    212 Bonds Payable                 214 Other Long-Term Liabilities
//    311 Paid Up Capital               312 Legal Reserve
//    313 General Reserve
//
//  ChartOfAccountSeeder.php is updated alongside this command so every
//  FUTURE company seeds all fifteen as postable from day one — this
//  command is the one-time fix for companies whose Chart of Accounts
//  was already seeded before that change. Since every system account
//  is a single platform-wide row (company_id IS NULL — see
//  ChartOfAccountSeeder::upsert()), this updates every company at
//  once; there is no per-company loop.
//
//  Idempotent — safe to re-run; already-postable rows are simply
//  skipped and reported as "already postable."
//
//  Usage:
//    php artisan coa:mark-postable            (report only)
//    php artisan coa:mark-postable --force     (apply)
//
//  @layer    Console Command
//  @depends  ChartOfAccount, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarkChartOfAccountsPostable extends Command
{
    protected $signature = 'coa:mark-postable {--force : Actually apply the change. Without this flag, only reports what would change.}';

    protected $description = 'Marks the confirmed-broken, sub-account-less Chart of Accounts headers (139, 140, 230, 231, 232, 121, 122, 123, 136, 211, 212, 214, 311, 312, 313) as postable for every company already on the platform.';

    private const CODES = [
        '139', '140', '230', '231', '232',
        '121', '122', '123', '136',
        '211', '212', '214',
        '311', '312', '313',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $accounts = ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->whereIn('code', self::CODES)
            ->orderBy('code')
            ->get();

        if ($accounts->isEmpty()) {
            $this->error('None of the expected codes were found in chart_of_accounts (company_id IS NULL). Nothing to do — check the seeder has run at all.');
            return self::FAILURE;
        }

        $toFix = $accounts->where('is_postable', false);

        foreach ($accounts as $account) {
            $status = $account->is_postable ? 'already postable — skipping' : ($force ? 'MARKING POSTABLE' : 'would mark postable');
            $this->line("  {$account->code} — {$account->name_en} → {$status}");
        }

        $this->newLine();

        if ($toFix->isEmpty()) {
            $this->info('All fifteen accounts are already postable. Nothing to do.');
            return self::SUCCESS;
        }

        if (! $force) {
            $this->warn("Dry run only — {$toFix->count()} account(s) would be updated. Re-run with --force to apply.");
            return self::SUCCESS;
        }

        foreach ($toFix as $account) {
            $this->markPostable($account);
        }

        $this->info("Done. Marked {$toFix->count()} account(s) postable.");
        return self::SUCCESS;
    }

    private function markPostable(ChartOfAccount $account): void
    {
        DB::transaction(function () use ($account) {
            $before = $account->toArray();

            $account->update(['is_postable' => true]);

            // System accounts (company_id = NULL) aren't scoped to one
            // company, so this is logged once per account, not once per
            // company — matches how the account itself is stored.
            AuditLog::create([
                'company_id'     => null,
                'user_id'        => null,
                'action'         => 'coa.marked_postable',
                'auditable_type' => ChartOfAccount::class,
                'auditable_id'   => $account->id,
                'before'         => $before,
                'after'          => $account->fresh()->toArray(),
                'created_at'     => now(),
            ]);
        });
    }
}
