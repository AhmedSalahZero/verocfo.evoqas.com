<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000100_create_goods_receipt_notes_table
//
//  Goods Receipt Note — Accounting Engine Phase 2. The document that
//  physically receives goods into stock and posts the GRNI clearing
//  entry. GRN's predecessor is Purchase Order (purchase_order_id);
//  a PO can be received across multiple partial GRNs (confirmed by
//  Mahmoud) — receipt progress rolls up onto the PO line's
//  received_quantity and the PO header's receipt_status.
//
//  `purchase_order_id` is NULLABLE — governed by the company-level
//  `companies.grn_policy` preference (migration 000097):
//    'po_required'  → controller/request enforce this column is set
//    'allow_ad_hoc' → a GRN may be created with no parent PO at all
//  This column intentionally does not enforce NOT NULL at the DB
//  level — the policy is a per-company business rule, not a schema
//  constraint, and is validated in GoodsReceiptNoteService/Request.
//
//  `partner_id` is always required — the supplier goods were
//  physically received from — whether or not a PO exists.
//
//  Currency simplification (confirmed as a stated assumption, not
//  silently assumed): GRN line unit costs are captured in FUNCTIONAL
//  currency, same simplification the Inventory Engine already uses
//  (stock_movements/product_stock_balances have no currency
//  dimension — see StockLedgerService header). When the parent PO is
//  in a foreign currency, `exchange_rate` here defaults from the
//  PO's own rate and is used to convert PO unit prices into the
//  functional-currency unit_cost stored on each GRN line.
//
//  Status lifecycle: draft → posted → (cancelled, draft only).
//  Posting is the one irreversible action — it moves physical stock
//  (StockLedgerService::receive), posts the GL entry
//  (journal_entry_id), and updates the parent PO. A posted GRN is
//  never edited or hard-deleted — a future "Goods Return" document
//  is the correct mechanism to reverse a posted receipt and is out
//  of scope for this migration.
//
//  GRN code format: GRN/YYYY/MM/00001 — immutable after creation,
//  same generator pattern as PO/SO codes.
//
//  @layer    Migration
//  @depends  companies, purchase_orders, partners, inventory_locations,
//            journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('grn_code', 30)->unique();

            $table->foreignId('purchase_order_id')->nullable()
                ->constrained('purchase_orders')->nullOnDelete(); // nullable — see header (grn_policy)
            $table->foreignId('partner_id')->constrained('partners'); // supplier — always required

            $table->foreignId('inventory_location_id')->constrained('inventory_locations');

            $table->date('received_date');
            $table->decimal('exchange_rate', 15, 6)->default(1); // see header — functional currency conversion

            $table->decimal('grn_value', 15, 2)->default(0); // auto-summed from lines, functional currency

            $table->enum('status', ['draft', 'posted', 'cancelled'])->default('draft');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete(); // set only on posting

            $table->string('supplier_reference', 100)->nullable(); // supplier's own delivery note / invoice ref
            $table->text('internal_notes')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'purchase_order_id']);
            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'received_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_notes');
    }
};
