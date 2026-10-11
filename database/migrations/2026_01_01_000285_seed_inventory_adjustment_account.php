<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000285_seed_inventory_adjustment_account
//
//  INV-007 companion. ChartOfAccountSeeder now creates account 516
//  (Inventory Adjustment) for fresh installs; this adds it to a
//  database that has already been seeded, where the seeder will not
//  run again.
//
//  StockLedgerService::adjust() resolves this account by code and
//  throws if it is missing, so without this migration every stock
//  adjustment on an existing database would start failing — the
//  exact class of mistake ACC-006 and INV-008 were: a code change
//  that a running system needs data for, shipped without it.
//
//  Idempotent: skips if 516 already exists.
//
//  @layer    Migration
//  @depends  chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('chart_of_accounts')
            ->whereNull('company_id')->where('code', '516')->exists();

        if ($exists) {
            return;
        }

        $parent = DB::table('chart_of_accounts')
            ->whereNull('company_id')->where('code', '51')->first();

        if (! $parent) {
            // Fresh database — the seeder has not run yet and will
            // create 516 itself. Nothing to backfill.
            return;
        }

        DB::table('chart_of_accounts')->insert([
            'company_id'     => null,
            'code'           => '516',
            'name_en'        => 'Inventory Adjustment',
            'name_ar'        => 'تسويات المخزون',
            'level'          => 3,
            'section'        => 'income_statement',
            'class'          => 'expenses',
            'account_type'   => 'Cost of Goods Sold',
            'normal_balance' => 'debit',
            'parent_id'      => $parent->id,
            'is_system'      => true, // Audit L-9
            'is_postable'    => true,
            'allow_currency' => false,
            'is_active'      => true,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')
            ->whereNull('company_id')->where('code', '516')->delete();
    }
};
