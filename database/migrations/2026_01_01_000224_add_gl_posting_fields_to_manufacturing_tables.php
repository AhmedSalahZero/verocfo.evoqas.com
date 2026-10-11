<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000224_add_gl_posting_fields_to_manufacturing_tables
//
//  Manufacturing GL Posting fix (2026-09) — four small additions
//  needed so Production Order / Job Order can post real journal
//  entries and be reopened (Set to Draft) like every other financial
//  document in this app:
//
//  1. production_orders / job_orders — journal_entry_id, set by
//     complete() (Dr 135003 Finished Goods / Cr 135002 WIP). One
//     completion per order, so a single column is enough — mirrors
//     goods_receipt_notes.journal_entry_id.
//
//  2. production_order_cost_entries / job_order_cost_entries —
//     journal_entry_id (each labor/overhead entry posts its OWN
//     entry, Dr 135002 WIP / Cr 237 or 238) and cost_center_id
//     (2026-09, confirmed with Mahmoud: departmental/location
//     allocation on top of order-level and Overhead-Pool-level
//     detail — same optional field every other cost document in this
//     app already carries).
//
//  Material consumption (consumeMaterial()) does NOT get a new
//  column — it already writes a stock_movements row, and its journal
//  entry is looked up the same way GoodsReceiptNoteService's ledger
//  references already are (JournalEntry::where('source_type', ...)
//  ->where('source_id', $movement->id)), never a stored column on the
//  shared stock_movements table.
//
//  @layer    Migration
//  @depends  production_orders, job_orders, production_order_cost_entries,
//            job_order_cost_entries, journal_entries, cost_centers
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('actual_completion_date')
                ->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('actual_completion_date')
                ->constrained('journal_entries')->nullOnDelete();
        });

        Schema::table('production_order_cost_entries', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('actual_amount')
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->after('journal_entry_id')
                ->constrained('cost_centers')->nullOnDelete();
        });

        Schema::table('job_order_cost_entries', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')->nullable()->after('actual_amount')
                ->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('cost_center_id')->nullable()->after('journal_entry_id')
                ->constrained('cost_centers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });

        Schema::table('job_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });

        Schema::table('production_order_cost_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_center_id');
            $table->dropConstrainedForeignId('journal_entry_id');
        });

        Schema::table('job_order_cost_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_center_id');
            $table->dropConstrainedForeignId('journal_entry_id');
        });
    }
};
