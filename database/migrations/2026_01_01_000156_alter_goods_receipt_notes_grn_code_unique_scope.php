<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000156_alter_goods_receipt_notes_grn_code_unique_scope
//
//  Same bug, same fix as 2026_01_01_000150 (Purchase Orders) and
//  2026_01_01_000151 (Sales Orders), just never applied here: grn_code
//  was unique across the ENTIRE system instead of unique per company.
//  Two different companies both posting their first Goods Receipt
//  Note in the same month both correctly compute GRN/YYYY/MM/00001
//  for themselves — the old global-unique rule wrongly rejected the
//  second company's GRN as a "duplicate."
//
//  Paired with GoodsReceiptNoteService now generating grn_code's
//  YYYY/MM from received_date (the GRN's real business date) instead
//  of the row's created_at — received_date already existed and
//  already drives the journal entry date, so this reuses it rather
//  than adding a new field the way Purchase Order needed a brand new
//  issuance_date column.
//
//  @layer    Migration
//  @depends  goods_receipt_notes
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->dropUnique('goods_receipt_notes_grn_code_unique');
            $table->unique(['company_id', 'grn_code']);
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_notes', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'grn_code']);
            $table->unique('grn_code');
        });
    }
};
