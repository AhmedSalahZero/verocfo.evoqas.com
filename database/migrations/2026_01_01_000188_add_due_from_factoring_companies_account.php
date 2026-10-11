<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000188_add_due_from_factoring_companies_account
//
//  Data-fix, not a schema change — same class of migration as
//  2026_01_01_000176_mark_intercompany_shareholder_accounts_postable.
//
//  Confirmed with Mahmoud (Factoring Without Recourse build): the
//  retained percentage a factoring company still owes back (see COA
//  doc Section 8.1) needs its OWN traceable GL account — a Trial
//  Balance / Account Statement line, not buried inside the shared
//  140 Other Debtors bucket — same reasoning that gave Factoring
//  Liabilities their own 224001/224002 children instead of a
//  generic liability account.
//
//  This REVERSES migration 000176's fix for account 140 specifically
//  (139/230/231/232 are untouched) — 140 goes back to being a
//  non-postable Level 3 parent, with a new postable child 140001.
//  ChartOfAccountSeeder.php is updated alongside this migration so
//  every FUTURE company seeds it this way from day one.
//
//  Safe to flip 140 back to non-postable: no Vero CFO service posts
//  to account 140 yet (grep confirms), so no historical journal
//  entry lines can be pointing at it — this migration double-checks
//  that and reassigns any it finds to 140001 rather than silently
//  leaving them referencing a now-parent account.
//
//  @layer    Migration
//  @depends  chart_of_accounts, journal_entry_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        $otherDebtors = DB::table('chart_of_accounts')->whereNull('company_id')->where('code', '140')->first();

        if (! $otherDebtors) {
            return; // defensive — seeder hasn't run yet on this environment
        }

        $existingChild = DB::table('chart_of_accounts')->whereNull('company_id')->where('code', '140001')->first();

        $dueFromFactoringId = $existingChild?->id ?? DB::table('chart_of_accounts')->insertGetId([
            'company_id'     => null,
            'code'           => '140001',
            'name_en'        => 'Due From Factoring Companies',
            'name_ar'        => 'مستحق من شركات التخصيم',
            'level'          => 4,
            'section'        => 'balance_sheet',
            'class'          => 'assets',
            'account_type'   => 'Current Assets',
            'normal_balance' => 'debit',
            'parent_id'      => $otherDebtors->id,
            'is_postable'    => true,
            'allow_currency' => false,
            'is_system'      => true,
            'is_active'      => true,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        // Defensive reassignment — see header. No-op if nothing has
        // ever posted to bare 140 (expected case).
        if (DB::table('journal_entry_lines')->where('chart_of_account_id', $otherDebtors->id)->exists()) {
            \App\Support\LedgerRewriteGuard::assertAllowed('migration 000188 (re-pointing posted lines from 140 to 140001)'); // M-39
        }
        DB::table('journal_entry_lines')->where('chart_of_account_id', $otherDebtors->id)
            ->update(['chart_of_account_id' => $dueFromFactoringId]);

        DB::table('chart_of_accounts')->where('id', $otherDebtors->id)->update(['is_postable' => false]);
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')->whereNull('company_id')->where('code', '140')->update(['is_postable' => true]);
        DB::table('chart_of_accounts')->whereNull('company_id')->where('code', '140001')->delete();
    }
};
