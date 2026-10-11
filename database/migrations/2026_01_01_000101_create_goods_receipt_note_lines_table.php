<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000101_create_goods_receipt_note_lines_table
//
//  `purchase_order_line_id` is NULLABLE — set when this line was
//  received against a specific PO line; null for an ad-hoc line on
//  a no-PO GRN (see companies.grn_policy, migration 000097).
//
//  `project_id` / `sales_order_id` — both nullable, independent of
//  whether the line is PO-based or ad-hoc. This is the "each receipt
//  line can be independently tagged to a project/SO" rule from the
//  Project Module doc §6.2 — a single GRN can have some lines for
//  general stock and others tagged to specific projects.
//
//  `unit_cost` is captured in FUNCTIONAL currency (see parent table
//  header) — this is exactly the `actual_unit_cost` the Project
//  Module's dual-valuation model (§6.4) needs when project_id is
//  set, and is also what feeds StockLedgerService::receive() for
//  the WAC recalculation regardless of project tagging.
//
//  @layer    Migration
//  @depends  goods_receipt_notes, purchase_order_lines, products,
//            units_of_measure, projects, sales_orders
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipt_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('goods_receipt_note_id')->constrained('goods_receipt_notes')->cascadeOnDelete();

            $table->foreignId('purchase_order_line_id')->nullable()
                ->constrained('purchase_order_lines')->nullOnDelete(); // null = ad-hoc line, see header

            $table->foreignId('product_id')->constrained('products');
            $table->string('description', 500)->nullable();

            $table->decimal('quantity', 15, 4);
            $table->foreignId('uom_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('unit_cost', 15, 2); // functional currency — see header
            $table->decimal('line_total', 15, 2);

            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'goods_receipt_note_id']);
            $table->index(['company_id', 'purchase_order_line_id']);
            $table->index(['company_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_note_lines');
    }
};
