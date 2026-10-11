<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000106_create_supplier_bills_table
//
//  Supplier Bill — Accounting Engine Phase 2, module 3. The document
//  that says "the supplier's real invoice has arrived" — completes
//  the chain Purchase Order → Goods Receipt Note → Supplier Bill.
//
//  TWO BILL FLAVORS (confirmed by Mahmoud — both needed):
//    'goods'   → lines match specific Goods Receipt Note lines
//                (goods_receipt_note_line_id on the line, migration
//                000107), clears GRNI (233), creates AP (221).
//    'expense' → lines post to either (a) an expense/asset account
//                directly (fresh expense, never accrued) or
//                (b) an existing open Accrual (migration 000105),
//                clearing 228 Accrued Expenses instead. Never a GRN.
//
//  `bill_type` on the header exists purely to drive which fields the
//  create form shows (Goods Bill vs Expense Bill are genuinely
//  different user workflows) — it is NOT a hard schema constraint.
//  SupplierBillService validates that every line's shape matches the
//  declared type before posting, but the columns themselves allow
//  either shape on any bill, in case a mixed-line bill is ever
//  wanted later without another migration.
//
//  `purchase_order_id` is NULLABLE and purely a UI convenience filter
//  ("show me GRN lines still open for billing against this PO") — it
//  is NOT the authoritative match. The real match is per-line via
//  goods_receipt_note_line_id → purchase_order_line_id, because a
//  single Bill's lines could in principle span GRNs from more than
//  one PO, and a Goods Bill against an entirely ad-hoc (no-PO) GRN
//  is equally valid.
//
//  CURRENCY (confirmed by Mahmoud — this is the one document in the
//  Purchase Cycle chain that is NOT restricted to functional currency
//  only): a Bill can be raised in the supplier's actual invoice
//  currency. `exchange_rate` auto-fills from the most recent
//  `exchange_rates` row for that currency and is user-overridable.
//  All amounts are stored BOTH in bill currency (`bill_value` etc.)
//  AND their functional-currency equivalent (`*_functional` columns)
//  — the functional columns are what the Journal Entry actually
//  posts, since Accounts Payable (221) itself carries no per-currency
//  dual balance in this COA (unlike Cash/Banks, 131/132). FX
//  gain/loss between Bill posting and actual payment is deliberately
//  out of scope here — that belongs to Supplier Payment (Phase 3),
//  not built yet.
//
//  `supplier_invoice_number` + `supplier_invoice_date` capture the
//  supplier's own paper trail — used by SupplierBillService as a
//  soft duplicate-billing check (same supplier + same invoice number
//  already billed → DuplicateSupplierInvoiceException), not a hard
//  DB-level unique constraint (a supplier's own numbering is outside
//  this system's control and occasionally repeats across periods).
//
//  Status lifecycle: draft → posted → (cancelled, draft only).
//  Posting is the one irreversible action, mirroring GoodsReceiptNote
//  exactly — see GoodsReceiptNoteService header for the same
//  reasoning; a posted Bill is never edited or hard-deleted, a future
//  Debit Note is the correct mechanism to reverse part of one.
//
//  Bill code format: SB/YYYY/MM/00001 — immutable after creation.
//
//  @layer    Migration
//  @depends  companies, purchase_orders, partners, currencies,
//            payment_terms, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('bill_code', 30)->unique();
            $table->enum('bill_type', ['goods', 'expense']); // see header — UI-driving, not a hard schema wall

            $table->foreignId('partner_id')->constrained('partners'); // supplier — always required

            $table->foreignId('purchase_order_id')->nullable()
                ->constrained('purchase_orders')->nullOnDelete(); // convenience filter only — see header

            $table->string('supplier_invoice_number', 100)->nullable();
            $table->date('supplier_invoice_date')->nullable();
            $table->date('bill_date'); // recognition date — drives the journal entry date

            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('exchange_rate', 15, 6)->default(1);

            $table->decimal('subtotal_amount', 15, 2)->default(0);   // bill currency, excl VAT — summed from lines
            $table->decimal('vat_amount', 15, 2)->default(0);        // bill currency
            $table->decimal('bill_value', 15, 2)->default(0);        // bill currency, incl VAT — subtotal + vat

            $table->decimal('subtotal_amount_functional', 15, 2)->default(0); // functional currency equivalents
            $table->decimal('vat_amount_functional', 15, 2)->default(0);
            $table->decimal('bill_value_functional', 15, 2)->default(0); // = the AP credit posted to the GL

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
            $table->index(['company_id', 'purchase_order_id']);
            $table->index(['company_id', 'bill_date']);
            $table->index(['company_id', 'partner_id', 'supplier_invoice_number'], 'supplier_bills_partner_invoice_idx'); // duplicate-billing lookup — see header; explicitly named, default auto-generated name exceeds MySQL's 64-char identifier limit
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_bills');
    }
};
