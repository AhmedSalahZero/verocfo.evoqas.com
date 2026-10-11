<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000197_create_supplier_debit_notes_table
//
//  Supplier Debit Note — Purchase Cycle. The purchase-side mirror of
//  Credit Note (migration 000120): DECREASES Accounts Payable on an
//  already-posted Supplier Bill (goods returned to the supplier, an
//  overcharge claim, etc.). Mandatory supplier_bill_id — same
//  no-free-floating-note principle as every other note in this
//  project (confirmed with Mahmoud, same session).
//
//  Naming follows what THIS company calls the document, not what the
//  supplier calls theirs (confirmed with Mahmoud): a Supplier Debit
//  Note is what we issue to the supplier. `supplier_credit_note_reference`
//  is an optional free-text field for the supplier's own Credit Note
//  number once they confirm it — purely for the user's own paper-trail
//  matching, never validated or looked up by the system.
//
//  On post: Dr Accounts Payable / Cr [whichever account the original
//  bill line posted to] — resolved per-line from the referenced
//  supplier_bill_line, not re-derived, since a Supplier Bill line can
//  be one of three shapes (goods / fresh expense / accrual settle —
//  see migration 000107's header) each with a different contra
//  account. SupplierDebitNoteService reads that resolution off the
//  line it's reversing, mirroring how CreditNoteService reverses the
//  exact revenue account each invoice line originally used.
//
//  Same currency/functional dual-amount pattern as the bill it debits
//  — inherits currency and exchange_rate from that bill.
//
//  Status lifecycle: draft → posted → (cancelled, draft only).
//  Debit Note code format: SDN/YYYY/MM/00001 — immutable after
//  creation.
//
//  @layer    Migration
//  @depends  companies, supplier_bills, partners, currencies,
//            journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_debit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('debit_note_code', 30)->unique();

            $table->foreignId('supplier_bill_id')->constrained('supplier_bills'); // mandatory — see header
            $table->foreignId('partner_id')->constrained('partners'); // supplier — copied from bill, kept for query convenience

            $table->date('debit_date');
            $table->string('debit_reason', 500)->nullable();

            // Optional paper-trail cross-reference to the supplier's own
            // Credit Note — free text, never validated. See header.
            $table->string('supplier_credit_note_reference', 100)->nullable();

            // Inherited from the bill being debited — see header
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('exchange_rate', 15, 6)->default(1);

            $table->decimal('subtotal_amount', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('debit_value', 15, 2)->default(0);

            $table->decimal('subtotal_amount_functional', 15, 2)->default(0);
            $table->decimal('vat_amount_functional', 15, 2)->default(0);
            $table->decimal('debit_value_functional', 15, 2)->default(0); // = the AP debit posted to the GL

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
            $table->index(['company_id', 'supplier_bill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_debit_notes');
    }
};
