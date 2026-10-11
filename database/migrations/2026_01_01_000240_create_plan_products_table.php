<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000240_create_plan_products_table
//
//  One row per product line inside a plan. Two ways a row gets here
//  (spec Section 3.1 / Decision 9):
//    - product_id set   → picked from the company's real `products`
//      table (Settings). name/nature/vat_rate/wht_rate are copied in
//      at add-time so the plan is stable even if the real product is
//      later edited or archived — a plan is a point-in-time
//      projection, not a live view.
//    - product_id NULL  → typed in fresh, plan-only. Confirmed
//      (Decision, Sept 2026): this does NOT create a real row in the
//      company's Products table. It exists only inside this plan.
//
//  @layer    Migration
//  @depends  plans, products
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

            // Nullable — see class header. When set, this is a real
            // product; when null, it's plan-only (Decision 9).
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('name', 255);
            $table->enum('nature', ['manufacturing', 'trading', 'service']);
            $table->string('uom_code', 20)->nullable();

            $table->decimal('vat_rate', 8, 4)->default(0);
            $table->decimal('wht_rate', 8, 4)->default(0);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'plan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_products');
    }
};
