<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000040_create_sales_order_lines_table
//
//  Line items on each Sales Order.
//  Products / Services / Expense items.
//  Unit price and quantity define line total.
//  Revenue account is auto-assigned from product — user can override.
//
//  @layer    Migration
//  @depends  sales_orders, products, units_of_measure, chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('sales_order_id');
            $table->unsignedBigInteger('product_id')->nullable();   // nullable for free-text lines
            $table->string('description', 500);                     // editable line description
            $table->decimal('quantity', 15, 4)->default(1);
            $table->unsignedBigInteger('uom_id')->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount_pct', 5, 2)->default(0);      // line discount %
            $table->decimal('line_total', 15, 2)->default(0);       // qty × price - discount
            $table->unsignedBigInteger('revenue_account_id')->nullable(); // COA — auto from product
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Indexes
            $table->index(['company_id', 'sales_order_id']);
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_lines');
    }
};
