<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000043_create_credit_facilities_table
//
//  Credit facility contracts — terms only in Layer 1C.
//  Transactions (drawdowns, repayments) wired in Layer 6.
//
//  Facility types:
//    clean_od         → 223001 Clean Overdraft
//    commercial_paper → 223002 OD Against Commercial Paper
//    contract_so      → 223003 OD Against Contract/SO (collateral required)
//    cheque_discount  → 223004 Cheque Discounting
//    import_lc        → 223005 Import LC
//    letter_guarantee → 227 LG Contingent Liability
//    mtl              → 211 Medium Term Loan
//    stl              → 223 Short Term Loan
//
//  OD Against Contract/SO collateral rules (Rule 30):
//    - collateral_project_id OR collateral_so_id must be set
//    - collateral end_date must be > review_date
//    - stored as real FK — never free text
//
//  @layer    Migration
//  @depends  banks, bank_accounts, projects, sales_orders, chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_facilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('bank_id');
            $table->string('facility_reference', 100)->nullable();  // bank's own ref number
            $table->enum('facility_type', [
                'clean_od', 'commercial_paper', 'contract_so',
                'cheque_discount', 'import_lc', 'letter_guarantee',
                'mtl', 'stl',
            ]);
            $table->unsignedBigInteger('coa_account_id');           // links to correct COA account
            $table->unsignedBigInteger('bank_account_id')->nullable(); // linked current account for drawdowns
            $table->unsignedBigInteger('currency_id');

            // Core facility fields
            $table->decimal('facility_limit', 15, 2)->default(0);  // for OD/LC/LG
            $table->decimal('interest_rate', 8, 4)->default(0);    // annual %
            $table->date('start_date');
            $table->date('review_date')->nullable();               // OD/STL review/renewal date
            $table->date('expiry_date')->nullable();               // LG/LC/STL expiry
            $table->string('purpose', 500)->nullable();

            // OD Against Contract/SO collateral (Rule 30)
            $table->enum('collateral_type', ['project', 'sales_order'])->nullable();
            $table->unsignedBigInteger('collateral_project_id')->nullable();
            $table->unsignedBigInteger('collateral_so_id')->nullable();
            $table->enum('collateral_status', ['valid', 'expired', 'warning'])->default('valid');

            // MTL specific
            $table->decimal('principal_amount', 15, 2)->default(0);
            $table->integer('tenure_months')->nullable();
            $table->date('first_payment_date')->nullable();

            // LG specific
            $table->string('lg_beneficiary', 300)->nullable();
            $table->string('lg_beneficiary_ar', 300)->nullable();
            $table->decimal('lg_margin_pct', 5, 2)->default(0);    // cash cover %
            $table->unsignedBigInteger('lg_cash_cover_account_id')->nullable(); // 132 sub-account

            // Import LC specific
            $table->unsignedBigInteger('lc_supplier_id')->nullable(); // Partner
            $table->date('lc_shipment_date')->nullable();
            $table->string('lc_settlement_terms', 200)->nullable();
            $table->unsignedBigInteger('lc_cash_cover_account_id')->nullable();

            // Cheque Discounting specific
            $table->enum('cheque_recourse', ['with_recourse', 'without_recourse'])->nullable();

            $table->enum('status', ['active', 'expired', 'closed', 'cancelled'])->default('active');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['company_id']);
            $table->index(['company_id', 'bank_id']);
            $table->index(['company_id', 'facility_type']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'collateral_status']);
            $table->index(['company_id', 'expiry_date']);           // for expiry alerts
            $table->index(['company_id', 'collateral_project_id']);
            $table->index(['company_id', 'collateral_so_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('credit_facilities'); }
};
