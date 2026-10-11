<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000242_create_plan_sales_lines_table
//
//  One row per plan_product per month of the plan's duration —
//  price × volume, plus the local/export split for that month.
//  period_month is 0-based (0 = the plan's first month), so an Annual
//  Plan has exactly 12 rows per product and a 10-year Business Plan
//  has up to 120 — a real relational grid, matching this codebase's
//  own migration-discipline preference for proper columns over JSON
//  blobs (unlike CFO Tools' Year1/Year2-monthly-then-annual-blob
//  shape, which this deliberately does NOT copy — one consistent
//  monthly grain for the whole duration is simpler to query and to
//  keep in sync with plan_collection_tranches).
//
//  @layer    Migration
//  @depends  plan_products
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_sales_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_product_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('period_month');

            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('volume', 15, 4)->default(0);
            $table->decimal('local_pct', 8, 4)->default(100);
            $table->decimal('export_pct', 8, 4)->default(0);

            $table->timestamps();

            $table->unique(['plan_product_id', 'period_month'], 'plan_sales_lines_product_month_unique');
            $table->index(['company_id', 'plan_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_sales_lines');
    }
};
