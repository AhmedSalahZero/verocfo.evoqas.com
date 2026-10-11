<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── Parent Fiscal Year ────────────────────────────────────
            $table->foreignId('fiscal_year_id')
                  ->constrained('fiscal_years')
                  ->cascadeOnDelete();

            // ── Identity ──────────────────────────────────────────────
            // Human-readable — e.g. "January 2026" or "Mar 2026"
            $table->string('name', 30);

            $table->date('start_date');
            $table->date('end_date');

            // Period number within the fiscal year — 1 through 12
            $table->unsignedTinyInteger('period_number');

            // ── Status ────────────────────────────────────────────────
            // open | closed
            // Closed period — no posting allowed without authorized override
            $table->string('status', 10)->default('open');

            // ── Closing Audit ─────────────────────────────────────────
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // Prior period adjustment — tracks if a closed period was reopened
            $table->boolean('was_reopened')->default(false);
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // ── Timestamps ────────────────────────────────────────────
            $table->timestamps();

            // ── Constraints ───────────────────────────────────────────
            // One period number per fiscal year per company
            // Explicit short name — MySQL 64-char index name limit
            $table->unique(
                ['company_id', 'fiscal_year_id', 'period_number'],
                'ap_company_fy_period_unique'
            );

            // ── Indexes ───────────────────────────────────────────────
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'start_date']);
            $table->index(['company_id', 'fiscal_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
    }
};