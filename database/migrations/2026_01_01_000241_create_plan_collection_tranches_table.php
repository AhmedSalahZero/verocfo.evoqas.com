<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000241_create_plan_collection_tranches_table
//
//  How a plan_product's sales revenue turns into cash — one row per
//  collection tranche, per market (local/export), per product.
//  e.g. "40% collected on delivery, 60% collected 60 days later" is
//  two rows. Deliberately a real table (matching the existing
//  payment_term_lines pattern already used elsewhere in this
//  codebase), not a JSON blob — a tranche is a small, bounded,
//  genuinely relational shape, not a financial amount.
//
//  Percentages across all tranches for the same (plan_product_id,
//  market) must sum to 100 — enforced in PlanService, not the schema.
//
//  @layer    Migration
//  @depends  plan_products
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_collection_tranches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_product_id')->constrained()->cascadeOnDelete();

            $table->enum('market', ['local', 'export']);
            $table->decimal('pct', 8, 4);
            $table->unsignedSmallInteger('days_after_sale')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'plan_product_id', 'market']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_collection_tranches');
    }
};
