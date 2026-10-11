<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000109_alter_leasing_contracts_add_existing_lease_fields
//
//  Lease Amortization Engine — Piece 2 (July 2026, confirmed with
//  Mahmoud). entry_type = 'existing' already existed as a concept
//  (migration 000075) but nothing captured what the user actually
//  needs to enter for it. Confirmed approach: same principle as
//  Opening Balances elsewhere in the app — the user types in where
//  things stand today, and the schedule builds forward from that,
//  rather than recalculating from the original signing date.
//
//  opening_balance_amount        — what's still owed, as of today
//  opening_balance_as_of_date    — the date that balance is true as of
//  opening_remaining_installments — how many payments are left from today
//
//  All three are nullable — only relevant when entry_type = 'existing'.
//  A 'new' lease calculates its own starting values instead (see
//  initial_rou_value / initial_lease_liability, already on this table).
//
//  @layer    Migration
//  @depends  leasing_contracts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leasing_contracts', function (Blueprint $table) {
            $table->decimal('opening_balance_amount', 15, 2)->nullable()->after('entry_type');
            $table->date('opening_balance_as_of_date')->nullable()->after('opening_balance_amount');
            $table->integer('opening_remaining_installments')->nullable()->after('opening_balance_as_of_date');
        });
    }

    public function down(): void
    {
        Schema::table('leasing_contracts', function (Blueprint $table) {
            $table->dropColumn(['opening_balance_amount', 'opening_balance_as_of_date', 'opening_remaining_installments']);
        });
    }
};
