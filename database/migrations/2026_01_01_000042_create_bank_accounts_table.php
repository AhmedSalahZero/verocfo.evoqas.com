<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000042_create_bank_accounts_table
//
//  Bank accounts — one record per account per bank.
//  Each account links to a COA 132 sub-account.
//  Time Deposits carry additional maturity/interest fields.
//  LG/LC Cash Cover accounts tagged with is_cash_cover = true.
//
//  Account types: current | time_deposit | escrow | lg_cash_cover | lc_cash_cover
//
//  @layer    Migration
//  @depends  banks, currencies, chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('bank_id');
            $table->string('account_name', 200);
            $table->string('account_number', 100)->nullable();
            $table->enum('account_type', ['current', 'time_deposit', 'escrow', 'lg_cash_cover', 'lc_cash_cover'])->default('current');
            $table->unsignedBigInteger('currency_id');
            $table->unsignedBigInteger('coa_account_id');          // links to 132xxx sub-account
            $table->boolean('is_cash_cover')->default(false);       // true for LG/LC cover accounts
            $table->unsignedBigInteger('linked_facility_id')->nullable(); // for LG/LC cover accounts

            // Time Deposit specific fields
            $table->date('td_start_date')->nullable();
            $table->date('td_maturity_date')->nullable();
            $table->decimal('td_interest_rate', 8, 4)->nullable();  // annual %
            $table->boolean('td_auto_renewal')->default(false);
            $table->decimal('td_amount', 15, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['company_id']);
            $table->index(['company_id', 'bank_id']);
            $table->index(['company_id', 'account_type']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'td_maturity_date']); // for maturity alerts
        });
    }

    public function down(): void { Schema::dropIfExists('bank_accounts'); }
};
