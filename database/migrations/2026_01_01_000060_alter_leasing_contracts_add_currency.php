<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000060_alter_leasing_contracts_add_currency
//
//  leasing_contracts had no currency_id at all — total_contract_value
//  and monthly_payment were raw decimals with no currency tied to them.
//
//  Adds:
//    currency_id    → required, the currency the contract is denominated in
//    exchange_rate  → rate to functional currency at contract signing.
//                     Defaults to 1.000000. Only meaningful (and shown
//                     in the UI) when currency_id differs from the
//                     company's functional currency — reserved for
//                     FX gain/loss calculation when the Accounting
//                     Engine is built.
//
//  @layer    Migration
//  @depends  leasing_contracts, currencies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leasing_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('currency_id')->after('asset_description');
            $table->decimal('exchange_rate', 15, 6)->default(1)->after('currency_id');

            $table->index(['company_id', 'currency_id']);
        });
    }

    public function down(): void
    {
        Schema::table('leasing_contracts', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'currency_id']);
            $table->dropColumn(['currency_id', 'exchange_rate']);
        });
    }
};
