<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000137_create_supplier_payments_table
//
//  Supplier Payment (August 2026) — the payable-side mirror of
//  Customer Collection, confirmed by Mahmoud to follow the exact same
//  design, built directly in its final shape rather than repeating
//  Customer Collection's own create-then-redesign migration history.
//
//  Every account role is reversed from Customer Collection, never
//  the account picker mechanics themselves:
//    Customer Collection: Dr [destination] / Cr AR (or 235 Advances)
//    Supplier Payment:     Dr AP (or 144 Advances) / Cr [source]
//
//  money_type has only THREE values here (confirmed by Mahmoud) —
//  Cash Payment / Outgoing Transfer / Payable Cheque — not four.
//  Paying money out doesn't have Customer Collection's Bank-Deposit-
//  vs-Incoming-Transfer distinction (that split exists because
//  *receiving* has two genuinely different real-world scenarios;
//  paying out is just "sent from one of our accounts," one type).
//
//  source_type/source_bank_account_id/source_credit_facility_id is
//  the mirror of destination_*, renamed since money is now leaving
//  one of our accounts rather than arriving into one. Confirmed
//  DIFFERENT from Customer Collection's cheque flow: a Payable
//  Cheque's source account is fixed HERE, at issue time — we already
//  know which of our own accounts it draws from when we write it.
//  A Receivable cheque's eventual destination is genuinely unknown
//  at receipt (decided later, at Send for Collection) — that
//  asymmetry is real, not a design inconsistency.
//
//  payment_type (settlement/down_payment) and the down-payment tags
//  mirror collection_type exactly, tagging to Project + Purchase
//  Order (the payable-side equivalent of Project + Sales Order).
//
//  Status lifecycle: draft → posted → (cancelled, draft only) —
//  identical semantics to Customer Collection, including the same
//  Reopen-to-Draft pattern (draft is only ever reached by reopening
//  a posted payment, never a real "save for later" starting state).
//
//  @layer    Migration
//  @depends  companies, partners, currencies, bank_accounts,
//            credit_facilities, chart_of_accounts, projects,
//            purchase_orders, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('payment_code', 30)->unique();

            $table->foreignId('partner_id')->constrained('partners'); // supplier

            $table->enum('payment_type', ['settlement', 'down_payment'])->default('settlement');
            $table->foreignId('down_payment_project_id')->nullable()
                ->constrained('projects')->nullOnDelete();
            $table->foreignId('down_payment_purchase_order_id')->nullable()
                ->constrained('purchase_orders')->nullOnDelete();

            $table->date('payment_date');

            $table->enum('money_type', ['cash_payment', 'outgoing_transfer', 'payable_cheque'])
                ->default('outgoing_transfer');
            $table->string('reference_number', 100)->nullable();

            // Dual currency — same shape as customer_payments
            $table->foreignId('pay_currency_id')->constrained('currencies');
            $table->foreignId('bill_currency_id')->nullable()
                ->constrained('currencies')->nullOnDelete();
            $table->decimal('bill_to_pay_exchange_rate', 15, 6)->nullable();
            $table->decimal('exchange_rate', 15, 6)->default(1); // pay currency → functional

            $table->decimal('amount', 15, 2)->default(0);             // pay currency
            $table->decimal('amount_functional', 15, 2)->default(0);  // = the AP debit posted to the GL

            $table->decimal('allocated_amount', 15, 2)->default(0);
            $table->decimal('unallocated_amount', 15, 2)->default(0);

            // Source — Current Account or Overdraft Facility. Used for
            // Outgoing Transfer AND Payable Cheque (fixed at issue time
            // for the latter — see header).
            $table->enum('source_type', ['bank_account', 'credit_facility'])->nullable();
            $table->foreignId('source_bank_account_id')->nullable()
                ->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('source_credit_facility_id')->nullable()
                ->constrained('credit_facilities')->nullOnDelete();

            // Cash Payment
            $table->foreignId('safe_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('receipt_number', 100)->nullable();

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
            $table->index(['company_id', 'money_type']);
            $table->index(['company_id', 'payment_type']);
            $table->index(['company_id', 'payment_date']);
            $table->index(['company_id', 'down_payment_project_id'], 'sp_company_dp_project_idx');
            $table->index(['company_id', 'status', 'unallocated_amount'], 'sp_company_status_unallocated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
