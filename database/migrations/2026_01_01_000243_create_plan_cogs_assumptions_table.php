<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000243_create_plan_cogs_assumptions_table
//
//  One row per plan_product — the COGS assumption for that product,
//  shape depends on plan_products.nature (mirrors CFO Tools'
//  Manufacturing/Trading/Service branches, spec Section 3.2):
//
//    manufacturing → unit_material_cost, unit_labor_cost,
//                     overhead_pct_of_labor (a simplified stand-in for
//                     CFO Tools' full BOM/WAC inventory engine —
//                     Phase 1 of this build; a later phase can wire
//                     this to the real BillOfMaterialService for
//                     companies that already have a BOM defined)
//    trading       → unit_purchase_cost, inventory_coverage_days
//                     (same WAC/inventory-coverage logic as CFO
//                     Tools' trading branch)
//    service       → direct_cost_pct (% of revenue)
//
//  All four cost fields are stored on every row (default 0) rather
//  than three separate nature-specific tables — only the field(s)
//  matching plan_products.nature are ever populated; the calculation
//  engine reads by nature, same branching as CFO Tools' calcCOGS.js.
//
//  annual_cost_increase_pct compounds year over year, same principle
//  as manpower's annual increase (spec Section 3.2).
//
//  @layer    Migration
//  @depends  plan_products
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_cogs_assumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_product_id')->constrained()->cascadeOnDelete();

            // Manufacturing
            $table->decimal('unit_material_cost', 15, 4)->default(0);
            $table->decimal('unit_labor_cost', 15, 4)->default(0);
            $table->decimal('overhead_pct_of_labor', 8, 4)->default(0);

            // Trading
            $table->decimal('unit_purchase_cost', 15, 4)->default(0);
            $table->unsignedSmallInteger('inventory_coverage_days')->default(30);

            // Service
            $table->decimal('direct_cost_pct', 8, 4)->default(0);

            $table->decimal('annual_cost_increase_pct', 8, 4)->default(0);
            $table->decimal('beginning_inventory_units', 15, 4)->default(0);
            $table->decimal('beginning_inventory_value', 15, 2)->default(0);

            $table->timestamps();

            $table->unique('plan_product_id');
            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_cogs_assumptions');
    }
};
