<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000281_alter_job_orders_add_estimated_material_cost_total
//
//  MFG-004 fix (confirmed with Mahmoud, 2026-09) — complete() used to
//  rebuild the material total by multiplying
//  estimated_material_cost_per_unit (a 4dp snapshot, itself already
//  rounded once when it was derived as total ÷ quantity) back out by
//  quantity_to_produce. That round trip loses a cent whenever the
//  division didn't come out even (reproduced: 1,000.00 ÷ 3 = 333.3333,
//  then 333.3333 × 3 = 999.99 — a real cent gone missing, reported as
//  a "variance" that isn't one).
//
//  This column stores the exact total BEFORE that division ever
//  happens, alongside the per-unit rate (which keeps its existing
//  meaning and existing callers unchanged). complete() now uses this
//  column directly instead of ever multiplying back out — the number
//  that went in is the number that comes back, with nothing lost to
//  rounding.
//
//  Nullable and purely additive — existing Job Orders created before
//  this migration simply have no value here; complete() falls back to
//  the old multiply-back calculation only for those, so nothing
//  already in progress breaks.
//
//  @layer    Migration
//  @depends  job_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->decimal('estimated_material_cost_total', 15, 2)->nullable()->after('estimated_material_cost_per_unit');
        });
    }

    public function down(): void
    {
        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropColumn('estimated_material_cost_total');
        });
    }
};
