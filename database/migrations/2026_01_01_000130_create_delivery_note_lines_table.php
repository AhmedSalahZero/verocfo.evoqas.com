<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000130_create_delivery_note_lines_table
//
//  Delivery & Revenue Recognition v1.0 — see
//  VeroCFO_Delivery_Revenue_Recognition_Design_v1_0.md Sections 8, 9.
//
//  sales_order_line_id is mandatory — mirrors
//  goods_receipt_note_line_id's role on the purchase side. quantity
//  here increments the SO line's own delivered_quantity running
//  total (migration 000128) at posting.
//
//  wac_unit_cost / wac_total_value are captured at posting time from
//  the real StockLedgerService::issue() call — this table does not
//  recompute WAC itself, it just records what that call returned, for
//  the COGS debit and for permanent audit ("what did this delivery's
//  COGS actually get calculated from").
//
//  deferred_cleared_quantity / accrued_created_quantity — the split
//  this specific line's quantity resolved into, per the net-position
//  reconciliation (migration 000128's header, design doc Section
//  8.3): deferred_cleared_quantity clears existing Deferred Revenue
//  (this SO line was invoiced ahead of this delivery),
//  accrued_created_quantity creates fresh Accrued Revenue (the normal
//  case). The two always sum to this line's own quantity. Kept as
//  real stored columns — not just inferred from the journal entry —
//  so the split is directly auditable without having to reverse-
//  engineer it from GL lines later.
//
//  @layer    Migration
//  @depends  delivery_notes, sales_order_lines, products,
//            units_of_measure, stock_movements
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('delivery_note_id')->constrained('delivery_notes')->cascadeOnDelete();

            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines'); // mandatory — see header
            $table->foreignId('product_id')->constrained('products');
            $table->string('description', 500);

            $table->decimal('quantity', 15, 4);
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();

            // Revenue side — always the SO line's own agreed price (confirmed
            // by Mahmoud: terms are settled before the SO is issued).
            $table->decimal('unit_price', 15, 2);
            $table->decimal('line_revenue_amount', 15, 2); // quantity x unit_price, net of VAT (VAT never appears here — Section 3)

            // Cost side — captured from the real StockLedgerService::issue()
            // call at posting, not recomputed here. See header.
            $table->decimal('wac_unit_cost', 15, 2);
            $table->decimal('wac_total_value', 15, 2);
            $table->foreignId('stock_movement_id')->nullable()
                ->constrained('stock_movements')->nullOnDelete();

            // The split — see header
            $table->decimal('deferred_cleared_quantity', 15, 4)->default(0);
            $table->decimal('accrued_created_quantity', 15, 4)->default(0);

            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'delivery_note_id']);
            $table->index(['company_id', 'sales_order_line_id'], 'dnl_company_so_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_note_lines');
    }
};
