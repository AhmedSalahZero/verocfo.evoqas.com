<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  alter_stock_movements_add_job_order
//
//  Manufacturing Variance Closing Design v1.0, Section 6, piece 2 —
//  tags a stock movement to the Job Order it belongs to, mirroring
//  project_id's exact shape (nullable, independent FK, own index).
//
//  Unlike project_id — which is a side reporting tag while WAC still
//  posts to the GL (Section 6.4) — job_order_id marks a movement that
//  bypasses the company-wide pooled balance entirely: when this
//  column is set on a receipt, StockLedgerService::receive() skips
//  updating product_stock_balances and JobOrderStockBalanceService
//  updates that job's own segment instead (see that service's
//  header). Physical quantity/location tracking is unaffected either
//  way — this only changes which COST balance absorbs the value.
//
//  @layer    Migration
//  @depends  stock_movements, job_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('job_order_id')->nullable()->after('project_id')
                ->constrained('job_orders')->nullOnDelete();

            $table->index(['company_id', 'job_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['job_order_id']);
            $table->dropIndex(['company_id', 'job_order_id']);
            $table->dropColumn('job_order_id');
        });
    }
};
