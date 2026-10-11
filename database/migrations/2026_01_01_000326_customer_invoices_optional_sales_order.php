<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000326 — a customer invoice may have no sales order
//
//  AUDIT L-27. A service invoice is description, quantity, price,
//  and a revenue account. Goods still require a sales order, and
//  that check lives in CustomerInvoiceService.
//
//  @layer    Migration
//  @depends  customer_invoices, customer_invoice_lines
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->change();
        });

        Schema::table('customer_invoice_lines', function (Blueprint $table) {
            $table->foreignId('sales_order_line_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoice_lines', function (Blueprint $table) {
            $table->foreignId('sales_order_line_id')->nullable(false)->change();
        });

        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable(false)->change();
        });
    }
};
