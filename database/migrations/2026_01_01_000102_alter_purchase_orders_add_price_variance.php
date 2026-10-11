<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000102_alter_purchase_orders_add_price_variance
//
//  Supplier Bill (Accounting Engine Phase 2, module 3). Confirmed by
//  Mahmoud: when the supplier's actual invoice price differs from
//  the price the goods were received at (Goods Receipt Note's
//  unit_cost), the system does NOT block posting and does NOT
//  invent a dedicated "Purchase Price Variance" COA account — it
//  posts the difference against the product's own inventory/COGS
//  accounts (see migration 000107 header for the split logic) and
//  simply FLAGS the affected Purchase Order so the discrepancy is
//  visible.
//
//  These two header columns are a ROLL-UP of every line-level flag
//  on this PO (see migration 000103) — recalculated by
//  SupplierBillService every time a Bill posts against this PO,
//  the same "recalculate the parent from every one of its lines"
//  pattern GoodsReceiptNoteService already uses for receipt_status.
//
//  `price_variance_amount` is signed (bcmath) — positive means the
//  company ended up paying more than the GRN cost, negative means
//  less. Never reset to zero once non-zero; it's a running total
//  across every Bill ever posted against this PO, for as long as
//  the PO stays open.
//
//  @layer    Migration
//  @depends  purchase_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->boolean('has_price_variance')->default(false)->after('billing_status');
            $table->decimal('price_variance_amount', 15, 2)->default(0)->after('has_price_variance'); // functional currency, signed
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['has_price_variance', 'price_variance_amount']);
        });
    }
};
