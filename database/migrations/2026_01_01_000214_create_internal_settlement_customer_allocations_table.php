<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000214_create_internal_settlement_customer_allocations_table
//
//  Which Customer Invoices an Internal Settlement reduces, and by how
//  much — the AR side. Mirrors CustomerPaymentAllocation's shape.
//  allocated_amount is in the INVOICE's own native currency (same
//  currency as the settlement itself, by design); allocated_amount_
//  functional is that amount converted at the INVOICE's own
//  exchange_rate — the value actually cleared from AR.
//
//  @layer    Migration
//  @depends  internal_settlements, customer_invoices, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_settlement_customer_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('internal_settlement_id');
            $table->unsignedBigInteger('customer_invoice_id');

            $table->decimal('allocated_amount', 15, 2); // invoice currency
            $table->decimal('allocated_amount_functional', 15, 2); // at the invoice's own exchange_rate

            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            // Explicit short names throughout — Laravel's auto-generated
            // names (table_column_foreign) blow past MySQL's 64-char
            // identifier limit on a table name this long. Fixes the
            // migration failure hit on first run (September 2026).
            $table->foreign('company_id', 'isca_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('internal_settlement_id', 'isca_settlement_fk')->references('id')->on('internal_settlements')->cascadeOnDelete();
            $table->foreign('customer_invoice_id', 'isca_invoice_fk')->references('id')->on('customer_invoices');
            $table->foreign('created_by', 'isca_creator_fk')->references('id')->on('users');

            $table->index(['company_id', 'internal_settlement_id'], 'isca_company_settlement_idx');
            $table->index(['company_id', 'customer_invoice_id'], 'isca_company_invoice_idx');
            $table->unique(['internal_settlement_id', 'customer_invoice_id'], 'isca_settlement_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_settlement_customer_allocations');
    }
};
