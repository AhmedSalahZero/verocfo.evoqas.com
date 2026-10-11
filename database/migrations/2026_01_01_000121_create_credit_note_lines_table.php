<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000121_create_credit_note_lines_table
//
//  One row per credited line. customer_invoice_line_id is mandatory
//  — a credit note line always credits back a specific invoice line,
//  never a bare amount, so revenue reversal always lands on the same
//  account it was originally recognized on.
//
//  @layer    Migration
//  @depends  credit_notes, customer_invoice_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
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

            $table->index(['company_id', 'credit_note_id']);
            $table->index(['company_id', 'customer_invoice_line_id'], 'cnl_company_invoice_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');
    }
};
