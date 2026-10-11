<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000215_create_internal_settlement_supplier_allocations_table
//
//  Which Supplier Bills an Internal Settlement reduces, and by how
//  much — the AP side. Mirrors SupplierPaymentAllocation's shape.
//  allocated_amount is in the BILL's own native currency (same
//  currency as the settlement itself); allocated_amount_functional
//  is that amount converted at the BILL's own exchange_rate — the
//  value actually cleared from AP.
//
//  @layer    Migration
//  @depends  internal_settlements, supplier_bills, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_settlement_supplier_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('internal_settlement_id');
            $table->unsignedBigInteger('supplier_bill_id');

            $table->decimal('allocated_amount', 15, 2); // bill currency
            $table->decimal('allocated_amount_functional', 15, 2); // at the bill's own exchange_rate

            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            // Explicit short names throughout — same reasoning as the
            // customer_allocations migration right before this one (see
            // its header comment).
            $table->foreign('company_id', 'issa_company_fk')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('internal_settlement_id', 'issa_settlement_fk')->references('id')->on('internal_settlements')->cascadeOnDelete();
            $table->foreign('supplier_bill_id', 'issa_bill_fk')->references('id')->on('supplier_bills');
            $table->foreign('created_by', 'issa_creator_fk')->references('id')->on('users');

            $table->index(['company_id', 'internal_settlement_id'], 'issa_company_settlement_idx');
            $table->index(['company_id', 'supplier_bill_id'], 'issa_company_bill_idx');
            $table->unique(['internal_settlement_id', 'supplier_bill_id'], 'issa_settlement_bill_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_settlement_supplier_allocations');
    }
};
