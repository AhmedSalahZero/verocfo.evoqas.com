<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000083_create_product_stock_balances_table
//
//  Inventory Engine — Layer 1. One row per (company_id, product_id).
//  This is the single, company-wide, authoritative current WAC and
//  on-hand quantity for that product — confirmed with Mahmoud: WAC
//  is one number per product per company, never fragmented by
//  location or by project.
//
//  This table is a fast-read cache/snapshot, recalculated by
//  StockLedgerService on every receipt per the formula in
//  Application Scope §5.3:
//      New WAC = (current stock value + new receipt value)
//                ÷ (current stock qty + new receipt qty)
//  It can always be rebuilt from stock_movements — that table is
//  the source of truth; this one exists purely so every screen that
//  needs "what is this product's current cost and quantity" doesn't
//  have to aggregate the full ledger on every read.
//
//  Per-location quantity (e.g. "how much is in Warehouse A") lives
//  in the separate product_location_stock table — deliberately
//  split out so this table stays exactly what its name says: the
//  company-wide balance, nothing else.
//
//  @layer    Migration
//  @depends  companies, products
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->decimal('quantity_on_hand', 15, 4)->default(0);
            $table->decimal('wac_unit_cost', 15, 4)->default(0);
            $table->decimal('wac_total_value', 15, 2)->default(0);

            $table->timestamps();

            $table->unique(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_stock_balances');
    }
};
