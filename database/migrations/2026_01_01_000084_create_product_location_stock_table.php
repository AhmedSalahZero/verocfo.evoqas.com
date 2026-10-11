<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000084_create_product_location_stock_table
//
//  Inventory Engine — Layer 1. One row per (company_id, product_id,
//  inventory_location_id) — QUANTITY ONLY, deliberately no cost
//  column on this table.
//
//  Confirmed with Mahmoud: WAC is one company-wide figure per
//  product (see product_stock_balances) — a location never has its
//  own separate cost for the same product. This table exists purely
//  to answer "how much of this product is physically sitting in
//  this specific warehouse/site right now" — an operational,
//  quantity-only question, kept structurally separate from the
//  costing question so the two can never be confused or drift out
//  of sync with each other.
//
//  sum(product_location_stock.quantity_on_hand) for a given product
//  across all its locations must always equal
//  product_stock_balances.quantity_on_hand for that product — both
//  maintained together by StockLedgerService on every movement.
//
//  @layer    Migration
//  @depends  companies, products, inventory_locations
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_location_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->constrained('inventory_locations')->cascadeOnDelete();

            $table->decimal('quantity_on_hand', 15, 4)->default(0);

            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'inventory_location_id'], 'pls_company_product_location');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_location_stock');
    }
};
