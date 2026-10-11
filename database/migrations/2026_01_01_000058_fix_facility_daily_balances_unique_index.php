<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000058_fix_facility_daily_balances_unique_index
//
//  Bug: migration 000051 defined balance_date as ->unique() alone.
//  This means only one row can exist for any given date across
//  the ENTIRE table — so two facilities with the same opening
//  balance date (e.g. both 2026-01-01) cause a duplicate key
//  violation on the second insert.
//
//  Fix: drop the broken single-column unique index, replace with
//  a composite unique on (credit_facility_id, balance_date).
//  This is the correct constraint: one row per day per facility.
//
//  The (company_id, credit_facility_id, balance_date) index that
//  already exists (fdb_company_facility_date) covers all query
//  patterns — we add the composite unique on top of it for data
//  integrity enforcement.
//
//  @layer    Migration
//  @depends  facility_daily_balances (migration 000051)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_daily_balances', function (Blueprint $table) {
            // Drop the broken single-column unique index.
            // Laravel names a ->unique() column index as:
            // {table}_{column}_unique
            $table->dropUnique('facility_daily_balances_balance_date_unique');

            // Add the correct composite unique: one row per day per facility
            $table->unique(['credit_facility_id', 'balance_date'], 'fdb_facility_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('facility_daily_balances', function (Blueprint $table) {
            $table->dropUnique('fdb_facility_date_unique');
            $table->unique('balance_date');
        });
    }
};
