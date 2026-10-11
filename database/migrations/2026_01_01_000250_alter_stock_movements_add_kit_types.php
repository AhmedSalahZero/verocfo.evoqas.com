<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000250_alter_stock_movements_add_kit_types
//
//  Assembly/Kit feature (VeroCFO_Manufacturing_Flexibility_Design_
//  v1_0.md, Section 3) — adds 'kit_consumption' and 'kit_receipt' as
//  new movement_type values. Kept as their own distinct types rather
//  than reusing 'production_consumption'/'production_receipt' —
//  those specifically mean "went through a Production Order, with
//  standard-cost snapshot and WIP posting." A Kit Assembly is a
//  simpler, always-actual-cost, no-WIP event — different meaning, so
//  it must read differently in the stock ledger and be filterable
//  separately in reports. Same reasoning migration 000155 used for
//  keeping issue_reversal distinct from adjustment_in.
//
//  MySQL enums require the whole column definition to be reissued —
//  repeats migration 000155's list plus these two new values.
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
            'kit_receipt'
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
            'issue_reversal'
        ) NOT NULL");
    }
};
