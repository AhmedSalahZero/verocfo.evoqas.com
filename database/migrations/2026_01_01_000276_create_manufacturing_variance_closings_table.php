<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  create_manufacturing_variance_closings_table
//
//  Manufacturing Variance Closing Design v1.0, Section 1/Step 3,
//  piece 3 — the header record for one month's actual closing action,
//  same role PayrollRun plays for a month's payroll: one row per
//  (company, period), created the moment VarianceClosingService::
//  post() runs, and its unique constraint is what stops the same
//  period from ever being closed twice.
//
//  proposal_snapshot keeps the exact numbers that were calculated and
//  posted, as JSON — an audit record of what happened, independent of
//  whatever the underlying data looks like later.
//
//  No draft/reopen state (unlike PayrollRun/ProductionOrder) — this
//  is a closing action, not an editable document. Confirmed
//  intentional for this piece; undo, if ever needed, is a future
//  decision, not assumed here.
//
//  @layer    Migration
//  @depends  companies, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturing_variance_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('proposal_snapshot')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'period_year', 'period_month'], 'mfg_variance_closings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturing_variance_closings');
    }
};
