<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000139_create_cheques_payable_table
//
//  Supplier Payment (August 2026) — one row per cheque WE write to a
//  supplier, one-to-one with the supplier_payments row that issued it
//  (money_type = 'payable_cheque'). Deliberately simpler than
//  cheques_receivable's four-state machine — confirmed with Mahmoud,
//  scope is explicitly Mark as Paid + Reset only, nothing more:
//
//    STATE MACHINE:  issued → paid
//
//  No 'sent_for_collection' equivalent — that step exists on the
//  receivable side because a customer's cheque's eventual bank
//  destination is genuinely unknown until later. A payable cheque's
//  source account is already fixed at issue time (it lives on the
//  parent supplier_payments row, via source_bank_account_id /
//  source_credit_facility_id — not duplicated here), so there's
//  nothing left to decide before it can be marked paid.
//
//  No 'bounced'/'rejected' state either — Mahmoud asked specifically
//  for Mark as Paid + Reset, not a bounce flow. If a payable cheque
//  needs that handling later, it's a deliberate follow-up, not
//  something silently missing from this table's design.
//
//  'issued' — the state the row starts in. Its own posting already
//  happened via the parent supplier_payments row's journal entry
//  (Dr AP or 144 Advances / Cr 222001 Notes Payable — Suppliers) —
//  nothing further posts here until Mark as Paid.
//
//  'paid' posts Dr 222001 Notes Payable — Suppliers / Cr [the
//  source account already fixed on the parent payment] — this is
//  where real cash actually leaves; nothing moved at 'issued'.
//  Mirrors cheques_receivable's 'collected' state, Dr/Cr reversed.
//
//  Real-world rule (confirmed with Mahmoud, same as the receivable
//  side): cannot be marked paid before its own due_date — enforced
//  in ChequePayableService, not at the database level.
//
//  @layer    Migration
//  @depends  supplier_payments, partners, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheques_payable', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('supplier_payment_id')->unique()
                ->constrained('supplier_payments')->cascadeOnDelete(); // 1:1 — see header

            $table->foreignId('partner_id')->constrained('partners'); // supplier — denormalized, same pattern as cheques_receivable.partner_id

            $table->decimal('cheque_amount', 15, 2); // pay currency — mirrors the parent payment's own amount
            $table->string('cheque_number', 100);
            $table->date('due_date');

            $table->enum('status', ['issued', 'paid'])->default('issued');

            // Set only at 'paid'
            $table->foreignId('paid_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cheques_payable');
    }
};
