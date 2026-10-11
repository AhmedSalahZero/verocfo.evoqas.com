<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000099_create_journal_entry_lines_table
//
//  Debit/credit lines for journal_entries. Every line references a
//  postable (Level 4/5) chart_of_accounts row — enforced in
//  JournalEntryService::post(), not here — and carries exactly one
//  non-zero side (debit XOR credit), also enforced in the service.
//
//  cost_center_id is nullable — only accounts flagged
//  requires_cost_center on the COA actually require one; that check
//  also lives in the service, matching where every other cross-field
//  validation in this codebase lives (Rule 3 — service-first).
//
//  @layer    Migration
//  @depends  journal_entries, chart_of_accounts, cost_centers
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();

            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts');
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();

            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);

            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'chart_of_account_id']);
            $table->index(['journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
