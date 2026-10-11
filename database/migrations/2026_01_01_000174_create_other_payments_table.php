<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000174_create_other_payments_table
//
//  Other Payment — the payable-side mirror of Other Collection. Money
//  paid to anyone who ISN'T a regular supplier being settled against
//  a bill: a Shareholder or Employee being reimbursed/repaid, a
//  Sister Company settling an intercompany balance, or a Customer
//  being refunded money they overpaid. See
//  VeroCFO_Other_Payment_Design_v1_0.md (confirmed with Mahmoud,
//  August 2026).
//
//  Every account role is reversed from Other Collection, never the
//  account-picker mechanics themselves:
//    Other Collection: Dr [destination] / Cr credit_account_id
//    Other Payment:     Dr debit_account_id / Cr [source]
//
//  MONEY_TYPE — only THREE values, same reasoning as Supplier
//  Payment vs Customer Collection: paying out doesn't have a
//  Bank-Deposit-vs-Incoming-Transfer-style split, it's just "sent
//  from one of our accounts." Mirrors supplier_payments column for
//  column (money_type, source_type/source_bank_account_id/
//  source_credit_facility_id, safe_account_id, receipt_number, the
//  cheque handling with source fixed at issue time).
//
//  debit_account_id is this table's one genuinely different piece —
//  the mirror of Other Collection's credit_account_id. No AP exists
//  for these partners, so the user picks the debit account directly
//  from the Chart of Accounts (frontend suggests a default per
//  Partner Type — see OtherPayment::DEFAULT_DEBIT_ACCOUNT_CODE).
//
//  NO SETTLEMENT / ALLOCATION CONCEPT — same reasoning as Other
//  Collection: nothing to match this money against. Always exactly
//  two journal lines:
//    Dr debit_account_id     amount_functional
//    Cr [source]              amount_functional
//
//  Status lifecycle — posts IMMEDIATELY on save, same posture as
//  Supplier Payment / Other Collection. 'status' supports "Set to
//  Draft" (reopen + re-post).
//
//  @layer    Migration
//  @depends  companies, partners, chart_of_accounts, currencies,
//            bank_accounts, credit_facilities, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('payment_code', 30)->unique();

            $table->foreignId('partner_id')->constrained('partners');
            // Denormalized copy of which type the partner was picked AS at
            // record time — see other_collections.partner_type for the
            // same reasoning (a partner can hold several types at once).
            $table->enum('partner_type', ['shareholder', 'employee', 'sister_company', 'customer', 'other']);

            $table->date('payment_date');
            $table->enum('money_type', ['cash_payment', 'outgoing_transfer', 'payable_cheque'])
                ->default('outgoing_transfer');
            $table->string('reference_number', 100)->nullable();

            // Source — Current Account or Overdraft Facility. Used for
            // Outgoing Transfer AND Payable Cheque (fixed at issue time
            // for the latter — same asymmetry vs. a receivable cheque as
            // Supplier Payment/ChequePayable, see that migration's header).
            $table->enum('source_type', ['bank_account', 'credit_facility'])->nullable();
            $table->foreignId('source_bank_account_id')->nullable()
                ->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('source_credit_facility_id')->nullable()
                ->constrained('credit_facilities')->nullOnDelete();

            // Cash Payment
            $table->foreignId('safe_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('receipt_number', 100)->nullable();

            // The debit side — picked manually by the user (see header).
            // Required even for money_type = payable_cheque — enforced in
            // OtherPaymentRequest, not the schema, matching this app's
            // established nullable-column pattern.
            $table->foreignId('debit_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();

            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('amount', 15, 2)->default(0);            // payment currency
            $table->decimal('amount_functional', 15, 2)->default(0); // = both JE line amounts

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            $table->text('internal_notes')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'partner_type']);
            $table->index(['company_id', 'money_type']);
            $table->index(['company_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_payments');
    }
};
