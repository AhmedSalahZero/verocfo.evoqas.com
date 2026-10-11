<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000103_alter_purchase_order_lines_add_price_variance
//
//  Line-level detail behind the purchase_orders header roll-up
//  (migration 000102). Written by SupplierBillService whenever a
//  posted Bill line matches a GRN line that traces back to this PO
//  line, and the Bill's unit price differs from the GRN's unit_cost.
//
//  `price_variance_amount` is a running total for THIS line across
//  every Bill ever posted against it — never reset, same "cumulative,
//  never replaced" convention as received_quantity on this same table.
//
//  `price_variance_note` is a short auto-generated audit trail — one
//  line appended per occurrence, e.g. "SB/2026/07/00003: GRN cost
//  48.00 vs Bill price 50.00 (+2.00 x 100 = +200.00)" — so a user
//  looking at a flagged PO line can see exactly which Bill(s) caused
//  the flag without having to cross-reference the journal entries.
//
//  @layer    Migration
//  @depends  purchase_order_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->boolean('has_price_variance')->default(false)->after('tolerance_pct');
            $table->decimal('price_variance_amount', 15, 2)->default(0)->after('has_price_variance'); // functional currency, signed, cumulative
            $table->text('price_variance_note')->nullable()->after('price_variance_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropColumn(['has_price_variance', 'price_variance_amount', 'price_variance_note']);
        });
    }
};
