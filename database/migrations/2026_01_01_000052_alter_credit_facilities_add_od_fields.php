<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000052_alter_credit_facilities_add_od_fields
//
//  Adds Clean Overdraft specific fields to credit_facilities table.
//  All OD types share these fields (clean / commercial paper / contract SO).
//
//  New fields:
//    account_number        → bank's account number for this specific facility
//    outstanding_balance   → opening balance at contract setup
//    balance_date          → date the opening balance was recorded
//    borrowing_rate        → base rate (%)
//    bank_margin_rate      → bank margin (%)
//    interest_rate         → auto: borrowing_rate + bank_margin_rate (stored for history)
//    min_interest_rate     → floor rate — bank charges minimum this
//    hdb_rate              → highest debit balance fee rate (%)
//    admin_fee_rate        → annual admin fee rate (%)
//    settled_max_days      → max days any withdrawal can remain open
//
//  @layer    Migration
//  @depends  credit_facilities
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            // OD-specific fields — nullable so other facility types are not affected
            $table->string('account_number', 100)->nullable()->after('facility_reference');
            $table->decimal('outstanding_balance', 15, 2)->default(0)->after('facility_limit');
            $table->date('balance_date')->nullable()->after('outstanding_balance');
            $table->decimal('borrowing_rate', 8, 4)->default(0)->after('interest_rate');
            $table->decimal('bank_margin_rate', 8, 4)->default(0)->after('borrowing_rate');
            $table->decimal('min_interest_rate', 8, 4)->default(0)->after('interest_rate');
            $table->decimal('hdb_rate', 8, 4)->default(0)->after('min_interest_rate');
            $table->decimal('admin_fee_rate', 8, 4)->default(0)->after('hdb_rate');
            $table->integer('settled_max_days')->nullable()->after('admin_fee_rate');
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropColumn([
                'account_number', 'outstanding_balance', 'balance_date',
                'borrowing_rate', 'bank_margin_rate', 'min_interest_rate',
                'hdb_rate', 'admin_fee_rate', 'settled_max_days',
            ]);
        });
    }
};
