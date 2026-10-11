<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000029_alter_companies_add_opening_balance_date
//
//  Adds opening_balance_date to companies.
//
//  This is the date as-of which all account balances are stated
//  when the company starts using Vero CFO. It is the cut-off
//  between historical data and live transactions.
//
//  Required field — set by Super Admin at company creation.
//  Used to auto-generate the first fiscal year and its periods.
//
//  Validation: fiscal year start_date >= opening_balance_date
//  enforced in FiscalYearService.
//
//  @layer    Migration
//  @depends  companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->date('opening_balance_date')
                  ->nullable()  // nullable for existing companies — will be required in form
                  ->after('fiscal_year_start_month');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('opening_balance_date');
        });
    }
};
