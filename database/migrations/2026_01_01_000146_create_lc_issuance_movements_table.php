<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000146_create_lc_issuance_movements_table
//
//  One real journal LINE per LC issuance-time event — cash cover
//  placement, issuance fee, one-time commission, and (at Mark as
//  Paid) the supplier settlement + bank drawdown. Same shape and
//  posting mechanism as lg_issuance_movements from day one — unlike
//  LG's original build, LC Issuance is built AFTER JournalEntryService
//  already exists, so there is no staged-only interim phase here:
//  every movement posts a real JournalEntry the moment it's created
//  (LetterOfCreditIssuanceService::postMovementsAsJournalEntry()).
//
//  @layer    Migration
//  @depends  letter_of_credit_issuances, chart_of_accounts,
//            bank_accounts, currencies, journal_entries,
//            purchase_orders, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_issuance_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('lc_issuance_id')->constrained('letter_of_credit_issuances')->cascadeOnDelete();

            // Cost-allocation tag ONLY (not an accounting posting) —
            // same purpose as lg_issuance_movements.project_id/
            // sales_order_id, for a future Project P&L / Project Cash
            // Flow. LC's natural tag is the Purchase Order, not a
            // Project/SO pair.
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();

            $table->enum('movement_type', [
                'cash_cover_placement',   // Dr 138002 / Cr Bank
                'issuance_fee',           // Dr 554 / Cr Bank
                'commission',             // Dr 554 / Cr Bank — one-time
                'cash_cover_release',     // Dr Bank / Cr 138002 — undo mark-as-paid or undo-to-draft only if cover was never consumed
                'supplier_settlement',    // Dr AP / Cr Bank (self-financed) or Dr AP / Cr Facility liability (bank-financed) — Mark as Paid
                'settlement_principal',   // Dr Facility liability / Cr Bank — repayment installment
                'settlement_interest',    // Dr user-picked expense account / Cr Bank — repayment installment
                'expense',                // Dr user-picked expense account / Cr Bank — misc LC expense
            ]);

            $table->date('movement_date');

            $table->foreignId('debit_coa_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('credit_coa_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('debit_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('credit_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            $table->decimal('amount', 15, 2);
            $table->foreignId('currency_id')->constrained('currencies');
            $table->string('description', 300);

            $table->enum('status', ['posted', 'reversed', 'memo_only'])->default('posted');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'lc_issuance_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'purchase_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_issuance_movements');
    }
};
