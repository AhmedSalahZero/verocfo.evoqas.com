<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000119_create_customer_invoice_lines_table
//
//  One row per invoiced item/service. sales_order_line_id is
//  mandatory (mirrors goods_receipt_note_line_id's role on the
//  purchase side) — every invoice line traces back to exactly one SO
//  line, which is how partial/milestone invoicing (multiple invoices
//  against one SO, Application Scope §5.1) stays reconcilable: you
//  can always ask "how much of this SO line has been invoiced so
//  far?" the same way GRN lines track billed_quantity.
//
//  revenue_account_id defaults from the SO line's own
//  revenue_account_id (already auto-assigned from the product at SO
//  creation, Project Module doc) but is copied onto the invoice line
//  rather than read live from the SO line at posting time — same
//  reasoning as Supplier Bill copying price/account onto its own
//  line: an invoice is a point-in-time financial record, it must not
//  silently change if the SO line is edited later.
//
//  No goods/inventory movement happens here — Vero CFO's Sales Order
//  → Invoice chain is a Phase 1 (Accounting Engine) concern only.
//  Physical delivery, when it matters, is tracked separately through
//  the Project Module's inventory movements — this table only ever
//  posts revenue and AR, never touches stock.
//
//  @layer    Migration
//  @depends  customer_invoices, sales_order_lines, products,
//            units_of_measure, chart_of_accounts, cost_centers
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('customer_invoice_id')->constrained('customer_invoices')->cascadeOnDelete();

            $table->foreignId('sales_order_line_id')->constrained('sales_order_lines'); // mandatory — see header
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 500);

            $table->decimal('quantity', 15, 4)->default(1);
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('unit_price', 15, 2); // invoice currency
            $table->decimal('line_subtotal', 15, 2); // invoice currency, excl VAT — qty x unit_price

            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('vat_amount', 15, 2)->default(0); // invoice currency
            $table->decimal('line_total', 15, 2); // invoice currency — subtotal + vat

            $table->foreignId('revenue_account_id')->constrained('chart_of_accounts'); // copied from SO line at invoice time, user-overridable
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();

            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'customer_invoice_id']);
            $table->index(['company_id', 'sales_order_line_id'], 'cil_company_so_line_idx'); // "how much of this SO line invoiced so far"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invoice_lines');
    }
};
