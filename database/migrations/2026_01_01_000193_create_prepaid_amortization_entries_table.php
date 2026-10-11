<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000193_create_prepaid_amortization_entries_table
//
//  One row PER POST ACTION — not a pre-generated 12-row schedule.
//  Deliberately mirrors lease_wear_down_entries exactly (see that
//  migration + LeaseAmortizationService::postMonthlyWearDown()): the
//  user clicks "Post Next Amortization" on a Prepaid Expense Bill
//  line, PrepaidAmortizationService computes that period's amount
//  live (from amortization_months + the mid-month convention) and
//  writes one row here. No scheduled job, no due-date matching — same
//  proven, manual, click-per-period pattern already running in
//  production for Lease asset wear-down, chosen deliberately over a
//  more complex pre-built schedule + auto-posting job.
//
//  Reversible, never deletable, per the same reverse-then-repost
//  principle used everywhere else in this codebase (Building Bible
//  Rule 29.7) — status flips to 'reversed' via the normal
//  JournalEntryService::reverse() mirror-image entry, the row itself
//  is never touched or removed.
//
//  @layer    Migration
//  @depends  companies, supplier_bill_lines, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prepaid_amortization_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('supplier_bill_line_id')->constrained('supplier_bill_lines')->cascadeOnDelete();

            $table->unsignedSmallInteger('period_number'); // 1-based, matches how many times this line has been posted
            $table->date('entry_date'); // when the user actually clicked "post" — not forced to match a calendar due-date
            $table->decimal('amount', 15, 2);

            $table->foreignId('journal_entry_id')->constrained('journal_entries');
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->enum('status', ['posted', 'reversed'])->default('posted');

            $table->foreignId('posted_by')->constrained('users');
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'supplier_bill_line_id'], 'prepaid_amort_entries_company_line_idx'); // explicitly named — default auto-generated name (69 chars) exceeds MySQL's 64-char identifier limit, same fix as supplier_bill_lines migration 000107
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prepaid_amortization_entries');
    }
};