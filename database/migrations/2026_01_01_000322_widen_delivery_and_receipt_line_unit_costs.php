<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000322_widen_delivery_and_receipt_line_unit_costs   (audit L-8)
//
//  delivery_note_lines.wac_unit_cost and goods_receipt_note_lines.unit_cost
//  were stored with 2 decimals while the stock ledger (stock_movements,
//  product_stock_balances, returns) keeps 4. On cheap, high-volume items
//  (0.0125 per piece) the 2-decimal copy rounded the cost to 0.01 — every
//  place that re-used that copy (reopening a delivery, receiving a
//  purchase at the typed cost) drifted from the ledger.
//
//  Both columns are widened to 4 decimals (no data is lost — widening
//  only). Existing delivery lines get their unit cost recomputed from the
//  exact booked value (wac_total_value / quantity). Existing receipt
//  lines keep what was stored (the original typed cost is not recoverable).
//
//  @layer    Migration
//  @depends  delivery_note_lines, goods_receipt_note_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE delivery_note_lines MODIFY wac_unit_cost DECIMAL(15,4) NOT NULL');
        DB::statement('ALTER TABLE goods_receipt_note_lines MODIFY unit_cost DECIMAL(15,4) NOT NULL');

        DB::statement('UPDATE delivery_note_lines
                          SET wac_unit_cost = ROUND(wac_total_value / quantity, 4)
                        WHERE quantity > 0 AND wac_total_value IS NOT NULL AND wac_total_value > 0');
    }

    public function down(): void
    {
        // Narrowing would round real costs — leave the wider columns in place.
    }
};
