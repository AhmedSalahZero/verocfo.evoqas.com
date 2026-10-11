<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000019_create_payment_term_lines_table
//
//  Stores the installment schedule for each payment term.
//  One payment_term has one or many lines.
//
//  Example — "50/50 Standard":
//    Line 1: 50% | 0 days  | so_date      | Down Payment
//    Line 2: 50% | 30 days | invoice_date | Balance
//
//  Example — "Net 30 Days":
//    Line 1: 100% | 30 days | invoice_date | null
//
//  reference_point drives due date calculation in AR aging,
//  AP aging, and collection follow-up reports.
//
//  percentage stored as decimal(5,2) — e.g. 50.00 = 50%
//  All percentage lines on one term must sum to 100.
//  This is enforced in PaymentTermService, not at DB level.
//
//  @layer    Migration
//  @depends  payment_terms
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_term_lines', function (Blueprint $table) {
            $table->id();

            // ── Parent ────────────────────────────────────────────
            $table->foreignId('payment_term_id')
                  ->constrained('payment_terms')
                  ->cascadeOnDelete();

            // ── Installment ───────────────────────────────────────
            // percentage: 50.00 = 50% — all lines must sum to 100
            $table->decimal('percentage', 5, 2);

            // due_days: number of days after reference_point
            // 0 = due immediately (down payment / cash)
            $table->unsignedSmallInteger('due_days')->default(0);

            // reference_point: the date this installment is calculated from
            // invoice_date | so_date | po_date | contract_date
            $table->string('reference_point', 20)->default('invoice_date');

            // ── Optional label ────────────────────────────────────
            // e.g. "Down Payment", "Balance", "Final Installment"
            $table->string('label')->nullable();

            // ── Sort order ────────────────────────────────────────
            $table->unsignedTinyInteger('sort_order')->default(0);

            // ── Timestamps ────────────────────────────────────────
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────
            $table->index(['payment_term_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_term_lines');
    }
};
