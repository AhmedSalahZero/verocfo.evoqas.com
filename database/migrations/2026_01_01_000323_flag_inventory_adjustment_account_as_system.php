<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000323_flag_inventory_adjustment_account_as_system   (audit L-9)
//
//  Migration 000285 inserted account 516 "Inventory Adjustment" without
//  is_system = true (the column defaults to false), while the seeder marks
//  every system account. On databases that got 516 through the migration
//  it could be edited or deactivated like a user account. Sets the flag.
//
//  @layer    Migration
//  @depends  chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::table('chart_of_accounts')->whereNull('company_id')->where('code', '516')->update(['is_system' => true]);
    }

    public function down(): void
    {
        // Intentionally empty — a system account stays a system account.
    }
};
