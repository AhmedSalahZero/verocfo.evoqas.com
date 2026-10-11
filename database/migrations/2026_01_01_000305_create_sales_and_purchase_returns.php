<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000305 — Sales Returns and Purchase Returns
//
//  AUDIT H16 (Sept 2026). Goods could not be returned to a supplier or
//  accepted back from a customer — a posted Delivery Note or Goods
//  Receipt Note could only be reopened as a whole, and Supplier Debit
//  Notes refused goods lines. Stock and cost stayed wrong.
//
//  SALES RETURN (against a posted Delivery Note line) — confirmed with
//  Mahmoud: the goods come back into stock at their ORIGINAL delivery
//  cost, exactly reversing the cost of sales booked when they left.
//    • Part not yet invoiced → the accrued revenue (143) booked at
//      delivery is reversed pro rata.
//    • Part already invoiced → either a Customer Credit Note is raised
//      and posted automatically (invoiced_treatment = 'credit'), or
//      nothing is credited because the goods will be replaced
//      ('replace' — the replacement delivery then recognises no new
//      revenue, since the customer was already billed).
//
//  PURCHASE RETURN (against a posted Goods Receipt Note line) — the
//  goods leave stock at their ORIGINAL receipt cost:
//    • Part not yet billed → Dr 233 GRNI / Cr Inventory (the receipt
//      is reversed; that quantity can no longer be billed).
//    • Part already billed → Dr 233 GRNI / Cr Inventory, and a Supplier
//      Debit Note is raised and posted automatically: Dr AP /
//      Cr 233 GRNI (receipt cost) ± 516 Inventory Adjustment for any
//      bill-price difference / Cr VAT Input.
//
//  New counters so every document knows what has already come back:
//    delivery_note_lines.returned_quantity
//    goods_receipt_note_lines.returned_unbilled_quantity  (reduces billable)
//    goods_receipt_note_lines.returned_billed_quantity    (debit-noted)
//
//  Stock movements reuse existing types — 'issue_reversal' for goods
//  coming back from a customer, 'receipt_reversal' for goods going back
//  to a supplier — with reference_type sales_return_line /
//  purchase_return_line.
//
//  @layer    Migration
//  @depends  delivery_notes, delivery_note_lines, goods_receipt_notes,
//            goods_receipt_note_lines, sales_orders, sales_order_lines,
//            partners, products, inventory_locations, stock_movements,
//            journal_entries, users
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_note_lines', function (Blueprint $table) {
            $table->decimal('returned_quantity', 15, 4)->default(0)->after('quantity');
        });

        Schema::table('goods_receipt_note_lines', function (Blueprint $table) {
            $table->decimal('returned_unbilled_quantity', 15, 4)->default(0)->after('billed_quantity');
            $table->decimal('returned_billed_quantity', 15, 4)->default(0)->after('returned_unbilled_quantity');
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('return_code', 40);
            $table->foreignId('partner_id')->constrained('partners');
            $table->foreignId('sales_order_id')->constrained('sales_orders');
            $table->foreignId('delivery_note_id')->constrained('delivery_notes');
            $table->date('return_date');
            $table->enum('invoiced_treatment', ['credit', 'replace'])->default('credit');
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->decimal('accrual_reversed', 15, 2)->default(0);
            $table->decimal('revenue_deferred', 15, 2)->default(0);   // 'replace' — billed revenue moved back to 236
            $table->foreignId('inventory_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('revenue_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('credit_note_ids')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'return_code']);
        });

        Schema::create('sales_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->foreignId('delivery_note_line_id')->constrained('delivery_note_lines');
            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('location_id')->constrained('inventory_locations');
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4);            // original delivery cost
            $table->decimal('total_cost', 15, 2);
            $table->decimal('uninvoiced_quantity', 15, 4)->default(0);
            $table->decimal('invoiced_quantity', 15, 4)->default(0);
            $table->decimal('accrual_reversed_functional', 15, 2)->default(0);
            $table->decimal('deferred_created_functional', 15, 2)->default(0);
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->unsignedBigInteger('job_order_id')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('return_code', 40);
            $table->foreignId('partner_id')->constrained('partners');
            $table->foreignId('goods_receipt_note_id')->constrained('goods_receipt_notes');
            $table->date('return_date');
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('debit_note_ids')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'return_code']);
        });

        Schema::create('purchase_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('goods_receipt_note_line_id')->constrained('goods_receipt_note_lines');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('location_id')->constrained('inventory_locations');
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_cost', 15, 4);            // original receipt cost (EGP)
            $table->decimal('total_cost', 15, 2);           // qty × receipt cost — what GRNI is debited with
            $table->decimal('stock_value', 15, 2);          // what actually left inventory (capped at book value)
            $table->decimal('unbilled_quantity', 15, 4)->default(0);
            $table->decimal('billed_quantity', 15, 4)->default(0);
            $table->foreignId('stock_movement_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_lines');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('sales_return_lines');
        Schema::dropIfExists('sales_returns');
        Schema::table('goods_receipt_note_lines', fn (Blueprint $t) => $t->dropColumn(['returned_unbilled_quantity', 'returned_billed_quantity']));
        Schema::table('delivery_note_lines', fn (Blueprint $t) => $t->dropColumn('returned_quantity'));
    }
};
