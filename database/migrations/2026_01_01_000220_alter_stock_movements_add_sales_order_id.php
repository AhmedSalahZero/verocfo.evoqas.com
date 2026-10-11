<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000220_alter_stock_movements_add_sales_order_id
//
//  Closes a confirmed gap in the dual-valuation model (Project
//  Module Structure v1.1, Section 6.4): a Goods Receipt Note line
//  can be tagged to a standalone Sales Order (no parent Project) via
//  goods_receipt_note_lines.sales_order_id — but that tag was never
//  passed through to stock_movements, and actual_unit_cost was only
//  ever populated when project_id was set. That meant standalone-SO
//  jobs (per the same document, the MORE common case for trading and
//  service companies) got zero actual-cost tracking at all — no way
//  to ever build a Project Inventory Statement or P&L for them.
//
//  This column mirrors project_id exactly: nullable, independent,
//  populated by GoodsReceiptNoteService::post() (see that service's
//  updated header) whenever a receipt line carries either a project
//  OR a standalone sales order — actual_unit_cost now follows the
//  same "either one" rule instead of "project only".
//
//  @layer    Migration
//  @depends  stock_movements, sales_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('sales_order_id')
                  ->nullable()
                  ->after('project_id')
                  ->constrained('sales_orders')
                  ->nullOnDelete();

            $table->index(['company_id', 'sales_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropIndex(['company_id', 'sales_order_id']);
            $table->dropColumn('sales_order_id');
        });
    }
};
