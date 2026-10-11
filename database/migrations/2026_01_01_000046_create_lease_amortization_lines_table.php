<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000046_create_lease_amortization_lines_table
//
//  Auto-generated amortization schedule for Treatment B + Capital leases.
//  One row per payment period for the full contract life.
//  All amounts calculated using bcmath — never PHP float.
//
//  Fix v1.1: Explicit short index names to respect MySQL 64-char limit.
//
//  @layer    Migration
//  @depends  leasing_contracts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_amortization_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('leasing_contract_id');
            $table->integer('period_number');
            $table->date('payment_date');
            $table->decimal('payment_amount', 15, 2);
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('interest_amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->boolean('is_paid')->default(false);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->timestamps();

            // Explicit short names — MySQL index name limit is 64 characters
            $table->index(['company_id', 'leasing_contract_id'],               'lal_company_contract');
            $table->index(['company_id', 'leasing_contract_id', 'payment_date'],'lal_company_contract_date');
            $table->index(['company_id', 'leasing_contract_id', 'is_paid'],     'lal_company_contract_paid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_amortization_lines');
    }
};