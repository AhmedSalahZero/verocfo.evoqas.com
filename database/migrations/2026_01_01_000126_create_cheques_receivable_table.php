<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000126_create_cheques_receivable_table
//
//  Customer Collection redesign (July 2026) — see
//  VeroCFO_Customer_Collection_Design_v1_0.md Section 6, the
//  authoritative reference. One row per cheque received from a
//  customer, one-to-one with the customer_payments row that received
//  it (money_type = 'cheque'). Tracks the cheque's OWN lifecycle from
//  here onward — receiving the cheque already settled the customer's
//  AR (via the customer_payments row's own journal entry, Dr 134
//  Notes Receivable / Cr 133 AR), so this table only concerns itself
//  with what happens to the physical instrument next.
//
//  STATE MACHINE (see design doc 6.1):
//    received → sent_for_collection → collected
//                                    → rejected
//
//  'received' needs no extra fields beyond what's here — it's the
//  state the row starts in, set by the same action that created the
//  parent customer_payments row.
//
//  'sent_for_collection' — NO POSTING (confirmed by Mahmoud — status
//  change only). Records WHICH of our own accounts the cheque is
//  being sent to clear into. Same polymorphic Current-Account-or-
//  Overdraft-Facility choice as customer_payments' own destination_*
//  columns (migration 000125) — a cheque can clear into either.
//
//  'collected' posts Dr [that destination] / Cr 134 Notes Receivable
//  — this is where real cash (or OD paydown) actually lands; nothing
//  moved at 'received' or 'sent_for_collection'.
//
//  'rejected' posts Dr 133 AR / Cr 134 Notes Receivable instead — the
//  customer's AR simply reopens. Bank charges on a bounced cheque are
//  explicitly out of scope here (design doc Section 11) — deferred to
//  a future Expense Payment screen per Mahmoud.
//
//  receiving_safe_account_id is a TAG ONLY (confirmed by Mahmoud) —
//  it does not post anywhere and has no effect on any 131xxx balance.
//  It exists purely so a user can answer "how many cheques, worth how
//  much, are physically in this safe right now" as a list/count
//  query. Same 131xxx Chart-of-Accounts-sub-account source as
//  customer_payments.safe_account_id (migration 000125), reused here
//  for the same "which physical location" purpose, not because a
//  balance is being tracked.
//
//  @layer    Migration
//  @depends  customer_payments, partners, banks, chart_of_accounts,
//            bank_accounts, credit_facilities, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheques_receivable', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('customer_payment_id')->unique()
                ->constrained('customer_payments')->cascadeOnDelete(); // 1:1 — see header

            $table->foreignId('partner_id')->constrained('partners'); // customer — denormalized for convenient querying, same pattern as credit_notes.partner_id

            $table->foreignId('drawee_bank_id')->constrained('banks'); // the bank the cheque is drawn ON
            $table->decimal('cheque_amount', 15, 2); // receive currency — mirrors the parent payment's own amount
            $table->string('cheque_number', 100);
            $table->date('due_date');

            // Tag only — see header. NOT part of the destination_* posting pair.
            $table->foreignId('receiving_safe_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();

            $table->enum('status', ['received', 'sent_for_collection', 'collected', 'rejected'])->default('received');

            // Set only at 'sent_for_collection' — same polymorphic shape as
            // customer_payments.destination_* (migration 000125).
            $table->enum('collection_destination_type', ['bank_account', 'credit_facility'])->nullable();

            // Fix: the auto-generated constraint name for these two columns
            // ("cheques_receivable_collection_destination_bank_account_id_foreign",
            // 65 chars, and the credit_facility equivalent at 68) both exceed
            // MySQL's 64-character identifier limit — same class of issue
            // already flagged and fixed on supplier_bills' partner/invoice
            // index. Explicit short names given instead of relying on the
            // default.
            $table->foreignId('collection_destination_bank_account_id')->nullable();
            $table->foreign('collection_destination_bank_account_id', 'cheq_recv_coll_dest_bank_fk')
                ->references('id')->on('bank_accounts')->nullOnDelete();

            $table->foreignId('collection_destination_credit_facility_id')->nullable();
            $table->foreign('collection_destination_credit_facility_id', 'cheq_recv_coll_dest_facility_fk')
                ->references('id')->on('credit_facilities')->nullOnDelete();

            $table->timestamp('sent_for_collection_at')->nullable();

            // Set only at 'collected'
            $table->foreignId('collected_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('collected_at')->nullable();

            // Set only at 'rejected' — mutually exclusive with collected_*
            $table->foreignId('rejected_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'due_date']);
            $table->index(['company_id', 'drawee_bank_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheques_receivable');
    }
};
