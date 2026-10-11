<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000253_create_kit_assemblies_table
//
//  Assembly/Kit feature — the transaction header for "assemble N
//  units of this kit." One location for both sides (components are
//  drawn from it, the kit is received into it) — deliberately
//  simpler than Production Order's separate raw_material_location_id
//  / output_location_id, matching the "lighter" design brief.
//
//  No separate line-items table for the components consumed — same
//  pattern ProductionOrder already uses: the actual components and
//  quantities consumed are read back from stock_movements, filtered
//  by reference_type/reference_id = this row (movement_type
//  'kit_consumption'), never duplicated into a second table. See
//  KitAssemblyService for the read path.
//
//  status: 'posted' | 'reversed'. No 'draft' — an assembly is a
//  single atomic action (components out + kit in + one journal
//  entry, all in one DB transaction), there is nothing partial to
//  save as a draft. To undo a mistake, reverse() it — same
//  can_reverse-not-can_delete principle as every other financial
//  write in this app.
//
//  total_cost is the exact value posted to the GL (the kit
//  product's own wac_total_value on its receipt movement) — stored
//  here too purely for fast list-page display, never used as a
//  second source of truth; StockLedgerService's own tables remain
//  authoritative.
//
//  @layer    Migration
//  @depends  companies, kit_recipes, products, inventory_locations,
//            journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kit_assemblies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('assembly_code', 30);
            $table->foreignId('kit_recipe_id')->constrained('kit_recipes')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->restrictOnDelete();

            $table->decimal('quantity', 15, 4);
            $table->decimal('total_cost', 15, 2)->default(0);

            $table->enum('status', ['posted', 'reversed'])->default('posted');
            $table->date('assembly_date');

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'assembly_code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kit_assemblies');
    }
};
