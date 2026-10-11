<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000194_create_customer_debit_notes_table
//
//  Customer Debit Note — Sales Cycle, mirror of Credit Note (migration
//  000120) but the opposite direction: it INCREASES what the customer
//  owes on an already-posted invoice (undercharge found after posting,
//  an extra fee applies after the fact, etc.), instead of decreasing
//  it. Same non-negotiable rule as Credit Note — customer_invoice_id
//  is mandatory, there is no free-floating debit note (confirmed with
//  Mahmoud, same session as this migration).
//
//  On post: Dr Accounts Receivable / Cr Revenue (+ Cr VAT Output if
//  applicable) — i.e. the same shape as an ordinary invoice line, just
//  raised after the fact against an existing invoice rather than a
//  Sales Order. Increments customer_invoices.amount_debited (migration
//  000196) rather than reducing amount_credited/amount_paid — this
//  document only ever makes an invoice's outstanding balance bigger.
//
//  Same currency/functional dual-amount pattern as the invoice it
//  debits — inherits currency and exchange_rate from that invoice,
//  same reasoning as Credit Note (a correction of one specific
//  transaction, not a new independent one).
//
//  Status lifecycle: draft → posted → (cancelled, draft only).
//  Debit Note code format: CDN/YYYY/MM/00001 — immutable after
//  creation.
//
//  @layer    Migration
//  @depends  companies, customer_invoices, partners, currencies,
//            journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_debit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('debit_note_code', 30)->unique();

            $table->foreignId('customer_invoice_id')->constrained('customer_invoices'); // mandatory — see header
            $table->foreignId('partner_id')->constrained('partners'); // customer — copied from invoice, kept for query convenience

            $table->date('debit_date');
            $table->string('debit_reason', 500)->nullable();

            // Inherited from the invoice being debited — see header
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('exchange_rate', 15, 6)->default(1);

            $table->decimal('subtotal_amount', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('debit_value', 15, 2)->default(0);

            $table->decimal('subtotal_amount_functional', 15, 2)->default(0);
            $table->decimal('vat_amount_functional', 15, 2)->default(0);
            $table->decimal('debit_value_functional', 15, 2)->default(0); // = the AR debit posted to the GL

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
            $table->index(['company_id', 'customer_invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_debit_notes');
    }
};
