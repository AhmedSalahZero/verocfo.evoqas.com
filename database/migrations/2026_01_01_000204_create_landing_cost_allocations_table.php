<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000204_create_landing_cost_allocations_table
//
//  See VeroCFO_Landing_Cost_Design_v1_0.md Section 5. One row per
//  (Landing Cost, Goods Receipt Note Line) pair — records exactly how
//  much of that Landing Cost's total amount was allocated to that
//  specific GRN line, and which base was used to compute the split.
//  Written once, at attach time, alongside the GRN's own posting —
//  never edited afterward (a posted GRN is immutable, same principle
//  as everywhere else in this project).
//
//  `allocation_base` is stored per LANDING COST, not per line — every
//  line of the same Landing Cost shares one base (Value / Quantity /
//  Weight / Manual), confirmed by Mahmoud: different Landing Costs
//  attached to the same GRN can each use a different base, but one
//  Landing Cost's own split is computed one way, consistently, across
//  every line it touches.
//
//  @layer    Migration
//  @depends  landing_costs, goods_receipt_note_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_cost_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('landing_cost_id')->constrained('landing_costs')->cascadeOnDelete();
            $table->foreignId('goods_receipt_note_line_id')->constrained('goods_receipt_note_lines');

            $table->enum('allocation_base', ['value', 'quantity', 'weight', 'manual']);
            $table->decimal('allocated_amount', 15, 2); // functional currency — this line's share, Dr'd to its inventory account

            $table->timestamps();

            $table->index(['company_id', 'landing_cost_id'], 'lca_company_landing_cost_idx');
            $table->index(['company_id', 'goods_receipt_note_line_id'], 'lca_company_grn_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_cost_allocations');
    }
};
