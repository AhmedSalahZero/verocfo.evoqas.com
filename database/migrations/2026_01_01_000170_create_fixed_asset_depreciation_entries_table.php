<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000170_create_fixed_asset_depreciation_entries_table
//
//  Fixed Assets module — Phase 1. One row per posted monthly
//  depreciation period per asset — same reversible-posting shape as
//  Leasing's lease_wear_down_entries (Building Bible Rule 29.7):
//  depreciation posts automatically (never touches cash), but must
//  stay correctable. Confirmed with Mahmoud: if a late-arriving
//  Supplier Bill changes an asset's cost after depreciation already
//  posted at the provisional cost, the affected entries are REVERSED
//  and REPOSTED at the corrected amount — never silently edited.
//
//  Unique per (company, fixed_asset, period_date) — an asset can only
//  have one ACTIVE depreciation entry per calendar month; a reversed
//  entry doesn't block reposting the same period, since the reversed
//  row's own status makes it inactive (enforced in the service via a
//  status-aware check, not a naive unique constraint that would block
//  reposting after a reversal).
//
//  @layer    Migration
//  @depends  fixed_assets, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_asset_depreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();

            $table->date('period_date'); // first-of-month the entry covers
            $table->decimal('amount', 15, 2);

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->enum('status', ['posted', 'reversed'])->default('posted');

            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'fixed_asset_id', 'period_date'], 'fad_entries_company_asset_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_asset_depreciation_entries');
    }
};