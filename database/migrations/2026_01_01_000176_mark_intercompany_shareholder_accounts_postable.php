<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000176_mark_intercompany_shareholder_accounts_postable
//
//  Data-fix, not a schema change — same class of migration as
//  2026_01_01_000171_fix_so_invoiced_amount_vat_bug.
//
//  Confirmed with Mahmoud (August 2026, during the Other Collection /
//  Other Payment build): accounts 139 (Intercompany Receivables), 140
//  (Other Debtors), 230 (Shareholders Current Accounts), 231
//  (Intercompany Payables), and 232 (Other Creditors) were seeded as
//  non-postable Level 3 accounts with no sub-accounts ever created
//  underneath them — an unintentional gap, not a deliberate group
//  header (compare 141 Goods In Transit / 233 GRNI, the correct
//  pattern for a childless Level 3 account: directly postable, per
//  Building Bible Rule 2's exception list). This left all five
//  genuinely unusable for any real transaction, which is exactly what
//  surfaced when Other Collection / Other Payment tried to post to
//  them.
//
//  ChartOfAccountSeeder.php is updated alongside this migration so
//  every FUTURE company seeds these five as postable from day one —
//  this migration is the one-time fix for companies whose Chart of
//  Accounts was already seeded before that change. Since every system
//  account is a single platform-wide row (company_id IS NULL — see
//  ChartOfAccountSeeder::upsert()), one UPDATE fixes every company at
//  once; no per-company loop needed.
//
//  @layer    Migration
//  @depends  chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::table('chart_of_accounts')
            ->whereNull('company_id')
            ->whereIn('code', ['139', '140', '230', '231', '232'])
            ->update(['is_postable' => true]);
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')
            ->whereNull('company_id')
            ->whereIn('code', ['139', '140', '230', '231', '232'])
            ->update(['is_postable' => false]);
    }
};
