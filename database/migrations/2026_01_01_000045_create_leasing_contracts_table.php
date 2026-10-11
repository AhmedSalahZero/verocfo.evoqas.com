<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000045_create_leasing_contracts_table
//
//  Leasing contracts — two types: operational | capital
//
//  Operational Lease:
//    accounting_treatment: expense_a → pure expense (Treatment A)
//                          rou_b     → IFRS 16 ROU asset (Treatment B)
//    Treatment is chosen at creation and LOCKED on activation (Rule 29.1)
//
//  Capital Lease:
//    Always finance lease / IFRS 16. No treatment choice.
//    asset_category_id links to fixed asset category for depreciation rate.
//
//  On activation (Treatment B / Capital):
//    - ROU sub-account auto-created under COA 114
//    - Amortization schedule auto-generated (lease_amortization_lines)
//
//  @layer    Migration
//  @depends  leasing_companies, bank_accounts, chart_of_accounts, currencies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leasing_contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('contract_reference', 100)->nullable();
            $table->unsignedBigInteger('leasing_company_id');
            $table->enum('contract_type', ['operational', 'capital']);
            $table->string('asset_description', 300);

            // Operational Lease only — treatment choice
            $table->enum('accounting_treatment', ['expense_a', 'rou_b'])->nullable();
            $table->unsignedBigInteger('expense_account_id')->nullable(); // Treatment A only

            // Financial terms
            $table->decimal('total_contract_value', 15, 2)->default(0);
            $table->decimal('monthly_payment', 15, 2)->default(0);
            $table->integer('duration_months');
            $table->decimal('borrowing_rate', 8, 4)->default(0);    // annual % — for PV calc
            $table->date('start_date');
            $table->date('end_date');                                // auto-calculated
            $table->date('first_payment_date');
            $table->date('purchase_date')->nullable();               // 1 EGP purchase date (operational)

            // Capital Lease
            $table->decimal('total_financed_value', 15, 2)->default(0);
            $table->enum('payment_frequency', ['monthly', 'quarterly'])->default('monthly');
            $table->unsignedBigInteger('asset_category_id')->nullable(); // for depreciation

            // COA links (auto-assigned on activation)
            $table->unsignedBigInteger('rou_asset_account_id')->nullable();      // 114xxx
            $table->unsignedBigInteger('rou_amort_account_id')->nullable();      // 115xxx
            $table->unsignedBigInteger('fixed_asset_account_id')->nullable();    // 111xxx (capital)
            $table->unsignedBigInteger('lease_liability_lt_account_id')->nullable(); // 213
            $table->unsignedBigInteger('lease_liability_current_account_id')->nullable(); // 234

            // Payment bank account
            $table->unsignedBigInteger('bank_account_id');

            // Calculated on activation
            $table->decimal('initial_rou_value', 15, 2)->default(0);
            $table->decimal('initial_lease_liability', 15, 2)->default(0);

            $table->enum('status', ['draft', 'active', 'closed', 'cancelled'])->default('draft');
            $table->timestamp('activated_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['company_id']);
            $table->index(['company_id', 'leasing_company_id']);
            $table->index(['company_id', 'contract_type']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'end_date']);
        });
    }

    public function down(): void { Schema::dropIfExists('leasing_contracts'); }
};
