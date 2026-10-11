<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000131_alter_customer_invoice_lines_for_delivery
//
//  Delivery & Revenue Recognition v1.0 — see
//  VeroCFO_Delivery_Revenue_Recognition_Design_v1_0.md Section 9.
//
//  A Customer Invoice line for a physical-goods SO line now branches
//  by whether that SO line's net position (migration 000128) shows
//  it's already been delivered:
//
//    accrued_cleared_quantity — the portion clearing existing Accrued
//      Revenue (143) — normal case, delivery already happened.
//      `Dr AR / Cr 143` (+ price variance, Section 4).
//    deferred_created_quantity — the portion with NO matching
//      delivery yet — advance billing (Section 2.2).
//      `Dr AR / Cr 236 Deferred Revenue`, no COGS/Inventory movement.
//
//  The two always sum to the line's own quantity — same "real stored
//  split, not inferred from the journal entry" reasoning as
//  delivery_note_lines' equivalent pair (migration 000130).
//
//  delivery_note_line_id is nullable and, unlike
//  sales_order_line_id, is NOT necessarily a single link — one
//  invoice line's accrued_cleared_quantity could in principle clear
//  accrued revenue built up across more than one prior delivery. Kept
//  nullable and best-effort (points at the most recent relevant
//  delivery line) for now; a proper many-to-many matching table is
//  flagged as a future refinement if this ever needs to be fully
//  precise rather than informational.
//
//  price_variance_amount — signed, functional currency: positive
//  when the invoice price was higher than the SO price the accrual
//  was made at (credited to Revenue), negative when lower (debited to
//  Revenue) — see design doc Section 4's worked examples. Always 0
//  for a pure deferred_created_quantity portion (no prior accrual to
//  vary against) and for service lines (unaffected by this migration
//  at all).
//
//  @layer    Migration
//  @depends  customer_invoice_lines, delivery_note_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoice_lines', function (Blueprint $table) {
            $table->foreignId('delivery_note_line_id')->nullable()->after('sales_order_line_id')
                ->constrained('delivery_note_lines')->nullOnDelete();

            $table->decimal('accrued_cleared_quantity', 15, 4)->default(0);
            $table->decimal('deferred_created_quantity', 15, 4)->default(0);
            $table->decimal('price_variance_amount', 15, 2)->default(0); // functional currency, signed — see header
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoice_lines', function (Blueprint $table) {
            $table->dropForeign(['delivery_note_line_id']);
            $table->dropColumn([
                'delivery_note_line_id', 'accrued_cleared_quantity',
                'deferred_created_quantity', 'price_variance_amount',
            ]);
        });
    }
};
