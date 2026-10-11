<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000264_drop_bom_flat_routing_fields
//
//  BOM Routing (VeroCFO_Manufacturing_Costing_Redesign_v1_0.md,
//  Section 5) — fully retires the two flat "standard labor hours per
//  batch" / "standard machine hours per batch" numbers a BOM used to
//  carry (migration 000085). Confirmed with Mahmoud (Sept 2026): no
//  need to keep these working for old data — VeroCFO is still being
//  built, not live in production — so this is a clean, one-way
//  replacement rather than a coexisting fallback. Standard labor and
//  overhead cost per batch is now computed entirely from the BOM's
//  Operations (bom_operations, migration 000265) — see
//  ProductionOrderService::standardLaborCostPerUnit()/
//  standardOverheadCostPerUnit(), rewritten in the same engagement to
//  read Operations only, no flat-field fallback left in the code.
//
//  The old blanket 'labor' StandardRate rate_type (paired with
//  overhead_pool_id/labor_category_id both null) becomes dead going
//  forward too — every new labor rate a company sets from here on is
//  a per-Labor-Category rate (rate_type = 'labor_category'). The
//  'labor' rate_type itself is left in the enum (standard_rates,
//  migration 000260) rather than removed — same "don't chase a
//  dormant value out of an enum" posture already applied to Account
//  227 (LG Contingent Liabilities) elsewhere in this app.
//
//  @layer    Migration
//  @depends  bill_of_materials
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_of_materials', function (Blueprint $table) {
            $table->dropColumn(['standard_labor_hours_per_batch', 'standard_machine_hours_per_batch']);
        });
    }

    public function down(): void
    {
        Schema::table('bill_of_materials', function (Blueprint $table) {
            $table->decimal('standard_labor_hours_per_batch', 15, 4)->nullable();
            $table->decimal('standard_machine_hours_per_batch', 15, 4)->nullable();
        });
    }
};
