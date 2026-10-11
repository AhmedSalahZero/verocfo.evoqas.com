<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000279_alter_goods_receipt_note_lines_add_stock_movement_id
//
//  INV-006 fix (confirmed with Mahmoud, 2026-09) — reverseReceipt()
//  can only safely refuse an unsafe reversal if it knows exactly
//  which stock_movements row the receipt being undone actually was,
//  so it can check whether anything has been issued/consumed for
//  that product since. Job Order, Kit Assembly, and Production Order
//  already hold that row directly when they reverse a receipt; a
//  Goods Receipt Note line did not previously remember it at all.
//
//  Nullable and purely additive — existing GRN lines posted before
//  this migration simply have no value here. StockLedgerService
//  treats a missing reference as "cannot verify this is safe" and
//  refuses the reversal outright rather than guessing, which is the
//  safe failure direction for financial data (see that class's
//  reverseReceipt() header for the full reasoning).
//
//  @layer    Migration
//  @depends  goods_receipt_note_lines, stock_movements
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_note_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('stock_movement_id')->nullable()->after('quantity');
            $table->foreign('stock_movement_id')->references('id')->on('stock_movements')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_note_lines', function (Blueprint $table) {
            $table->dropForeign(['stock_movement_id']);
            $table->dropColumn('stock_movement_id');
        });
    }
};
