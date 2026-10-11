<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000205_create_grn_landing_cost_selections_table
//
//  See VeroCFO_Landing_Cost_Design_v1_0.md and the follow-up
//  discussion on Set to Draft. A Landing Cost's actual attachment
//  (LandingCostAllocation rows, landing_costs.goods_receipt_note_id)
//  only happens at POST time — but the user needs to pick which
//  Landing Costs apply, and with which allocation base, while the
//  GRN is still a DRAFT, the same way GRN lines themselves are picked
//  before posting.
//
//  This table is that intent — nothing here means anything is
//  actually attached yet. GoodsReceiptNoteService::post() reads these
//  rows to know what to attach; GoodsReceiptNoteService::setToDraft()
//  releases the real attachment (LandingCost::goods_receipt_note_id,
//  LandingCostAllocation rows) but leaves THESE rows untouched, so
//  re-posting the same GRN replays the same picks without the user
//  re-selecting anything.
//
//  `manual_amounts` is a JSON map of goods_receipt_note_line_id =>
//  amount, only meaningful when allocation_base = 'manual' — same
//  shape LandingCostService::attach() already expects.
//
//  One row per (GRN, Landing Cost) pick — a company_id + unique pair
//  prevents the same Landing Cost being picked twice on one draft.
//
//  @layer    Migration
//  @depends  goods_receipt_notes, landing_costs
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grn_landing_cost_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('goods_receipt_note_id')->constrained('goods_receipt_notes')->cascadeOnDelete();
            $table->foreignId('landing_cost_id')->constrained('landing_costs')->cascadeOnDelete();

            $table->enum('allocation_base', ['value', 'quantity', 'weight', 'manual']);
            $table->json('manual_amounts')->nullable(); // manual base only — see header

            $table->timestamps();

            $table->unique(['goods_receipt_note_id', 'landing_cost_id'], 'grn_lcs_grn_landing_cost_unique');
            $table->index(['company_id', 'landing_cost_id'], 'grn_lcs_company_landing_cost_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_landing_cost_selections');
    }
};
