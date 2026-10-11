<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000034_create_product_uom_lines_table
//
//  Alternate UOMs per product with conversion rates to primary UOM.
//  Max 4 UOMs per product (1 primary + 3 alternates).
//  Exactly one is_primary = true per product.
//
//  conversion_rate: how many primary units = 1 of this UOM
//  Example: 1 Barrel = 159 Liters → conversion_rate = 159
//
//  @layer    Migration
//  @depends  products, units_of_measure
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_uom_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->decimal('conversion_rate', 15, 6)->default(1.000000);
            $table->boolean('is_primary')->default(false);
            $table->boolean('use_for_purchase')->default(false);
            $table->boolean('use_for_sales')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'uom_id']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_uom_lines');
    }
};
