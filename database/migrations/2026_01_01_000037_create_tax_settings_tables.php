<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000037_create_tax_settings_tables
//
//  Two tables:
//
//  company_tax_settings:
//    One row per company. Stores the general/default VAT and
//    Debit WHT rates that apply to all transactions unless
//    overridden at category or product level.
//
//  tax_overrides:
//    Repeater rows — specific rates per category or product.
//    Either category_id OR product_id must be set (not both).
//    Product-level override takes priority over category-level.
//    Category-level takes priority over company default.
//
//  Account mapping (fixed COA codes — no user selection):
//    VAT Output  → 226xxx  (Current Liabilities)
//    VAT Input   → 137     (Current Assets)
//    Debit WHT   → 142     (Current Assets)
//    Credit WHT  → entered directly on supplier bill (Stream 4)
//
//  @layer    Migration
//  @depends  companies, product_categories, products
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // ── Company Default Tax Rates ─────────────────────────────
        Schema::create('company_tax_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->unique()
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // General VAT rate — e.g. 14.00 for Egypt standard rate
            $table->decimal('vat_rate', 5, 2)->default(0);

            // General Debit WHT rate — e.g. 5.00
            $table->decimal('debit_wht_rate', 5, 2)->default(0);

            $table->timestamps();
        });

        // ── Product / Category Tax Overrides ─────────────────────
        Schema::create('tax_overrides', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // Either category_id OR product_id — not both
            $table->foreignId('category_id')
                  ->nullable()
                  ->constrained('product_categories')
                  ->cascadeOnDelete();

            $table->foreignId('product_id')
                  ->nullable()
                  ->constrained('products')
                  ->cascadeOnDelete();

            // null = inherit from parent level (do not override)
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('debit_wht_rate', 5, 2)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────
            $table->index(['company_id', 'category_id']);
            $table->index(['company_id', 'product_id']);

            // Prevent duplicate overrides for same category/product
            $table->unique(['company_id', 'category_id'], 'tax_override_category_unique');
            $table->unique(['company_id', 'product_id'],  'tax_override_product_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_overrides');
        Schema::dropIfExists('company_tax_settings');
    }
};
