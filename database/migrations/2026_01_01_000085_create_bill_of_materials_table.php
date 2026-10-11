<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000085_create_bill_of_materials_table
//
//  Mass Production — Layer 2, piece 1 of 5. The BOM header for a
//  finished-good product. Versioned (confirmed, Scope §5.4) — a
//  product can have multiple bill_of_materials rows over time; only
//  one is_active = true per product at a time, enforced in
//  BillOfMaterialService, not at the DB level (same pattern as every
//  other "one active X" rule elsewhere in this app).
//
//  output_quantity is the batch size this BOM is written for — e.g.
//  a BOM with output_quantity = 100 means every line's quantity is
//  "per 100 units produced," not per single unit. A Production Order
//  scales this by (quantity_to_produce ÷ output_quantity).
//
//  standard_labor_hours_per_batch (confirmed with Mahmoud): labor
//  routing lives on the BOM, not on the Production Order — it's
//  structural and changes with the BOM version, not month to month.
//  Multiplied by the current standard_rates labor rate (see
//  migration 000088) to derive standard labor cost per batch.
//  Nullable — a BOM can exist before labor timing is known, filled
//  in once routing is confirmed.
//
//  standard_machine_hours_per_batch — same reasoning, added for the
//  machine_hours overhead-pool allocation base (migration 000087).
//  Without this, a machine-hours pool would have no "standard usage
//  per batch" to apply its rate against — labor hours alone doesn't
//  cover it. The third allocation base, production_units, needs no
//  separate figure here — its usage per batch is just
//  output_quantity itself.
//
//  Material lines live in bom_lines (migration 000086), not here —
//  same header/lines split as every other document in this app
//  (purchase_orders/purchase_order_lines, etc.).
//
//  @layer    Migration
//  @depends  companies, products
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_of_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(true);

            $table->decimal('output_quantity', 15, 4);
            $table->decimal('standard_labor_hours_per_batch', 15, 4)->nullable();
            $table->decimal('standard_machine_hours_per_batch', 15, 4)->nullable();

            $table->date('effective_date');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'version']);
            $table->index(['company_id', 'product_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_of_materials');
    }
};
