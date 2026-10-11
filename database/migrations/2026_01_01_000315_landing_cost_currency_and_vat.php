<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000315_landing_cost_currency_and_vat   (audit M-32)
//
//  Landing costs get (1) their own currency + rate, and (2) a separate
//  recoverable-VAT amount (customs / clearing VAT) so that VAT goes to
//  VAT Input (137) instead of being capitalised into stock.
//
//    amount                 = the cost EXCLUDING VAT, in document currency
//    vat_amount             = recoverable VAT, in document currency
//    amount_functional      = amount × rate        (capitalised into stock)
//    vat_amount_functional  = vat × rate           (Dr 137)
//
//  Existing rows: functional currency, rate 1, no VAT.
//
//  @layer    Migration
//  @depends  landing_costs, currencies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_costs', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('amount')->constrained('currencies')->nullOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1)->after('currency_id');
            $table->decimal('amount_functional', 15, 2)->nullable()->after('exchange_rate');
            $table->decimal('vat_amount', 15, 2)->default(0)->after('amount_functional');
            $table->decimal('vat_amount_functional', 15, 2)->default(0)->after('vat_amount');
        });

        DB::statement('UPDATE landing_costs SET amount_functional = amount');
    }

    public function down(): void
    {
        Schema::table('landing_costs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn(['exchange_rate', 'amount_functional', 'vat_amount', 'vat_amount_functional']);
        });
    }
};
