<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000138_create_supplier_payment_allocations_table
//
//  The matching layer between Supplier Payments and Supplier Bills —
//  exact mirror of customer_payment_allocations. journal_entry_id
//  included from the start here (unlike Customer Collection, which
//  needed a follow-up migration to add it after the v1.0 accounting
//  error was found) — a later "Apply" allocation is a real Dr AP /
//  Cr 144 Advances movement, not GL-neutral, same corrected
//  understanding already proven on the customer side.
//
//  @layer    Migration
//  @depends  supplier_payments, supplier_bills, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->cascadeOnDelete();
            $table->foreignId('supplier_bill_id')->constrained('supplier_bills');

            $table->decimal('allocated_amount', 15, 2); // functional currency

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'supplier_payment_id'], 'spa_company_payment_idx');
            $table->index(['company_id', 'supplier_bill_id'], 'spa_company_bill_idx');
            $table->unique(['supplier_payment_id', 'supplier_bill_id'], 'spa_payment_bill_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
    }
};
