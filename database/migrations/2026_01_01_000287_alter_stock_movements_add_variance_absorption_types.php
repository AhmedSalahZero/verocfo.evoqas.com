<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000287_alter_stock_movements_add_variance_absorption_types
//
//  RPT-003 fix (Audit, 8 Sep 2026). Adds 'variance_absorption_in' and
//  'variance_absorption_out' as new movement_type values.
//
//  VarianceClosingService::adjustProductBalance() revalues a
//  product's Finished Goods value at month-end (absorbing its share
//  of manufacturing variance) by editing product_stock_balances
//  directly — quantity_on_hand never changes, only wac_total_value /
//  wac_unit_cost do. Because that edit never wrote a stock_movements
//  row, StockLedgerService::categoryValueStatement() (which sums
//  stock_movements) permanently disagreed with the Stock Balance
//  screen (which reads product_stock_balances directly) by exactly
//  the absorbed amount.
//
//  This is a well-established pattern in mainstream ERPs, not a
//  Vero-specific invention: SAP has a dedicated transaction for it
//  (MR21/MR22 — "an accounting transaction, not material transaction
//  ... will not change the quantity"), and Oracle Cost Management
//  documents the same thing as its own transaction class ("only the
//  monetary amount is known but not an associated quantity ... zero
//  quantity"). Both keep it as a real, dated transaction their own
//  reconciliation reports pick up — which is exactly what these two
//  new types let Vero CFO do too.
//
//  Two directions, not one, for the same reason adjustment_in/
//  adjustment_out are split: categoryMovementSums() groups by
//  StockMovement::INBOUND_TYPES / OUTBOUND_TYPES to build a
//  beginning/debit/credit/ending statement — a value INCREASE must
//  count as inbound (debit), a value DECREASE as outbound (credit),
//  same shape as every other movement type already does.
//
//  MySQL enums require the whole column definition to be reissued —
//  repeats migration 000250's list plus these two new values.
//
//  @layer    Migration
//  @depends  stock_movements
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE stock_movements MODIFY COLUMN movement_type ENUM(
            'receipt',
            'issue',
            'transfer_out',
            'transfer_in',
            'adjustment_in',
            'adjustment_out',
            'production_consumption',
            'production_receipt',
            'job_consumption',
            'job_receipt',
            'opening_balance',
            'receipt_reversal',
            'issue_reversal',
            'kit_consumption',
            'kit_receipt',
            'variance_absorption_in',
            'variance_absorption_out'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE stock_movements MODIFY COLUMN movement_type ENUM(
            'receipt',
            'issue',
            'transfer_out',
            'transfer_in',
            'adjustment_in',
            'adjustment_out',
            'production_consumption',
            'production_receipt',
            'job_consumption',
            'job_receipt',
            'opening_balance',
            'receipt_reversal',
            'issue_reversal',
            'kit_consumption',
            'kit_receipt'
        ) NOT NULL");
    }
};
