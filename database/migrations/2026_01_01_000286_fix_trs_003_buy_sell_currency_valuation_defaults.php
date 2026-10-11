<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000286_fix_trs_003_buy_sell_currency_valuation_defaults
//
//  TRS-003 (Audit, 8 Sep 2026). buy_sell_currencies has five columns
//  that are only ever calculated by BuySellCurrencyService::post() —
//  sell_reference_rate, buy_reference_rate, sell_fair_value_functional,
//  buy_fair_value_functional, realized_fx_gain_loss. All five were
//  declared NOT NULL with no default, so BuySellCurrencyService::
//  create() — which never sets them, by design, since they don't
//  exist yet on a draft — failed on the very first insert.
//
//  The audit named the first two (the ones MySQL happened to reject
//  first); the same missing-default problem is on all five, so
//  fixing only two would just move the crash to the third column.
//
//  The fix follows the pattern already established elsewhere in this
//  codebase for exactly this situation — a number that only means
//  something once a transaction is posted. CustomerPayment's own
//  fx_gain_loss_functional column (2026_01_01_000208) is NOT
//  nullable — it carries a built-in default of 0, and
//  CustomerPaymentService::setToDraft() resets it back to '0', never
//  to null. A draft always has real, safe numbers — zero, meaning
//  "not calculated yet" — never an empty value. This migration brings
//  buy_sell_currencies' five valuation columns in line with that same
//  convention (matching each column's own existing decimal
//  precision, unchanged).
//
//  @layer    Migration
//  @depends  buy_sell_currencies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buy_sell_currencies', function (Blueprint $table) {
            $table->decimal('sell_reference_rate', 20, 10)->default(0)->change();
            $table->decimal('buy_reference_rate', 20, 10)->default(0)->change();
            $table->decimal('sell_fair_value_functional', 18, 2)->default(0)->change();
            $table->decimal('buy_fair_value_functional', 18, 2)->default(0)->change();
            $table->decimal('realized_fx_gain_loss', 18, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('buy_sell_currencies', function (Blueprint $table) {
            $table->decimal('sell_reference_rate', 20, 10)->default(null)->change();
            $table->decimal('buy_reference_rate', 20, 10)->default(null)->change();
            $table->decimal('sell_fair_value_functional', 18, 2)->default(null)->change();
            $table->decimal('buy_fair_value_functional', 18, 2)->default(null)->change();
            $table->decimal('realized_fx_gain_loss', 18, 2)->default(null)->change();
        });
    }
};
