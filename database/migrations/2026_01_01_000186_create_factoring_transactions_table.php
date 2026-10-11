<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000186_create_factoring_transactions_table
//
//  Factoring Transactions — one row per invoice sold/advanced under
//  a Factoring Agreement. Ported from CashVero's FactoringTransaction
//  (see FactoringWithRecourseController / calculateAmounts()), adapted
//  for Vero CFO's real Accounts Receivable + Accounting Engine:
//
//  CashVero (treasury-only)         →  Vero CFO (ERP + accounting engine)
//  ─────────────────────────────────────────────────────────────────
//  factoring_contract_id             → factoring_agreement_id (already built)
//  customer_invoice_id (own AR)      → customer_invoice_id (real AR — CustomerInvoice)
//  financial_institution_id +        → bank_account_id (Vero CFO's own BankAccount,
//    account_type_id + account_number    already carries its own coa_account_id)
//  raw bank-statement rows           → journal_entry_id (real double-entry posting,
//                                        via JournalEntryService — see COA doc
//                                        Section 8.2 for the posting rule)
//  is_collected / is_rejected flags  → status enum (kept as CashVero's own
//                                        proven states — Collect/Reject actions
//                                        are a follow-up build, not in this
//                                        migration's initial controller)
//
//  invoice_amount / factoring_amount / received_amount etc. are all
//  stored in the company's FUNCTIONAL currency — Vero CFO posts every
//  journal entry in functional currency (see CustomerPaymentService),
//  so this transaction follows the same convention rather than
//  re-solving FX conversion here.
//
//  contract_interest_rate is a SNAPSHOT of the agreement's discount
//  rate (borrowing_rate + discount_margin_rate) at the moment of this
//  transaction — the agreement can be renewed later with a different
//  rate; this transaction's own historical rate must never drift.
//
//  @layer    Migration
//  @depends  factoring_agreements, customer_invoices, bank_accounts,
//            journal_entries, partners, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factoring_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('factoring_agreement_id');
            $table->unsignedBigInteger('customer_invoice_id');
            $table->unsignedBigInteger('partner_id'); // snapshot of the invoice's customer — convenience/filtering

            $table->date('factoring_date');

            // Snapshots — taken at disbursement time, never recalculated later
            $table->decimal('invoice_amount', 15, 2);       // invoice's functional amount at factoring time
            $table->date('invoice_due_date')->nullable();   // resolved from the invoice's payment term
            $table->decimal('factoring_percentage', 5, 2);
            $table->decimal('factoring_amount', 15, 2);
            $table->decimal('contract_interest_rate', 8, 4); // agreement's discount_rate, snapshotted
            $table->integer('diff_in_days')->default(0);
            $table->decimal('factoring_interest_amount', 15, 2)->default(0);
            $table->decimal('other_charges', 15, 2)->default(0);
            $table->decimal('received_amount', 15, 2);

            $table->unsignedBigInteger('bank_account_id');   // disbursement destination
            $table->unsignedBigInteger('journal_entry_id')->nullable(); // disbursement posting

            // Lifecycle — Collect/Reject actions are a follow-up build;
            // columns exist now so that build doesn't need another migration.
            $table->enum('status', ['pending', 'collected', 'rejected'])->default('pending');
            $table->date('collection_date')->nullable();
            $table->decimal('collection_difference_amount', 15, 2)->nullable();
            $table->unsignedBigInteger('collection_journal_entry_id')->nullable();
            $table->date('rejection_date')->nullable();
            $table->decimal('uncollected_invoice_charges', 15, 2)->nullable();
            $table->unsignedBigInteger('rejection_journal_entry_id')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('factoring_agreement_id', 'ftx_agreement_fk')
                  ->references('id')->on('factoring_agreements');
            $table->foreign('customer_invoice_id', 'ftx_invoice_fk')
                  ->references('id')->on('customer_invoices');
            $table->foreign('bank_account_id', 'ftx_bank_account_fk')
                  ->references('id')->on('bank_accounts');
            $table->foreign('journal_entry_id', 'ftx_journal_entry_fk')
                  ->references('id')->on('journal_entries')->nullOnDelete();

            $table->index(['company_id', 'factoring_agreement_id'], 'ftx_company_agreement_idx');
            $table->index(['company_id', 'customer_invoice_id'], 'ftx_company_invoice_idx');
            $table->index(['company_id', 'status'], 'ftx_company_status_idx');
        });
    }

    public function down(): void { Schema::dropIfExists('factoring_transactions'); }
};
