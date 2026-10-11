<?php

// ══════════════════════════════════════════════════════════════════
//  create_stock_reservations_table
//
//  General stock reservation (July 2026 — confirmed with Mahmoud,
//  simple scope): once a Sales Order is activated, a warehouse person
//  can reserve its lines' quantity against the product, company-wide
//  — NOT tied to any one Inventory Location. Deliberately simple:
//  - Handled entirely from its own screen (Stock Reservations),
//    nothing added to the Sales Order form itself.
//  - "General" only — no per-location block was built (explicitly
//    descoped). Because it isn't location-specific, an Internal
//    Transfer between locations is completely unaffected by a
//    reservation existing — the reservation only reduces the
//    company-wide "available to promise" figure shown on the Stock
//    Balance page, it does not prevent any specific location's stock
//    from moving.
//  - One reservation per Sales Order Line (unique constraint) — kept
//    deliberately atomic rather than tracking partial quantities,
//    since there's no Delivery Note yet to drive a partial release.
//  - Release is a manual action on the same screen today ("mark
//    delivered / release"). Confirmed: release should happen once
//    the line is fully delivered — since Delivery Note doesn't exist
//    yet, that trigger is a person's judgment call for now; the
//    moment Delivery Note is built, it can call the same release path
//    automatically instead.
//
//  @layer    Migration
//  @depends  companies, sales_orders, sales_order_lines, products, users
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignId('sales_order_id')->constrained('sales_orders')->cascadeOnDelete();
            $table->foreignId('sales_order_line_id')->unique()->constrained('sales_order_lines')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');

            $table->decimal('quantity', 15, 4);

            $table->enum('status', ['active', 'released'])->default('active');

            $table->foreignId('reserved_by')->constrained('users');
            $table->timestamp('reserved_at');

            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'product_id', 'status']);
            $table->index(['company_id', 'sales_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservations');
    }
};
