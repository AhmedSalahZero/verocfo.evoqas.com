<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000104_alter_goods_receipt_note_lines_add_billed_quantity
//
//  Supplier Bill's real matching target for a "Goods" bill line is a
//  GRN line, not a PO line directly — a GRN can be ad-hoc (no PO at
//  all, per companies.grn_policy) and still needs to be billable.
//  `billed_quantity` is the exact mirror of purchase_order_lines'
//  own `received_quantity` column (migration 000074) — cumulative,
//  never replaced, incremented by every posted Bill line that
//  matches this GRN line. Whether or not a parent PO line exists,
//  this is the number that prevents billing more than was received.
//
//  Unlike GRN-vs-PO receiving, there is deliberately NO tolerance
//  band here — a GRN line can never be billed for more than it
//  physically received (received_quantity's own tolerance already
//  absorbed the PO-vs-actual variance at receipt time; billing
//  matches the GRN's own recorded quantity exactly).
//
//  @layer    Migration
//  @depends  goods_receipt_note_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_note_lines', function (Blueprint $table) {
            $table->decimal('billed_quantity', 15, 4)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_note_lines', function (Blueprint $table) {
            $table->dropColumn('billed_quantity');
        });
    }
};
