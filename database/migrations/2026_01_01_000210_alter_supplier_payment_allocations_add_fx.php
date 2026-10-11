<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000210_alter_supplier_payment_allocations_add_fx
//
//  Realized FX Gain/Loss on Supplier Payment settlements — the
//  payable-side mirror of migration 000206 (customer_payment_
//  allocations). SAME sign convention as the customer side: positive
//  = gain, negative = loss — see SupplierPaymentService::
//  postPaymentTransaction() for exactly how that's derived on the
//  payable side (paying out LESS functional currency than the bill
//  was booked at is a gain to us; paying out MORE is a loss). Zero on
//  every same-currency, same-rate settlement, which is the vast
//  majority.
//
//  @layer    Migration
//  @depends  supplier_payment_allocations
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payment_allocations', function (Blueprint $table) {
            $table->decimal('fx_gain_loss_functional', 15, 2)->default(0)->after('allocated_amount');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payment_allocations', function (Blueprint $table) {
            $table->dropColumn('fx_gain_loss_functional');
        });
    }
};
