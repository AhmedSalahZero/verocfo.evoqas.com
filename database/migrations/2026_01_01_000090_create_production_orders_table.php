<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000090_create_production_orders_table
//
//  Mass Production — Layer 2, piece 4 of 5. The job itself: produce
//  a given quantity of a finished good, against a specific BOM
//  version, drawing raw material from one location and landing
//  Finished Goods in another (can be the same location).
//
//  Material movement has no columns here — it's entirely
//  represented in stock_movements (production_consumption /
//  production_receipt, reserved for exactly this in migration
//  000082) via reference_type/reference_id pointing back to this
//  table. Labor and overhead cost accrue in
//  production_order_cost_entries (migration 000091) — see that
//  file's header for why those don't fit stock_movements.
//
//  Standard cost snapshot (standard_material_cost_per_unit /
//  standard_labor_cost_per_unit / standard_overhead_cost_per_unit):
//  locked in at CREATION time from the BOM + whatever standard_rates
//  are in effect that day. This is deliberate — standard costing
//  means the order's target cost doesn't silently drift if rates
//  change while the order is still open; a rate change only affects
//  orders created after it. These three columns are what actual
//  cost (from stock_movements + cost entries) gets compared against
//  for month-end variance.
//
//  status lifecycle: draft → in_progress → completed → closed.
//  completed = production_receipt has been posted, Finished Goods
//  updated. closed = variance has been calculated and disposed of
//  (write-off or prorate) — see the future variance-closing service.
//
//  actual_completion_date is set when the order moves to completed —
//  it's when standard vs actual cost becomes final for that order.
//
//  @layer    Migration
//  @depends  companies, products, bill_of_materials, inventory_locations, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('order_code', 30); // PROD/YYYY/MM/00001, immutable after creation
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('bill_of_material_id')->constrained('bill_of_materials')->restrictOnDelete();

            $table->decimal('quantity_to_produce', 15, 4);

            $table->foreignId('raw_material_location_id')->constrained('inventory_locations')->restrictOnDelete();
            $table->foreignId('output_location_id')->constrained('inventory_locations')->restrictOnDelete();

            $table->enum('status', ['draft', 'in_progress', 'completed', 'closed', 'cancelled'])->default('draft');

            $table->date('planned_start_date')->nullable();
            $table->date('actual_start_date')->nullable();
            $table->date('actual_completion_date')->nullable();

            // ── Standard cost snapshot — locked at creation, see header ──
            $table->decimal('standard_material_cost_per_unit', 15, 4)->nullable();
            $table->decimal('standard_labor_cost_per_unit', 15, 4)->nullable();
            $table->decimal('standard_overhead_cost_per_unit', 15, 4)->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'order_code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
