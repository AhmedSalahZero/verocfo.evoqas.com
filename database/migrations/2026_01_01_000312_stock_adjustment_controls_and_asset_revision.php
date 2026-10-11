<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000312_stock_adjustment_controls_and_asset_revision
//
//  Audit M-18 — stock adjustments get a reason code, a stock-count
//  reference and a second-person approval. The request table holds an
//  adjustment while it waits; nothing reaches stock or the ledger until
//  someone else approves it. The movement itself records the reason
//  code and count reference permanently.
//
//  Audit M-20 — fixed_assets.cost_revised_at marks an asset whose cost
//  was corrected after depreciation had posted, so the remaining
//  depreciable amount is spread over the remaining months (IAS 8).
//
//  Re-runnable: every step checks before it acts. Foreign keys carry
//  short explicit names (MySQL caps identifiers at 64 characters).
//
//  @layer    Migration
//  @depends  stock_movements, fixed_assets, products, inventory_locations
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fixed_assets', 'cost_revised_at')) {
            Schema::table('fixed_assets', fn (Blueprint $t) => $t->timestamp('cost_revised_at')->nullable());
        }

        if (! Schema::hasColumn('stock_movements', 'reason_code')) {
            Schema::table('stock_movements', function (Blueprint $t) {
                $t->string('reason_code', 30)->nullable();
                $t->string('count_reference', 60)->nullable();
            });
        }

        if (! Schema::hasTable('stock_adjustment_requests')) {
            Schema::create('stock_adjustment_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('product_id');
                $t->unsignedBigInteger('inventory_location_id');
                $t->unsignedBigInteger('job_order_id')->nullable();
                $t->string('direction', 3);
                $t->decimal('quantity', 15, 4);
                $t->decimal('unit_cost', 15, 4)->nullable();
                $t->date('movement_date')->nullable();
                $t->string('reason_code', 30);
                $t->string('count_reference', 60)->nullable();
                $t->text('notes');
                $t->string('status', 10)->default('pending')->index();
                $t->unsignedBigInteger('requested_by');
                $t->unsignedBigInteger('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->text('rejection_reason')->nullable();
                $t->unsignedBigInteger('stock_movement_id')->nullable();
                $t->timestamps();

                $t->foreign('company_id', 'sar_company_fk')->references('id')->on('companies')->cascadeOnDelete();
                $t->foreign('product_id', 'sar_product_fk')->references('id')->on('products');
                $t->foreign('inventory_location_id', 'sar_location_fk')->references('id')->on('inventory_locations');
            });
        }
    }

    public function down(): void
    {
        // Forward-only.
    }
};
