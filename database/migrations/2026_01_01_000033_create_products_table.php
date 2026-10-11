<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000033_create_products_table
//
//  Main product / service item master.
//
//  Account assignment — fully automatic, zero user input:
//    revenue_account_id  → auto 411 (Sales Local). Changeable
//                          at transaction line. Never per-SKU.
//    cogs_account_id     → auto from nature (511/512/513)
//    inventory_account_id→ auto from usage_type (135001/135002/135003)
//    Consumable expense  → set at Category level, not here
//
//  has_stock is derived from usage_type + nature:
//    tradable / manufactured / raw_material / consumable → true
//    service → false
//
//  Auto-code: P-00001 per company (sequential).
//
//  @layer    Migration
//  @depends  companies, product_categories, units_of_measure
//            chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // ── Identity ──────────────────────────────────────────
            $table->string('code', 30);
            $table->string('name_en', 255);
            $table->string('name_ar', 255)->nullable();
            $table->string('barcode', 50)->nullable();
            $table->text('description')->nullable();

            // ── Classification ────────────────────────────────────
            $table->foreignId('category_id')
                  ->constrained('product_categories')
                  ->restrictOnDelete();

            // usage_types: JSON array — ['saleable','raw_material'] etc.
            // saleable | raw_material | consumable (multi-select)
            $table->json('usage_types');

            // tradable | manufactured | service | null
            // null for raw_material and consumable (always physical)
            $table->string('nature', 20)->nullable();

            // Derived: true for all physical items, false for service
            $table->boolean('has_stock')->default(true);

            // ── UOM ───────────────────────────────────────────────
            // Primary storage UOM — inventory valuation always in this UOM
            $table->foreignId('primary_uom_id')
                  ->constrained('units_of_measure')
                  ->restrictOnDelete();

            // ── Auto-assigned accounts (system-resolved, read-only) ──
            // Revenue: auto 411 Sales Local — override at tx line
            $table->foreignId('revenue_account_id')
                  ->nullable()
                  ->constrained('chart_of_accounts')
                  ->nullOnDelete();

            // COGS: auto from nature 511/512/513
            $table->foreignId('cogs_account_id')
                  ->nullable()
                  ->constrained('chart_of_accounts')
                  ->nullOnDelete();

            // Inventory: auto from type 135001/135002/135003
            $table->foreignId('inventory_account_id')
                  ->nullable()
                  ->constrained('chart_of_accounts')
                  ->nullOnDelete();

            // ── Physical item fields ──────────────────────────────
            $table->decimal('weight', 10, 4)->nullable();
            $table->foreignId('weight_uom_id')
                  ->nullable()
                  ->constrained('units_of_measure')
                  ->nullOnDelete();

            // Reorder alerts — inventory companies only
            $table->decimal('reorder_level', 15, 4)->nullable();
            $table->decimal('reorder_qty', 15, 4)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'category_id']);
            $table->index(['company_id', 'has_stock']);
            $table->index(['company_id', 'nature']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
