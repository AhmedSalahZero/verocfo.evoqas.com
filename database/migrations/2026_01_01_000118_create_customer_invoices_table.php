<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000118_create_customer_invoices_table
//
//  Customer Invoice — Sales Cycle, module 1. The document that turns
//  a Sales Order (a commercial-only document, Rule 28) into a real
//  financial transaction: revenue recognized, Accounts Receivable
//  created. Mirrors Supplier Bill's design closely — same header
//  shape, same currency/functional dual-amount pattern, same
//  status lifecycle — just on the revenue side instead of expense.
//
//  Every invoice must link to a Sales Order (Rule 28.1 — enforced at
//  creation, not just at post) — sales_order_id is NOT nullable,
//  unlike Supplier Bill's purchase_order_id (which is an optional
//  convenience filter). There is no "ad-hoc, no-SO" invoice path in
//  Vero CFO — that's the entire point of Rule 28.
//
//  Rule 28.2 (invoice total across all posted invoices cannot exceed
//  so_value) and Rule 28.3 (SO must be active to receive invoices)
//  are enforced by CustomerInvoiceService::post(), using the
//  already-existing SOValueExceededException.
//
//  CURRENCY: same pattern as Supplier Bill — an invoice can be raised
//  in the customer's billing currency, exchange_rate auto-fills and
//  is user-overridable, and both currency-native and functional
//  amounts are stored (*_functional is what actually posts to the
//  GL, since AR (133) carries no per-currency dual balance).
//
//  amount_paid / amount_outstanding (functional currency) are new —
//  Supplier Bill has no equivalent because Supplier Payment doesn't
//  exist yet. Customer Invoice needs this from day one, since
//  Customer Payment (built alongside this in the same push) matches
//  against it directly — this is the number a payment allocation
//  reduces, and what tells the UI which invoices are still open.
//
//  customer_invoice_number is deliberately NOT a field here — unlike
//  a Supplier Bill (which records the supplier's own external
//  invoice number for duplicate-billing checks), a Customer Invoice
//  IS the original invoice number — invoice_code is the number,
//  there's no external document to cross-reference.
//
//  Status lifecycle: draft → posted → (cancelled, draft only).
//  Posting is irreversible, same reasoning as Supplier Bill/GRN — a
//  posted invoice is corrected via a Credit Note, never edited.
//
//  Invoice code format: CI/YYYY/MM/00001 — immutable after creation.
//
//  @layer    Migration
//  @depends  companies, sales_orders, partners, currencies,
//            payment_terms, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('invoice_code', 30)->unique();

            $table->foreignId('sales_order_id')->constrained('sales_orders'); // Rule 28.1 — mandatory, no ad-hoc path
            $table->foreignId('partner_id')->constrained('partners'); // customer — always required

            $table->date('invoice_date'); // recognition date — drives the journal entry date

            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('exchange_rate', 15, 6)->default(1);

            $table->decimal('subtotal_amount', 15, 2)->default(0);   // invoice currency, excl VAT — summed from lines
            $table->decimal('vat_amount', 15, 2)->default(0);        // invoice currency
            $table->decimal('invoice_value', 15, 2)->default(0);     // invoice currency, incl VAT — subtotal + vat

            $table->decimal('subtotal_amount_functional', 15, 2)->default(0); // functional currency equivalents
            $table->decimal('vat_amount_functional', 15, 2)->default(0);
            $table->decimal('invoice_value_functional', 15, 2)->default(0); // = the AR debit posted to the GL

            $table->decimal('amount_paid', 15, 2)->default(0);         // functional — running total from allocations
            $table->decimal('amount_outstanding', 15, 2)->default(0);  // functional — invoice_value_functional - amount_paid

            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete(); // set only on posting

            $table->text('internal_notes')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'sales_order_id']);
            $table->index(['company_id', 'invoice_date']);
            $table->index(['company_id', 'status', 'amount_outstanding'], 'ci_company_status_outstanding_idx'); // "open invoices for this customer" lookup — payment matching
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invoices');
    }
};
