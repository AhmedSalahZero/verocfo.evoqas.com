<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000120_create_credit_notes_table
//
//  Credit Note — Sales Cycle, module 2. The correction mechanism for
//  a posted Customer Invoice (a posted invoice is never edited or
//  reversed directly — same immutability principle as Supplier Bill/
//  GRN). customer_invoice_id is mandatory — unlike some accounting
//  systems, Vero CFO has no free-floating credit note; every credit
//  traces back to exactly one real invoice, which is what makes
//  "how much of this invoice is still valid AR" a reliable question.
//
//  Reasons a credit note exists: goods returned, pricing correction,
//  goodwill adjustment, service not fully delivered — reason is
//  free text (credit_reason), not an enum, since Mahmoud's business
//  types vary too widely to enumerate usefully up front.
//
//  On post: reverses the invoice's revenue and VAT (Dr revenue + Dr
//  VAT Output / Cr AR) for the credited amount. Cannot exceed what's
//  still open on that invoice — CustomerInvoiceService tracks
//  amount_outstanding precisely so this check is a simple comparison,
//  not a recomputation from history each time.
//
//  Same currency/functional dual-amount pattern as the invoice it
//  credits — inherits the invoice's currency and exchange_rate
//  rather than letting the user pick a different one, since a credit
//  note is a partial reversal of a specific transaction, not a new
//  one.
//
//  Status lifecycle: draft → posted → (cancelled, draft only).
//  Credit Note code format: CN/YYYY/MM/00001 — immutable after
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
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('credit_note_code', 30)->unique();

            $table->foreignId('customer_invoice_id')->constrained('customer_invoices'); // mandatory — see header
            $table->foreignId('partner_id')->constrained('partners'); // customer — copied from invoice, kept for query convenience

            $table->date('credit_date');
            $table->string('credit_reason', 500)->nullable();

            // Inherited from the invoice being credited — see header
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('exchange_rate', 15, 6)->default(1);

            $table->decimal('subtotal_amount', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('credit_value', 15, 2)->default(0);

            $table->decimal('subtotal_amount_functional', 15, 2)->default(0);
            $table->decimal('vat_amount_functional', 15, 2)->default(0);
            $table->decimal('credit_value_functional', 15, 2)->default(0); // = the AR credit posted to the GL

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
        Schema::dropIfExists('credit_notes');
    }
};
