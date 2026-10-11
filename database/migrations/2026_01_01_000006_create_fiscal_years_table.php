<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── Identity ──────────────────────────────────────────────
            // Human-readable label — e.g. "FY2026" or "2025/2026"
            $table->string('name', 20);

            $table->date('start_date');
            $table->date('end_date');

            // ── Status ────────────────────────────────────────────────
            // open | closed
            // Only one fiscal year per company can be open at a time
            $table->string('status', 10)->default('open');

            // ── Year-End Closing ──────────────────────────────────────
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // ── Timestamps ────────────────────────────────────────────
            $table->timestamps();

            // ── Constraints ───────────────────────────────────────────
            // Year name must be unique per company
            $table->unique(['company_id', 'name']);

            // ── Indexes ───────────────────────────────────────────────
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_years');
    }
};
