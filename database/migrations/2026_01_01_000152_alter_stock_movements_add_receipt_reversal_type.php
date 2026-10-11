<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000152_alter_stock_movements_add_receipt_reversal_type
//
//  Adds 'receipt_reversal' as a new movement_type value, needed by
//  GoodsReceiptNoteService::setToDraft() (Set to Draft feature).
//  Kept as its own distinct type rather than reusing
//  'adjustment_out' — an adjustment means "we physically counted
//  less than we thought," a receipt reversal means "this specific
//  earlier receipt is being undone." Different meaning, so it
//  should read differently in the stock ledger / reports.
//
//  MySQL enums require the whole column definition to be reissued
//  to add a value — this repeats the exact enum list from migration
//  000082 (create_stock_movements_table) plus the one new value.
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
            'receipt_reversal'
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
            'opening_balance'
        ) NOT NULL");
    }
};
