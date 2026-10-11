<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000143_alter_lg_issuance_history_add_undo_tracking
//
//  Part of the August 2026 correction-everywhere fix (confirmed with
//  Mahmoud: "there must be a way for correction" — nothing in an ERP
//  should be a permanent dead end except a genuinely empty draft).
//
//  Renewal / Decrease / Cancel / Liquidate each get their own "Undo"
//  action (LgIssuanceService::undoRenewal()/undoDecrease()/
//  undoCancel()/undoLiquidate()), separate from "Set to Draft" on the
//  original issuance (which only applies while the LG is still
//  untouched — see 000142's posting_status). Only the MOST RECENT
//  non-undone row can be undone — enforced in the service by reading
//  undone_at, not here.
//
//  journal_entry_id: the real journal entry this specific event
//  posted (renewal fee / cash cover release on cancel / claim on
//  liquidate) — reversed via JournalEntryService::reverse() when this
//  event is undone. NULL for decrease (memo-only, no cash movement —
//  see lg_issuance_movements header) and for any event that genuinely
//  posted nothing (e.g. a Time-Deposit-collateralized cancel).
//
//  old_current_amount: current_amount immediately BEFORE this event,
//  captured explicitly rather than derived, so undoDecrease() can
//  restore it with certainty instead of reverse-computing it from
//  decrease_amount (which would silently drift if this row is ever
//  undone out of order or edited).
//
//  undone_at / undone_by: when set, this row is no longer "the most
//  recent active event" and is excluded from being undone again or
//  from blocking Set to Draft a second time.
//
//  @layer    Migration
//  @depends  lg_issuance_history, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lg_issuance_history', function (Blueprint $table) {
            $table->unsignedBigInteger('journal_entry_id')->nullable()->after('shortfall_account_id');
            $table->decimal('old_current_amount', 15, 2)->nullable()->after('balance_after_event');
            $table->timestamp('undone_at')->nullable()->after('created_by');
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete()->after('undone_at');

            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->index(['company_id', 'lg_issuance_id', 'undone_at']);
        });
    }

    public function down(): void
    {
        Schema::table('lg_issuance_history', function (Blueprint $table) {
            $table->dropForeign(['journal_entry_id']);
            $table->dropForeign(['undone_by']);
            $table->dropIndex(['company_id', 'lg_issuance_id', 'undone_at']);
            $table->dropColumn(['journal_entry_id', 'old_current_amount', 'undone_at', 'undone_by']);
        });
    }
};
