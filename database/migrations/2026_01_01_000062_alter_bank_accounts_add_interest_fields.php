<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000062_alter_bank_accounts_add_interest_fields
//
//  Some current accounts pay daily interest on the balance (bank
//  product feature), sometimes only above a minimum balance. These
//  are distinct from td_interest_rate, which is specific to Time
//  Deposit maturity terms.
//
//  Both fields are nullable and editable — banks change these terms
//  over time, and every change is logged in bank_account_terms_history
//  (see migration 000063) rather than silently overwritten.
//
//  @layer    Migration
//  @depends  bank_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->decimal('interest_rate', 8, 4)->nullable()->after('coa_account_id');
            $table->decimal('minimum_balance_for_interest', 15, 2)->nullable()->after('interest_rate');
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['interest_rate', 'minimum_balance_for_interest']);
        });
    }
};
