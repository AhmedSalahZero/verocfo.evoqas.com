<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000283_backfill_goods_receipt_note_lines_stock_movement_id
//
//  Companion to migration 000279, which added the column but left it
//  NULL on every line that already existed. reverseReceipt() refuses
//  outright when original_movement_id is absent (INV-006), so on any
//  database that was already in use, migration 000279 alone silently
//  removes the ability to reopen EVERY goods receipt posted before
//  it ran — the guard is correct, but it has no evidence to work with.
//
//  The evidence does exist: every receipt movement carries
//  reference_type = 'goods_receipt_note' and reference_id = the GRN
//  it came from, so each line can be matched to its own movement row
//  by (GRN, product). Only unambiguous matches are backfilled — a
//  line whose product appears more than once on the same GRN is left
//  NULL, which falls back to the same refusal as before rather than
//  guessing at which movement was which.
//
//  @layer    Migration
//  @depends  goods_receipt_note_lines, stock_movements
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE goods_receipt_note_lines l
            JOIN (
                SELECT m.reference_id AS grn_id,
                       m.product_id,
                       MIN(m.id)      AS movement_id,
                       COUNT(*)       AS matches
                  FROM stock_movements m
                 WHERE m.reference_type = 'goods_receipt_note'
                   AND m.movement_type  = 'receipt'
                 GROUP BY m.reference_id, m.product_id
            ) m ON m.grn_id     = l.goods_receipt_note_id
               AND m.product_id = l.product_id
               AND m.matches    = 1
               SET l.stock_movement_id = m.movement_id
             WHERE l.stock_movement_id IS NULL
        SQL);
    }

    public function down(): void
    {
        // Intentionally not reversed: the column is dropped by 000279's
        // own down(), and clearing correct links serves no purpose.
    }
};
