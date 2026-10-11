<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000147_create_lc_issuance_history_table
//
//  Append-only lifecycle log for one LetterOfCreditIssuance — two
//  event types, both correctable via their own Undo action, most-
//  recent-first (same "peel back one layer at a time" chain as
//  lg_issuance_history — see LgIssuanceService class header for the
//  full rationale, ported unchanged):
//
//    - 'mark_paid'   → the ONE-TIME event that pays the supplier
//                       (bank or self) and, for a bank-financed LC,
//                       opens the bank_debt_outstanding balance.
//                       "Undo" = CashVero's backToRunningStatus(),
//                       ported as undoMarkAsPaid() — only reachable
//                       once every 'settlement' row is undone first.
//    - 'settlement'  → one repayment installment to the bank
//                       (bank-financed only) — principal + interest,
//                       interest entered per-settlement since it's
//                       only knowable then (confirmed with Mahmoud,
//                       not at issuance). Ported from CashVero's
//                       LcSettlementInternalMoneyTransfer + its
//                       reset() action — reset() already proved the
//                       "wipe back to fully outstanding" concept;
//                       here it becomes a real per-row Undo (reverses
//                       that one settlement's journal entry) rather
//                       than a single wipe-everything action, for the
//                       same most-recent-first correction discipline
//                       used everywhere else in VeroCFO.
//
//  journal_entry_id: the real entry this event posted — reversed via
//  JournalEntryService::reverse() when undone. NULL only if the event
//  had nothing to post (e.g. a bank-financed Mark as Paid with zero
//  cash cover to release and a facility-liability leg that still
//  needs posting — in practice this is always set for both event
//  types here, unlike LG's decrease which is genuinely memo-only).
//
//  @layer    Migration
//  @depends  lc_issuance_history, journal_entries, bank_accounts,
//            chart_of_accounts, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_issuance_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('lc_issuance_id')->constrained('letter_of_credit_issuances')->cascadeOnDelete();

            $table->enum('event_type', ['mark_paid', 'settlement']);
            $table->date('event_date');

            // mark_paid
            $table->foreignId('supplier_bill_id')->nullable()->constrained('supplier_bills')->nullOnDelete();
            $table->decimal('amount_paid_to_supplier', 15, 2)->nullable();
            $table->decimal('bank_debt_opened', 15, 2)->nullable(); // bank-financed only

            // settlement
            $table->decimal('principal_amount', 15, 2)->nullable();
            $table->decimal('interest_amount', 15, 2)->nullable();
            $table->foreignId('interest_expense_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('settlement_from_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->decimal('bank_debt_after_event', 15, 2)->nullable();
            $table->decimal('old_bank_debt_outstanding', 15, 2)->nullable(); // exact pre-event snapshot, same "explicit, never re-derived" rule as lg_issuance_history.old_current_amount

            $table->unsignedBigInteger('journal_entry_id')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');

            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->index(['company_id', 'lc_issuance_id', 'undone_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_issuance_history');
    }
};
