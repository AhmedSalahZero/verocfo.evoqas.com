<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000208_alter_customer_payments_add_fx_gain_loss
//
//  fx_gain_loss_functional (signed, positive = gain, negative = loss)
//  — the SUM of every Settlement row's own fx_gain_loss_functional
//  (see the customer_payment_allocations migration right before this
//  one), kept here too purely so the collection's own Edit screen and
//  any list/report can show "this collection generated a net FX
//  gain/loss of X" without summing allocations every time. Zero on
//  every collection that doesn't involve a rate difference — which is
//  every collection posted before this feature existed, and every
//  same-currency, same-rate collection from here on.
//
//  @layer    Migration
//  @depends  customer_payments
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->decimal('fx_gain_loss_functional', 15, 2)->default(0)->after('amount_functional');
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropColumn('fx_gain_loss_functional');
        });
    }
};
