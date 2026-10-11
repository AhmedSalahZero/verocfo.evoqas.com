<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000035_create_product_prices_table
//
//  Date-effective selling and purchase prices per product.
//  Append-only — no UPDATE or DELETE ever.
//  System always uses: most recent price where
//  effective_date <= transaction date.
//
//  Price resolution:
//    product_id + price_type + uom_id + currency_id
//    + effective_date <= tx_date
//    ORDER BY effective_date DESC LIMIT 1
//
//  @layer    Migration
//  @depends  products, units_of_measure, currencies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            // selling_price | purchase_price
            $table->string('price_type', 20);

            $table->foreignId('uom_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();

            // bcmath enforced — 4 decimal places
            $table->decimal('price', 15, 4);

            $table->date('effective_date');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // No updated_at — append-only
            $table->timestamp('created_at')->nullable();

            // ── Indexes ───────────────────────────────────────────
            // Named explicitly — the auto-generated name for this
            // combination of table + 4 columns + "_index" exceeds
            // MySQL's 64-character identifier limit (1059 error).
            $table->index(['company_id', 'product_id', 'price_type', 'effective_date'],
                          'product_prices_listing_idx');
            $table->index(['company_id', 'product_id', 'price_type', 'uom_id', 'currency_id', 'effective_date'],
                          'product_prices_resolution_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_prices');
    }
};