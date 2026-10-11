<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000163_fix_so_invoiced_amount_vat_bug
//
//  Reported by Mahmoud: posting a Customer Invoice threw "Invoice
//  total exceeds remaining Sales Order value" even though the
//  invoice matched the SO — because the invoice total being compared
//  (invoice_value_functional) includes VAT, while Sales Orders are a
//  purely commercial document with no VAT concept at all
//  (sales_order_lines has no tax column — confirmed by inspection).
//  Comparing a VAT-inclusive number against a VAT-exclusive one was
//  always going to overstate the invoice by however much VAT it
//  carried.
//
//  The bug was actually in THREE places in CustomerInvoiceService,
//  all now fixed in this same pass (code changes ship alongside this
//  migration, not here — this migration only handles what the
//  database itself needs):
//    1. The exceeds-SO check compared invoice_value_functional (incl.
//       VAT) against so_value (excl. VAT). Fixed to compare
//       subtotal_amount_functional (excl. VAT) instead.
//    2. Posting an invoice added invoice_value_functional to the SO's
//       running invoiced_amount — inflating it by the VAT amount on
//       every single invoice ever posted.
//    3. Reopening an invoice to draft subtracted the same inflated
//       number back off — internally consistent with itself, but
//       still wrong relative to so_value.
//
//  What this migration does:
//
//  1. Backfills every Sales Order's invoiced_amount from scratch —
//     recomputed as the sum of subtotal_amount_functional (excl.
//     VAT) across its own posted Customer Invoices, not by trying to
//     "subtract out" the historical VAT drift row by row. A full
//     recompute from source is simpler and safer than patching a
//     number that's been wrong since the very first invoice with
//     VAT was ever posted — it can't compound or miss an edge case.
//
//  2. Adds exceeds_so_value to customer_invoices — confirmed with
//     Mahmoud: exceeding the SO value is no longer always a hard
//     block. If the agreed price genuinely goes up, the user gets a
//     warning and can proceed anyway; this column is the permanent
//     record that a specific invoice was allowed to exceed its SO
//     (drives the "Exceeds SO" badge on the index). The Sales
//     Order's own so_value is deliberately NEVER auto-adjusted when
//     this happens (confirmed with Mahmoud) — the badge on the
//     invoice is the record of the overage, not a rewrite of the
//     original agreement.
//
//  @layer    Migration
//  @depends  customer_invoices, sales_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->boolean('exceeds_so_value')->default(false)->after('status');
        });

        // Recompute every Sales Order's invoiced_amount from source —
        // sum of subtotal_amount_functional (excl. VAT) across its
        // own posted invoices. Chunked to avoid loading every SO into
        // memory at once on a large company.
        DB::table('sales_orders')->select('id')->orderBy('id')->chunkById(200, function ($salesOrders) {
            foreach ($salesOrders as $so) {
                $correctTotal = DB::table('customer_invoices')
                    ->where('sales_order_id', $so->id)
                    ->where('status', 'posted')
                    ->sum('subtotal_amount_functional');

                DB::table('sales_orders')
                    ->where('id', $so->id)
                    ->update(['invoiced_amount' => $correctTotal]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropColumn('exceeds_so_value');
        });

        // Deliberately no reversal of the invoiced_amount backfill —
        // the pre-fix numbers were wrong (VAT-inflated); reverting to
        // them would reintroduce the bug, not undo a data change.
    }
};
