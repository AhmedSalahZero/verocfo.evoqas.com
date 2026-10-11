<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000251_create_kit_recipes_table
//
//  Assembly/Kit feature (VeroCFO_Manufacturing_Flexibility_Design_
//  v1_0.md, Section 3) — a lightweight alternative to
//  bill_of_materials, for combining existing stock products into a
//  new sellable product WITHOUT the full Manufacturing engine
//  (no labor hours, no machine hours, no overhead pools, no
//  standard-cost snapshot, no versioning). Available to any company
//  that carries inventory — gated by Company::hasInventory(), not
//  Company::isManufacturing() — since a Trading or Service-with-
//  Inventory company (e.g. a System Integrator) is the primary
//  target, not a factory-floor Manufacturing company.
//
//  Deliberately NOT versioned like bill_of_materials — confirmed
//  with Mahmoud: a recipe can be freely edited at any time, because
//  every KitAssembly transaction resolves its own component cost at
//  the moment of assembly (always actual WAC, never a frozen
//  standard), so there is nothing for a later recipe edit to
//  retroactively corrupt — unlike a Production Order's standard-cost
//  snapshot, which is exactly why BOM needed the Draft/Active/
//  Superseded + effective-date treatment and this table does not.
//
//  One recipe per kit product — a product cannot have two competing
//  recipes at once. If the recipe needs to change, it's edited in
//  place, not superseded (see note above for why that's safe here).
//
//  is_active lets a discontinued kit be hidden from the Assembly
//  picker without losing the historical record — same "hide, don't
//  delete" principle as Product::is_active elsewhere in this app.
//
//  @layer    Migration
//  @depends  companies, products
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kit_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // The finished "kit" product this recipe assembles. Must be a
            // has_stock = true product, enforced in KitRecipeService.
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'product_id']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kit_recipes');
    }
};
