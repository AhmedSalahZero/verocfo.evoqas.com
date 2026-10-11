<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000086_create_bom_lines_table
//
//  Mass Production — Layer 2, piece 1 of 5 (continued). Material
//  components for a BOM — mirrors the header/lines split used by
//  purchase_order_lines/sales_order_lines. quantity_per_batch is
//  scaled by a Production Order against bill_of_materials.
//  output_quantity — never entered per-order, always derived from
//  the BOM.
//
//  No cost column here — material cost is NEVER stored on the BOM
//  line. It's resolved at Production Order time from whatever the
//  component's current company-wide WAC is (product_stock_balances),
//  same principle as Purchase Order lines not storing an account
//  (resolved automatically elsewhere, not hand-entered and frozen).
//
//  @layer    Migration
//  @depends  bill_of_materials, products, units_of_measure
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('bill_of_material_id')->constrained('bill_of_materials')->cascadeOnDelete();

            $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity_per_batch', 15, 4);
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'bill_of_material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bom_lines');
    }
};
