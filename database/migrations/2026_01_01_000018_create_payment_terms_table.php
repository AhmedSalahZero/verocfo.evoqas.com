<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000018_create_payment_terms_table
//
//  Creates the payment_terms master table.
//  Each record is a named collection/payment term used on both
//  Customer (collection) and Supplier (payment) sides.
//
//  System terms (is_system = true) are seeded by PaymentTermSeeder
//  and cannot be deleted by the user — only custom terms can be
//  deleted. System terms can be viewed but not altered.
//
//  The actual installment schedule lives in payment_term_lines
//  (next migration) — one term has many lines.
//
//  @layer    Migration
//  @depends  companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────
            // NULL = system-level term (available to all companies)
            // SET  = company-defined custom term
            $table->foreignId('company_id')
                  ->nullable()
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── Identity ──────────────────────────────────────────
            $table->string('code', 20);           // CASH / NET30 / NET45 …
            $table->string('name_en');
            $table->string('name_ar')->nullable();

            // ── System flag ───────────────────────────────────────
            // TRUE  = seeded by system — cannot be deleted
            // FALSE = user-defined — can be deleted if not in use
            $table->boolean('is_system')->default(false);

            $table->boolean('is_active')->default(true);

            // ── Timestamps ────────────────────────────────────────
            $table->timestamps();

            // ── Constraints ───────────────────────────────────────
            $table->unique(['company_id', 'code']);

            // ── Indexes ───────────────────────────────────────────
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'is_system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_terms');
    }
};
