<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000122_create_customer_payments_table
//
//  Customer Payment — Sales Cycle, module 3. Real cash received from
//  a customer. The first Accounting-Engine transaction table in this
//  build that touches a bank account directly — Rule 31 (Facility
//  Transaction Bank Account Linkage) applies in full: bank_account_id
//  is mandatory and lives directly on this row, never inferred.
//
//  ON-ACCOUNT RECEIPTS (confirmed direction, flag if wrong): a
//  payment can post for the real cash immediately even before every
//  peso/pound of it is allocated to a specific invoice — matches
//  real practice (a customer sometimes pays a round number before
//  you've finished deciding which invoices it covers). amount and
//  allocated_amount are tracked separately; unallocated_amount
//  (amount - allocated_amount) is what's still available to match.
//  The GL posting itself (Dr Bank / Cr AR) happens in full at post
//  time regardless of allocation state — allocation only decides
//  which invoice(s)' amount_outstanding drop, not whether the cash
//  is real. An unallocated payment still correctly reduces total AR
//  exposure even before it's matched to a specific invoice — it just
//  doesn't reduce any one invoice's outstanding balance until
//  allocated.
//
//  payment_method is informational only for now (bank_transfer /
//  cheque / cash) — cheque's eventual tie-in to Notes Receivable
//  (safe → under collection → cleared, per the Opening Balances
//  design) is explicitly out of scope for this build; a cheque
//  received today posts the same as a transfer, immediately, no
//  staged clearing states yet.
//
//  Same currency/functional dual-amount pattern as Customer Invoice.
//  FX gain/loss between invoice posting and payment (when paid in a
//  different currency, or the rate moved) is deliberately out of
//  scope here, same reasoning as Supplier Bill's header — a future,
//  separate concern once realized FX gain/loss handling is designed
//  properly, not bolted on as an afterthought.
//
//  Status lifecycle: draft → posted → (cancelled, draft only).
//  Payment code format: CP/YYYY/MM/00001 — immutable after creation.
//
//  @layer    Migration
//  @depends  companies, partners, bank_accounts, currencies,
//            journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('payment_code', 30)->unique();

            $table->foreignId('partner_id')->constrained('partners'); // customer
            $table->foreignId('bank_account_id')->constrained('bank_accounts'); // Rule 31 — mandatory, carried directly

            $table->date('payment_date');
            $table->enum('payment_method', ['bank_transfer', 'cheque', 'cash'])->default('bank_transfer');
            $table->string('reference_number', 100)->nullable(); // transfer ref / cheque number — free text

            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('exchange_rate', 15, 6)->default(1);

            $table->decimal('amount', 15, 2)->default(0);             // payment currency — full cash received
            $table->decimal('amount_functional', 15, 2)->default(0);  // = the Bank debit posted to the GL

            $table->decimal('allocated_amount', 15, 2)->default(0);   // functional — running total from allocations
            $table->decimal('unallocated_amount', 15, 2)->default(0); // functional — amount_functional - allocated_amount

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
            $table->index(['company_id', 'bank_account_id']);
            $table->index(['company_id', 'payment_date']);
            $table->index(['company_id', 'status', 'unallocated_amount'], 'cp_company_status_unallocated_idx'); // "payments still available to match" lookup
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payments');
    }
};
