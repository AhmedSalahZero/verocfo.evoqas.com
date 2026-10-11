<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000048_create_factoring_agreements_table
//
//  Factoring agreements — direct (with/without recourse) + reverse.
//
//  Direct Factoring:
//    With Recourse    → COA 224001 — liability stays until invoice collected
//    Without Recourse → AR cleared directly — no liability
//
//  Reverse Factoring:
//    Supplier paid by factoring co → COA 224002 — company repays on due date
//
//  @layer    Migration
//  @depends  factoring_companies, bank_accounts, chart_of_accounts, partners
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factoring_agreements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('factoring_company_id');
            $table->string('agreement_reference', 100)->nullable();
            $table->enum('agreement_type', ['direct', 'reverse']);
            $table->enum('recourse_type', ['with_recourse', 'without_recourse'])->nullable(); // direct only
            $table->decimal('discount_rate', 8, 4)->default(0);     // % applied to invoice face value
            $table->unsignedBigInteger('coa_account_id');           // 224001 or 224002
            $table->unsignedBigInteger('bank_account_id');          // where proceeds arrive / repayments leave

            // Eligibility filters (optional)
            $table->unsignedBigInteger('eligible_partner_id')->nullable(); // restrict to specific customer/supplier
            $table->integer('min_invoice_age_days')->nullable();     // direct: invoice must be X days old
            $table->decimal('min_invoice_amount', 15, 2)->nullable();
            $table->integer('extended_payment_days')->nullable();    // reverse: days beyond original due date

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['company_id']);
            $table->index(['company_id', 'factoring_company_id']);
            $table->index(['company_id', 'agreement_type']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void { Schema::dropIfExists('factoring_agreements'); }
};
