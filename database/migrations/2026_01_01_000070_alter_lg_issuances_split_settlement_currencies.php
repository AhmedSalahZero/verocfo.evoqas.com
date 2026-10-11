<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000070_alter_lg_issuances_split_settlement_currencies
//
//  Confirmed with Mahmoud: cash cover and commission/fees don't
//  have to sit in the same currency as each other, or as the LG
//  itself, EXCEPT for 100% Cash Cover (where cash cover is locked
//  to LG currency by definition — enforced in LgIssuanceService,
//  not the database, since Facility type genuinely allows a
//  mismatch). Both default to LG currency but are independently
//  overridable — except that lock.
//
//  cash_cover_account_id and fees_commission_account_id already
//  existed (migration 000066) — this migration only adds the two
//  currency columns that now drive which accounts appear in each
//  dropdown (bank + this specific currency, not just bank).
//
//  @layer    Migration
//  @depends  lg_issuances, currencies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lg_issuances', function (Blueprint $table) {
            $table->foreignId('cash_cover_currency_id')->nullable()->after('cash_cover_amount')->constrained('currencies')->nullOnDelete();
            $table->foreignId('commission_fees_currency_id')->nullable()->after('cash_cover_currency_id')->constrained('currencies')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lg_issuances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_cover_currency_id');
            $table->dropConstrainedForeignId('commission_fees_currency_id');
        });
    }
};
