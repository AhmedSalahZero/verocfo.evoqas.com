<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000155_alter_stock_movements_add_issue_reversal_type
//
//  Adds 'issue_reversal' as a new movement_type value, needed by
//  DeliveryNoteService::setToDraft() (Set to Draft feature, mirrors
//  GoodsReceiptNoteService::setToDraft()). Kept as its own distinct
//  type rather than reusing 'adjustment_in' — an adjustment means
//  "we physically counted more than we thought," an issue reversal
//  means "this specific earlier delivery is being undone." Different
//  meaning, so it should read differently in the stock ledger.
//
//  MySQL enums require the whole column definition to be reissued —
//  repeats migration 000152's list plus this one new value.
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
            'issue_reversal'
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
            'receipt_reversal'
        ) NOT NULL");
    }
};
