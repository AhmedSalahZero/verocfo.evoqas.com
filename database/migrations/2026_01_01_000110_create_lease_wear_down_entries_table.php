<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000110_create_lease_wear_down_entries_table
//
//  Lease Amortization Engine — Piece 3 (July 2026, confirmed with
//  Mahmoud). Kept as its own table, separate from
//  lease_amortization_lines, because it has a genuinely different
//  lifecycle:
//
//    lease_amortization_lines — the PAYMENT schedule. Never posts on
//    its own — the user records each payment themselves, whenever
//    they actually pay it (confirmed: never automatic, could
//    mis-state the real bank balance if it were).
//
//    lease_wear_down_entries (this table) — the ASSET side. Posts
//    automatically each period, since it never touches cash — but
//    confirmed the user must still be able to adjust it if needed
//    (early termination, a correction, etc.), via the same
//    reverse-then-repost pattern JournalEntryService already uses
//    everywhere else. 'status' tracks that lifecycle directly.
//
//  Covers both cases that need this:
//    - Operating Lease Treatment B → ROU asset amortization (563/115xxx)
//    - Capital Lease → straight-line depreciation (561/113xxx) — a
//      deliberately simple stand-in until the real Fixed Assets &
//      Depreciation module exists (see LeaseAmortizationService
//      header for the full note on this).
//
//  @layer    Migration
//  @depends  leasing_contracts, journal_entries
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lease_wear_down_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('leasing_contract_id');
            $table->integer('period_number');
            $table->date('entry_date');
            $table->decimal('amount', 15, 2);
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable();
            $table->enum('status', ['posted', 'reversed'])->default('posted');
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'leasing_contract_id'], 'lwd_company_contract');
            $table->index(['company_id', 'leasing_contract_id', 'period_number'], 'lwd_company_contract_period');
            $table->index(['company_id', 'status'], 'lwd_company_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lease_wear_down_entries');
    }
};
