<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  create_job_order_stock_balances_table
//
//  Manufacturing Variance Closing Design v1.0, Section 6 — Job Order
//  output gets its own valuation segment, never blended into the
//  Product's ordinary company-wide WAC (ProductStockBalance). One row
//  per (company_id, job_order_id, product_id) — mirrors
//  ProductStockBalance's shape exactly, but scoped to a single Job
//  Order instead of pooled company-wide.
//
//  Same "derived cache, maintained exclusively by StockLedgerService"
//  rule as ProductStockBalance — nothing else is allowed to write to
//  this table directly (piece 2 of Section 6's build order teaches
//  StockLedgerService to do so; this migration only creates the
//  table, no behavior changes yet).
//
//  Confirmed with Mahmoud (SAP "Individual Valuation" / Oracle
//  Project Manufacturing precedent): every unit a Job Order produces
//  is genuinely different from another job's output sharing the same
//  Product code, so it is never averaged together with anything else
//  — not the Product's general stock, not another Job Order's output.
//
//  @layer    Migration
//  @depends  companies, job_orders, products
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_stock_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('job_order_id')->constrained('job_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();

            // Same shape as ProductStockBalance — quantity + this
            // job's own unit/total cost. Never called "wac_" here,
            // deliberately: this isn't an average pooled across
            // receipts the way Product WAC is: a Job Order normally
            // receives its Finished Goods ONCE, at completion, at its
            // own calculated Standard Cost (Section 5). The column
            // names avoid implying pooled averaging that doesn't
            // happen in the normal case.
            $table->decimal('quantity_on_hand', 15, 4)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_value', 15, 2)->default(0);

            $table->timestamps();

            $table->unique(['company_id', 'job_order_id', 'product_id'], 'job_order_stock_balances_unique');
            $table->index(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_stock_balances');
    }
};
