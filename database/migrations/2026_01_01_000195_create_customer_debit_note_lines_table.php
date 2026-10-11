<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000195_create_customer_debit_note_lines_table
//
//  One row per debited line. customer_invoice_line_id is mandatory —
//  a debit note line always adds to a specific invoice line's value,
//  never a bare amount, so the extra revenue lands on the same
//  account the original line used. Mirrors credit_note_lines
//  (migration 000121) exactly, opposite direction.
//
//  @layer    Migration
//  @depends  customer_debit_notes, customer_invoice_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_debit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('customer_debit_note_id')->constrained('customer_debit_notes')->cascadeOnDelete();
            $table->foreignId('customer_invoice_line_id')->constrained('customer_invoice_lines');

            $table->string('description', 500);
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_subtotal', 15, 2);

            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);

            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'customer_debit_note_id'], 'cdnl_company_debit_note_idx');
            $table->index(['company_id', 'customer_invoice_line_id'], 'cdnl_company_invoice_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_debit_note_lines');
    }
};
