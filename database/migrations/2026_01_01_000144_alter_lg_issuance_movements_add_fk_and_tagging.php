<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000144_alter_lg_issuance_movements_add_fk_and_tagging
//
//  Three changes, all part of the August 2026 LG Issuance fix:
//
//  1. posted_journal_entry_id finally gets its real FK — the
//     original 000068 migration left it as a bare unsignedBigInteger
//     "FK added when journal_entries exists" (see that file's
//     header). journal_entries has existed since 000098; this closes
//     the loop now that LgIssuanceService actually posts through
//     JournalEntryService instead of only staging rows.
//
//  2. `status` enum gains two values:
//       - 'reversed'   → this movement's journal entry was reversed
//                         (Set to Draft, or an Undo action)
//       - 'memo_only'  → this movement never had a real postable
//                         leg on both sides (e.g. Time-Deposit-
//                         collateral cash cover, or an Advanced
//                         Payment LG decrease_adjustment) — it's
//                         informational, not something the sweep
//                         ever tries to post to the GL.
//     MySQL requires a raw MODIFY for enum changes (no doctrine/dbal
//     in this project) — existing rows are untouched, only the
//     allowed value set grows.
//
//  3. project_id / sales_order_id — cost-allocation tagging
//     (confirmed with Mahmoud: NOT an accounting posting, purely for
//     future Project P&L / Project Cash Flow to sum LG cost lines
//     per contract, the same way Materials/Services costs are
//     already tagged on their own transaction lines — see Project
//     Module Structure doc §6/§7). Copied straight from the parent
//     LgIssuance's contract_reference_project_id / purchase_order_so_id
//     at the moment each movement is created, so a report never has
//     to join back through lg_issuances to find out which contract a
//     cost belongs to. Always NULL for Bid Bond (no Project/SO to
//     tag — confirmed, Bid Bond is the one LG type with neither).
//
//  @layer    Migration
//  @depends  lg_issuance_movements, journal_entries, projects, sales_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE lg_issuance_movements MODIFY status ENUM('pending_gl_posting', 'posted', 'reversed', 'memo_only') NOT NULL DEFAULT 'pending_gl_posting'");

        Schema::table('lg_issuance_movements', function (Blueprint $table) {
            $table->foreign('posted_journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();

            $table->foreignId('project_id')->nullable()->after('lg_issuance_id')->constrained('projects')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->after('project_id')->constrained('sales_orders')->nullOnDelete();

            $table->index(['company_id', 'project_id']);
            $table->index(['company_id', 'sales_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('lg_issuance_movements', function (Blueprint $table) {
            $table->dropForeign(['posted_journal_entry_id']);
            $table->dropForeign(['project_id']);
            $table->dropForeign(['sales_order_id']);
            $table->dropIndex(['company_id', 'project_id']);
            $table->dropIndex(['company_id', 'sales_order_id']);
            $table->dropColumn(['project_id', 'sales_order_id']);
        });

        DB::statement("ALTER TABLE lg_issuance_movements MODIFY status ENUM('pending_gl_posting', 'posted') NOT NULL DEFAULT 'pending_gl_posting'");
    }
};
