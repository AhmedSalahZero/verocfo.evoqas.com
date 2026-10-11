<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000199_create_supplier_credit_notes_table
//
//  Supplier Credit Note — Purchase Cycle. INCREASES Accounts Payable
//  on an already-posted Supplier Bill (the supplier charges us more
//  after the fact — extra freight, a price correction, etc.).
//  Mandatory supplier_bill_id — same no-free-floating-note principle
//  as every other note in this project (confirmed with Mahmoud, same
//  session).
//
//  Naming follows what THIS company calls the document (confirmed
//  with Mahmoud): a Supplier Credit Note is what the supplier sends
//  US, increasing what we owe them — the opposite direction from
//  Supplier Debit Note (migration 000197), which we issue to them.
//  `supplier_debit_note_reference` is an optional free-text field for
//  the supplier's own Debit Note number that triggered this — purely
//  for the user's own paper-trail matching, never validated or looked
//  up by the system.
//
//  On post: Dr [whichever account the original bill line posted to]
//  / Cr Accounts Payable — resolved per-line from the referenced
//  supplier_bill_line, same reasoning as Supplier Debit Note
//  (migration 000197's header) just posted in the opposite direction.
//
//  Same currency/functional dual-amount pattern as the bill it
//  affects — inherits currency and exchange_rate from that bill.
//
//  Status lifecycle: draft → posted → (cancelled, draft only).
//  Credit Note code format: SCN/YYYY/MM/00001 — immutable after
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
        Schema::create('supplier_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('credit_note_code', 30)->unique();

            $table->foreignId('supplier_bill_id')->constrained('supplier_bills'); // mandatory — see header
            $table->foreignId('partner_id')->constrained('partners'); // supplier — copied from bill, kept for query convenience

            $table->date('credit_date');
            $table->string('credit_reason', 500)->nullable();

            // Optional paper-trail cross-reference to the supplier's own
            // Debit Note — free text, never validated. See header.
            $table->string('supplier_debit_note_reference', 100)->nullable();

            // Inherited from the bill being affected — see header
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('exchange_rate', 15, 6)->default(1);

            $table->decimal('subtotal_amount', 15, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('credit_value', 15, 2)->default(0);

            $table->decimal('subtotal_amount_functional', 15, 2)->default(0);
            $table->decimal('vat_amount_functional', 15, 2)->default(0);
            $table->decimal('credit_value_functional', 15, 2)->default(0); // = the AP credit posted to the GL

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
        Schema::dropIfExists('supplier_credit_notes');
    }
};
