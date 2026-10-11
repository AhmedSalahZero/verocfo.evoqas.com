<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000128_alter_sales_order_lines_add_quantity_tracking
//
//  Delivery & Revenue Recognition v1.0 — see
//  VeroCFO_Delivery_Revenue_Recognition_Design_v1_0.md, the
//  authoritative reference.
//
//  Two independent running totals, each incremented only by its own
//  side (delivered_quantity by DeliveryNoteService, invoiced_quantity
//  by CustomerInvoiceService) — same "stored running counter" pattern
//  already used for goods_receipt_note_lines.billed_quantity, not a
//  live-computed sum.
//
//  THE RECONCILIATION MECHANISM: at any moment,
//  `delivered_quantity - invoiced_quantity` is this SO line's net
//  position —
//    positive  → delivered ahead of invoicing → that excess is
//                outstanding Accrued Revenue (143) quantity
//    negative  → invoiced ahead of delivery → that shortfall is
//                outstanding Deferred Revenue (236) quantity
//
//  Both DeliveryNoteService and CustomerInvoiceService read this net
//  position at the moment of their own posting to decide how much of
//  THIS event's quantity clears the other side's existing waiting
//  account vs. creates a fresh one of its own — see design doc
//  Sections 8.3 and 9. Using two independent running totals rather
//  than a single combined counter means this naturally handles a
//  single delivery or invoice event partially clearing existing
//  history and partially creating new — without needing per-document
//  matching records to track which specific prior delivery/invoice
//  a given portion relates to.
//
//  @layer    Migration
//  @depends  sales_order_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->decimal('delivered_quantity', 15, 4)->default(0)->after('quantity');
            $table->decimal('invoiced_quantity', 15, 4)->default(0)->after('delivered_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn(['delivered_quantity', 'invoiced_quantity']);
        });
    }
};
