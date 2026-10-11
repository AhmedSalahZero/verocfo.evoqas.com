<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000252_create_kit_recipe_lines_table
//
//  Assembly/Kit feature — one row per component product in a
//  kit_recipes header, mirroring bom_lines' header/lines split.
//
//  quantity_per_kit is "how many of this component go into ONE unit
//  of the finished kit" — deliberately per-single-unit rather than
//  bom_lines' per-batch approach, since a Kit has no "batch size"
//  concept (no output_quantity on kit_recipes at all). A
//  KitAssemblyService scales this directly by however many kit units
//  are being assembled.
//
//  No cost column here, same reasoning as bom_lines: component cost
//  is always resolved fresh at assembly time from the component's
//  current company-wide WAC — never stored or frozen on the recipe
//  line itself.
//
//  @layer    Migration
//  @depends  companies, kit_recipes, products
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kit_recipe_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('kit_recipe_id')->constrained('kit_recipes')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products')->restrictOnDelete();

            $table->decimal('quantity_per_kit', 15, 4);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'kit_recipe_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kit_recipe_lines');
    }
};
