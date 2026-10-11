<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000282_alter_production_orders_add_standard_cost_totals
//
//  MFG-004 fix (confirmed with Mahmoud, 2026-09) — same defect found
//  in JobOrderService also exists here: complete() rebuilds the
//  standard total by combining three 4dp-rounded per-unit rates
//  (material/labor/overhead) and multiplying the sum back out by
//  quantity_to_produce, which can lose a cent to rounding versus the
//  true per-batch totals those rates were originally derived from.
//  (The audit that flagged this for Job Orders believed Production
//  Orders used a different, safe calculation — checked directly:
//  they don't; both follow the identical divide-then-multiply
//  pattern.)
//
//  These three columns store the exact pre-division totals for each
//  cost component, alongside the existing per-unit columns (whose
//  meaning and existing callers are unchanged). complete() now sums
//  these three directly instead of ever multiplying a rate back out.
//
//  Nullable and purely additive — existing Production Orders created
//  before this migration simply have no value here; complete() falls
//  back to the old multiply-back calculation only for those.
//
//  @layer    Migration
//  @depends  production_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->decimal('standard_material_cost_total', 15, 2)->nullable()->after('standard_material_cost_per_unit');
            $table->decimal('standard_labor_cost_total', 15, 2)->nullable()->after('standard_labor_cost_per_unit');
            $table->decimal('standard_overhead_cost_total', 15, 2)->nullable()->after('standard_overhead_cost_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['standard_material_cost_total', 'standard_labor_cost_total', 'standard_overhead_cost_total']);
        });
    }
};
