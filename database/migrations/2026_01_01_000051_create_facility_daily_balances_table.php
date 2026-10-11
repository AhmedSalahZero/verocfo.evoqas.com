<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000051_create_facility_daily_balances_table
//
//  Running daily balance log for OD facilities.
//  Auto-maintained by every transaction (withdrawal / settlement / charge).
//  Used for:
//    - Real-time OD position at any date
//    - Monthly interest calculation (360-day convention)
//    - Highest Debit Balance tracking per quarter
//    - CFO dashboard — running position
//
//  Interest formula: Daily = Balance × (rate / 100) / 360
//  All calculations use bcmath.
//
//  @layer    Migration
//  @depends  credit_facilities
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_daily_balances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('credit_facility_id');
            $table->date('balance_date')->unique();                // one row per day per facility
            $table->decimal('opening_balance', 15, 2);
            $table->decimal('withdrawals_today', 15, 2)->default(0);
            $table->decimal('charges_today', 15, 2)->default(0);  // interest + HDB + admin
            $table->decimal('settlements_today', 15, 2)->default(0);
            $table->decimal('closing_balance', 15, 2);            // opening + withdrawals + charges - settlements
            $table->decimal('daily_interest_amount', 15, 6);      // closing × rate / 360 (6dp precision)
            $table->boolean('is_quarter_peak')->default(false);    // highest balance this quarter
            $table->timestamps();

            $table->index(['company_id', 'credit_facility_id'],            'fdb_company_facility');
            $table->index(['company_id', 'credit_facility_id', 'balance_date'], 'fdb_company_facility_date');
            $table->index(['company_id', 'credit_facility_id', 'is_quarter_peak'], 'fdb_quarter_peak');
        });
    }

    public function down(): void { Schema::dropIfExists('facility_daily_balances'); }
};
