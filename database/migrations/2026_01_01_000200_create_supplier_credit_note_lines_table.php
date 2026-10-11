<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000200_create_supplier_credit_note_lines_table
//
//  One row per affected line. supplier_bill_line_id is mandatory — a
//  credit note line always adds to a specific bill line's value,
//  never a bare amount, so SupplierCreditNoteService can resolve the
//  correct contra account from that exact line's own shape — same
//  resolution mechanism as Supplier Debit Note (migration 000198),
//  posted in the opposite direction.
//
//  @layer    Migration
//  @depends  supplier_credit_notes, supplier_bill_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('supplier_credit_note_id')->constrained('supplier_credit_notes')->cascadeOnDelete();
            $table->foreignId('supplier_bill_line_id')->constrained('supplier_bill_lines');

            $table->string('description', 500);
            $table->decimal('quantity', 15, 4)->default(1);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_subtotal', 15, 2);

            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);

            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'supplier_credit_note_id'], 'scnl_company_credit_note_idx');
            $table->index(['company_id', 'supplier_bill_line_id'], 'scnl_company_bill_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_credit_note_lines');
    }
};
