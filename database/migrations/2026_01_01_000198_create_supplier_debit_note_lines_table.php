<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000198_create_supplier_debit_note_lines_table
//
//  One row per debited (reversed) line. supplier_bill_line_id is
//  mandatory — a debit note line always reverses a specific bill
//  line, never a bare amount, so SupplierDebitNoteService can resolve
//  the correct contra account (inventory / expense-asset / 228
//  Accrued Expenses) from that exact line's own shape — see migration
//  000197's header and migration 000107's three-shapes note.
//
//  @layer    Migration
//  @depends  supplier_debit_notes, supplier_bill_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_debit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('supplier_debit_note_id')->constrained('supplier_debit_notes')->cascadeOnDelete();
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

            $table->index(['company_id', 'supplier_debit_note_id'], 'sdnl_company_debit_note_idx');
            $table->index(['company_id', 'supplier_bill_line_id'], 'sdnl_company_bill_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_debit_note_lines');
    }
};
