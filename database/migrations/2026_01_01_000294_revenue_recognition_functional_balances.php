<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000294 — revenue recognition remembers EGP values
//
//  AUDIT C8 (Sept 2026). A Delivery Note on a USD Sales Order booked
//  Accrued Revenue (143) at quantity × USD price with NO exchange
//  rate — USD 1,000 went into the EGP ledger as EGP 1,000. The
//  invoice then cleared 143 at × 50, leaving a large balance behind.
//  Deferred Revenue (236) had the mirror-image problem.
//
//  The fix values each side once, at its own date, and remembers the
//  EGP figure so the other side clears EXACTLY what was booked:
//
//    sales_order_lines
//      • accrued_revenue_functional  — running EGP balance this SO
//        line holds in 143 (delivered, not yet invoiced)
//      • deferred_revenue_functional — running EGP balance this SO
//        line holds in 236 (invoiced, not yet delivered)
//    delivery_note_lines
//      • accrued_created_functional, deferred_cleared_functional
//    customer_invoice_lines
//      • accrued_cleared_functional, deferred_created_functional,
//        fx_difference_functional (realised FX on the 143 clearing)
//
//  The per-document figures make Set-to-Draft reverse exactly what
//  was posted.
//
//  BACKFILL — the two SO-line balances are rebuilt from every POSTED
//  delivery and invoice, using exactly the amounts the old code
//  posted, so each SO line starts equal to what the ledger holds for
//  it. For functional-currency (EGP) orders this is simply the
//  familiar quantity × price.
//
//  @layer    Migration
//  @depends  sales_order_lines, delivery_note_lines, delivery_notes,
//            customer_invoice_lines, customer_invoices
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->decimal('accrued_revenue_functional', 15, 2)->default(0)->after('invoiced_quantity');
            $table->decimal('deferred_revenue_functional', 15, 2)->default(0)->after('accrued_revenue_functional');
        });

        Schema::table('delivery_note_lines', function (Blueprint $table) {
            $table->decimal('accrued_created_functional', 15, 2)->default(0)->after('accrued_created_quantity');
            $table->decimal('deferred_cleared_functional', 15, 2)->default(0)->after('accrued_created_functional');
        });

        Schema::table('customer_invoice_lines', function (Blueprint $table) {
            $table->decimal('accrued_cleared_functional', 15, 2)->default(0)->after('price_variance_amount');
            $table->decimal('deferred_created_functional', 15, 2)->default(0)->after('accrued_cleared_functional');
            $table->decimal('fx_difference_functional', 15, 2)->default(0)->after('deferred_created_functional');
        });

        $this->backfill();
    }

    /** Rebuilds per-line and per-SO-line EGP figures from what was actually posted. */
    private function backfill(): void
    {
        $accrued  = []; // sales_order_line_id => string
        $deferred = [];

        // Posted deliveries — the old code posted qty × unit_price, no rate.
        $deliveryLines = DB::table('delivery_note_lines as l')
            ->join('delivery_notes as d', 'd.id', '=', 'l.delivery_note_id')
            ->where('d.status', 'posted')
            ->get(['l.id', 'l.sales_order_line_id', 'l.unit_price', 'l.accrued_created_quantity', 'l.deferred_cleared_quantity']);

        foreach ($deliveryLines as $l) {
            $created = bcmul((string) $l->accrued_created_quantity, (string) $l->unit_price, 2);
            $cleared = bcmul((string) $l->deferred_cleared_quantity, (string) $l->unit_price, 2);

            DB::table('delivery_note_lines')->where('id', $l->id)->update([
                'accrued_created_functional'  => $created,
                'deferred_cleared_functional' => $cleared,
            ]);

            $accrued[$l->sales_order_line_id]  = bcadd($accrued[$l->sales_order_line_id] ?? '0', $created, 2);
            $deferred[$l->sales_order_line_id] = bcsub($deferred[$l->sales_order_line_id] ?? '0', $cleared, 2);
        }

        // Posted invoices — the old code cleared 143 at qty × SO price × invoice rate
        // and created 236 at qty × invoice price × invoice rate.
        $invoiceLines = DB::table('customer_invoice_lines as l')
            ->join('customer_invoices as i', 'i.id', '=', 'l.customer_invoice_id')
            ->leftJoin('sales_order_lines as s', 's.id', '=', 'l.sales_order_line_id')
            ->where('i.status', 'posted')
            ->whereNotNull('l.sales_order_line_id')
            ->get(['l.id', 'l.sales_order_line_id', 'l.unit_price', 'l.accrued_cleared_quantity', 'l.deferred_created_quantity', 'i.exchange_rate', 's.unit_price as so_unit_price']);

        foreach ($invoiceLines as $l) {
            $rate    = (string) ($l->exchange_rate ?: '1');
            $cleared = bcmul(bcmul((string) $l->accrued_cleared_quantity, (string) ($l->so_unit_price ?? '0'), 2), $rate, 2);
            $created = bcmul(bcmul((string) $l->deferred_created_quantity, (string) $l->unit_price, 2), $rate, 2);

            DB::table('customer_invoice_lines')->where('id', $l->id)->update([
                'accrued_cleared_functional'  => $cleared,
                'deferred_created_functional' => $created,
            ]);

            $accrued[$l->sales_order_line_id]  = bcsub($accrued[$l->sales_order_line_id] ?? '0', $cleared, 2);
            $deferred[$l->sales_order_line_id] = bcadd($deferred[$l->sales_order_line_id] ?? '0', $created, 2);
        }

        foreach (array_unique(array_merge(array_keys($accrued), array_keys($deferred))) as $soLineId) {
            DB::table('sales_order_lines')->where('id', $soLineId)->update([
                'accrued_revenue_functional'  => $accrued[$soLineId] ?? '0',
                'deferred_revenue_functional' => $deferred[$soLineId] ?? '0',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('customer_invoice_lines', function (Blueprint $table) {
            $table->dropColumn(['accrued_cleared_functional', 'deferred_created_functional', 'fx_difference_functional']);
        });
        Schema::table('delivery_note_lines', function (Blueprint $table) {
            $table->dropColumn(['accrued_created_functional', 'deferred_cleared_functional']);
        });
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropColumn(['accrued_revenue_functional', 'deferred_revenue_functional']);
        });
    }
};
