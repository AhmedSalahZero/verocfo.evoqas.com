<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000177_create_cash_expense_payments_table
//
//  Cash Expense Payment (August 2026) — a direct expense payment that
//  does NOT go through the Supplier Bill / Accounts Payable cycle
//  (no PO, no GRN, no Bill to match). Confirmed with Mahmoud: for
//  one-off / low-value expenses where setting up a full Supplier
//  Bill isn't worth it — courier fees, small repairs, government
//  fees, etc.
//
//  Closest structural sibling is OtherPayment (migration 000174), NOT
//  Supplier Payment — same "no settlement/allocation, exactly one
//  posting event" shape. Two genuine differences from OtherPayment:
//
//    1. The debit side is always an EXPENSE account (Chart of
//       Accounts, class = expenses, postable), not a manually-picked
//       Balance Sheet account — see expense_account_id.
//    2. VAT and Withholding Tax are supported directly on this
//       screen, mirroring exactly how Supplier Bill computes them
//       (see SupplierBillService::addLineVat() / the WHT block in
//       postBillJournalEntry()):
//         VAT Input    → COA 137  (recoverable, company default rate
//                        from company_tax_settings.vat_rate, editable)
//         WHT Payable  → COA 226002 (same account Supplier Bill uses
//                        for Credit WHT — no company-default rate
//                        exists for this one anywhere in the system;
//                        always typed in fresh, same as a bill line)
//
//  partner_id is OPTIONAL (confirmed with Mahmoud) — many of these
//  payments have no registered Partner at all (e.g. a government
//  fee). When absent, payee_description carries a free-text label
//  for the journal narration only; it is never posted anywhere.
//
//  cost_center_id is OPTIONAL at the schema level — only actually
//  required at posting time when the chosen expense_account_id has
//  chart_of_accounts.requires_cost_center = true (enforced in
//  CashExpensePaymentService, same rule as everywhere else that
//  posts to a cost-center-flagged account).
//
//  THE POSTED JOURNAL ENTRY (2 to 4 lines depending on tax):
//    Dr  expense_account_id        subtotal_functional
//    Dr  137 VAT Input             vat_amount_functional        [if VAT > 0]
//        Cr  226002 WHT Payable        withholding_tax_amount_functional [if WHT > 0]
//        Cr  [source: Safe / Bank / Facility / 222001 Notes Payable]
//                                   net_amount_functional
//  net_amount = subtotal + vat_amount - withholding_tax_amount —
//  the actual cash that leaves. Same arithmetic as
//  SupplierBillService::postBillJournalEntry() (apAmount = bill_value
//  - wht), just with "source" standing in for AP.
//
//  MONEY_TYPE — same THREE values as Supplier Payment / Other
//  Payment (Cash Payment / Outgoing Transfer / Payable Cheque).
//  A Payable Cheque here shares the exact same cheques_payable table
//  as Supplier Payment and Other Payment cheques — see migration
//  000178, which widens cheques_payable a second time for this new
//  source, same pattern as 000175 did for Other Payment.
//
//  NO SETTLEMENT / ALLOCATION CONCEPT — nothing to match this
//  payment against, same reasoning as Other Payment.
//
//  Status lifecycle — posts IMMEDIATELY on save, same posture as
//  every other payment module. 'status' supports "Set to Draft"
//  (reopen, edit, re-post) exactly like OtherPaymentService does.
//
//  @layer    Migration
//  @depends  companies, partners, chart_of_accounts, cost_centers,
//            currencies, bank_accounts, credit_facilities,
//            journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_expense_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('payment_code', 30)->unique();

            // Payee — optional (see header). payee_description is a
            // free-text label used only when partner_id is null.
            $table->foreignId('partner_id')->nullable()
                ->constrained('partners')->nullOnDelete();
            $table->string('payee_description', 255)->nullable();

            // The debit side — always an Expense account.
            $table->foreignId('expense_account_id')
                ->constrained('chart_of_accounts');
            $table->foreignId('cost_center_id')->nullable()
                ->constrained('cost_centers')->nullOnDelete();

            $table->date('payment_date');
            $table->enum('money_type', ['cash_payment', 'outgoing_transfer', 'payable_cheque'])
                ->default('outgoing_transfer');
            $table->string('reference_number', 100)->nullable();

            // Source — Current Account or Overdraft Facility. Used for
            // Outgoing Transfer AND Payable Cheque (fixed at issue
            // time for the latter — same asymmetry as every other
            // payable cheque in this system).
            $table->enum('source_type', ['bank_account', 'credit_facility'])->nullable();
            $table->foreignId('source_bank_account_id')->nullable()
                ->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('source_credit_facility_id')->nullable()
                ->constrained('credit_facilities')->nullOnDelete();

            // Cash Payment
            $table->foreignId('safe_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('receipt_number', 100)->nullable();

            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('exchange_rate', 15, 6)->default(1);

            // ── Amount + tax breakdown (pay currency) ──────────────
            $table->decimal('subtotal', 15, 2)->default(0);

            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);

            $table->decimal('withholding_tax_rate', 5, 2)->default(0);
            $table->decimal('withholding_tax_amount', 15, 2)->default(0);

            // = subtotal + vat_amount - withholding_tax_amount — the
            // actual cash that leaves (pay currency).
            $table->decimal('net_amount', 15, 2)->default(0);

            // ── Same breakdown, functional currency (posted values) ─
            $table->decimal('subtotal_functional', 15, 2)->default(0);
            $table->decimal('vat_amount_functional', 15, 2)->default(0);
            $table->decimal('withholding_tax_amount_functional', 15, 2)->default(0);
            $table->decimal('net_amount_functional', 15, 2)->default(0);

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
            $table->index(['company_id', 'expense_account_id']);
            $table->index(['company_id', 'money_type']);
            $table->index(['company_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_expense_payments');
    }
};
