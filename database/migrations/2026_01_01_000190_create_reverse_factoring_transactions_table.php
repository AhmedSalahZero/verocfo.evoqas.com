<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000190_create_reverse_factoring_transactions_table
//
//  Reverse Factoring — a Supplier Bill paid early by a factoring
//  company on the company's behalf (COA doc Section 8.3). Sibling of
//  factoring_transactions (Direct Factoring, AR side), but genuinely
//  needs its own table — the underlying document is a SupplierBill,
//  not a CustomerInvoice, and the partner is a supplier, not a
//  customer.
//
//  Confirmed with Mahmoud — disbursement (createReverseFactoring()):
//      Dr  Partner's AP account            — ap_clear_amount (Bill Amount × % − Interest − Charges)
//      Dr  557 Factoring Fees & Discount   — Interest (own line)
//      Dr  557 Factoring Fees & Discount   — Charges (own line)
//      Cr  Agreement's coa_account_id (224002) — reverse_factoring_amount (the gross advance)
//  No bank account moves at disbursement — the factoring company pays
//  the SUPPLIER directly, not us.
//
//  Two settlement paths, chosen at creation time (settlement_method):
//    'due_date' — liability stays open (status stays 'pending'),
//      settled later via a manual Settle action:
//        Dr 224002 Reverse Factoring Liabilities / Cr Bank
//      reverse_due_date defaults to Bill Date + the agreement's own
//      extended_payment_days ("Reverse Due Days"), overridable per
//      transaction (Mahmoud's explicit ask).
//    'cheques' — one or more payable cheques issued immediately,
//      settling the liability right away (status becomes 'settled'
//      at creation):
//        Dr 224002 Reverse Factoring Liabilities / Cr 222001 Notes
//        Payable — Suppliers (total across all cheques)
//      Each cheque becomes its own row in cheques_payable (see
//      migration 2026_01_01_000191), tracked and eventually marked
//      paid through the EXISTING Cheques Payable screen — no
//      parallel cheque-lifecycle system built here.
//
//  source_bank_account_id is the account that will fund EITHER
//  settlement path — the due-date bank payment, or (fixed at issue
//  time, same as SupplierPayment) whichever account each cheque
//  eventually clears from.
//
//  @layer    Migration
//  @depends  factoring_agreements, supplier_bills, partners,
//            bank_accounts, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reverse_factoring_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('factoring_agreement_id');
            $table->unsignedBigInteger('supplier_bill_id');
            $table->unsignedBigInteger('partner_id'); // snapshot of the bill's supplier

            $table->date('reverse_factoring_date');

            // Snapshots — taken at disbursement time, never recalculated later
            $table->decimal('bill_amount', 15, 2);
            $table->date('reverse_due_date');            // Bill Date + agreement's extended_payment_days, user-overridable
            $table->decimal('reverse_factoring_percentage', 5, 2);
            $table->decimal('reverse_factoring_amount', 15, 2); // gross advance — the liability booked
            $table->decimal('contract_interest_rate', 8, 4);    // agreement's discount_rate, snapshotted
            $table->integer('diff_in_days')->default(0);
            $table->decimal('reverse_factoring_interest_amount', 15, 2)->default(0);
            $table->decimal('reverse_factoring_charges', 15, 2)->default(0);
            $table->decimal('ap_clear_amount', 15, 2); // reverse_factoring_amount − interest − charges — what actually reaches the supplier

            $table->enum('settlement_method', ['due_date', 'cheques']);
            $table->unsignedBigInteger('source_bank_account_id'); // funds either settlement path — see header
            $table->unsignedBigInteger('journal_entry_id')->nullable(); // disbursement posting

            $table->enum('status', ['draft', 'pending', 'settled'])->default('pending');
            $table->date('settlement_date')->nullable();
            $table->unsignedBigInteger('settlement_journal_entry_id')->nullable();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->foreign('factoring_agreement_id', 'rftx_agreement_fk')
                  ->references('id')->on('factoring_agreements');
            $table->foreign('supplier_bill_id', 'rftx_bill_fk')
                  ->references('id')->on('supplier_bills');
            $table->foreign('source_bank_account_id', 'rftx_bank_account_fk')
                  ->references('id')->on('bank_accounts');
            $table->foreign('journal_entry_id', 'rftx_journal_entry_fk')
                  ->references('id')->on('journal_entries')->nullOnDelete();
            $table->foreign('settlement_journal_entry_id', 'rftx_settlement_je_fk')
                  ->references('id')->on('journal_entries')->nullOnDelete();

            $table->index(['company_id', 'factoring_agreement_id'], 'rftx_company_agreement_idx');
            $table->index(['company_id', 'supplier_bill_id'], 'rftx_company_bill_idx');
            $table->index(['company_id', 'status'], 'rftx_company_status_idx');
        });
    }

    public function down(): void { Schema::dropIfExists('reverse_factoring_transactions'); }
};
