<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000074_create_purchase_order_lines_table
//
//  Mirrors sales_order_lines closely, with two differences confirmed
//  by Mahmoud:
//
//    - NO revenue/expense account column. A PO line is inventory-
//      bound, not COGS-bound — the account resolves automatically
//      from the product's classification at Goods Receipt Note time
//      (same "100% automatic account assignment" principle already
//      used for Products in Settings). Nothing to store here.
//
//    - `received_quantity` — reserved for the future GOODS RECEIPT
//      NOTE module. Defaults to 0 and stays there until GRN exists.
//      Compared against `quantity` (adjusted by `tolerance_pct`) to
//      derive the PARENT purchase_orders.receipt_status. Confirmed:
//      partial receipt is normal — a PO can stay open indefinitely
//      with some lines fully received and others still pending.
//
//    - `tolerance_pct` — confirmed to live PER LINE, entered by the
//      user at PO creation time (not a company-wide default, not
//      set later at receipt time). Represents the allowed over/
//      under-receipt tolerance for that specific line — e.g. a line
//      with quantity=1000 and tolerance_pct=5 is considered fully
//      received anywhere between 950 and 1050. The actual over/
//      under validation logic belongs to the future GRN module —
//      this column just makes sure the tolerance the user intended
//      at ordering time isn't lost before GRN exists to use it.
//
//  @layer    Migration
//  @depends  purchase_orders, products, units_of_measure
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();

            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 15, 4);
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('unit_price', 15, 2);
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->decimal('line_total', 15, 2);

            $table->decimal('received_quantity', 15, 4)->default(0); // future GRN placeholder — see header
            $table->decimal('tolerance_pct', 5, 2)->default(0);      // user-entered at PO issuance — see header

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'purchase_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
