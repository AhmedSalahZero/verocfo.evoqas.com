<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000172_create_other_collections_table
//
//  Other Collection — money received from anyone who ISN'T a regular
//  customer: a Shareholder or Employee repaying a loan/advance, a
//  Sister Company settling an intercompany balance, or a Supplier
//  refunding money the company overpaid them. See
//  VeroCFO_Other_Collection_Design_v1_0.md (confirmed with Mahmoud,
//  August 2026).
//
//  RELATIONSHIP TO CUSTOMER COLLECTION — this table deliberately
//  mirrors customer_payments' MONEY-IN side (money_type,
//  destination_type/bank/facility, safe_account_id, receipt_number,
//  cheque handling) column for column, since "where did the money
//  land" is identical logic regardless of who sent it. What's
//  different, and why this is its own table rather than a variant of
//  customer_payments, is the credit side: Customer Collection always
//  resolves the credit side automatically to AR (133xxx) or 235
//  Advances from Customers. Other Collection has no such single
//  answer — a Shareholder repayment credits 230, an Employee
//  repayment credits 140, a Supplier refund credits 144, and so on —
//  so the credit account is picked directly by the user from the
//  Chart of Accounts, not resolved by the system. credit_account_id
//  below IS that credit side.
//
//  NO SETTLEMENT / ALLOCATION CONCEPT — unlike Customer Collection,
//  there are no open invoices to match this money against. The
//  posted journal entry is always exactly two lines:
//    Dr [destination]        amount_functional
//    Cr credit_account_id    amount_functional
//  which is why this table carries no allocated_amount/
//  unallocated_amount pair.
//
//  Status lifecycle — same as Customer Collection (Customer
//  Collection Design v1.1/v1.2): posts IMMEDIATELY on save, no
//  draft-then-post two-step. 'status' stays for schema consistency
//  and to support "Set to Draft" (the reopen-and-re-post action,
//  identical in spirit to CustomerPaymentService::setToDraft()).
//
//  @layer    Migration
//  @depends  companies, partners, chart_of_accounts, currencies,
//            bank_accounts, credit_facilities, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('other_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('collection_code', 30)->unique();

            $table->foreignId('partner_id')->constrained('partners');
            // Denormalized copy of which type the partner was picked AS at
            // record time (a partner can hold several types at once —
            // e.g. both 'supplier' and 'shareholder' — this freezes which
            // one this specific collection was recorded under, for
            // reporting and for re-deriving the credit-account suggestion
            // on edit).
            $table->enum('partner_type', ['shareholder', 'employee', 'sister_company', 'supplier', 'other']);

            $table->date('collection_date');
            $table->enum('money_type', ['bank_deposit', 'incoming_transfer', 'cash_in_safe', 'cheque'])
                ->default('bank_deposit');
            $table->string('reference_number', 100)->nullable();

            // Polymorphic destination — same shape as customer_payments'
            // destination_* trio (migration 000125).
            $table->enum('destination_type', ['bank_account', 'credit_facility'])->nullable();
            $table->foreignId('destination_bank_account_id')->nullable()
                ->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('destination_credit_facility_id')->nullable()
                ->constrained('credit_facilities')->nullOnDelete();

            // Cash in Safe
            $table->foreignId('safe_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('receipt_number', 100)->nullable();

            // The credit side — picked manually by the user (see header).
            // Nullable only because a Cheque collection has no credit
            // account YET at receipt time in the exact same sense a
            // Customer Collection cheque has no AR credit at receipt —
            // wait: unlike Customer Collection, Other Collection's credit
            // account is never system-resolved, so it IS required even
            // for money_type = cheque (the cheque itself sits in Notes
            // Receivable as the debit; credit_account_id is still what
            // the cheque is FOR). Enforced in OtherCollectionRequest, not
            // the schema, matching this app's established pattern
            // (nullable column, required-ness enforced in the Form
            // Request) — see e.g. safe_account_id above.
            $table->foreignId('credit_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();

            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('amount', 15, 2)->default(0);            // collection currency
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
            $table->index(['company_id', 'collection_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('other_collections');
    }
};
