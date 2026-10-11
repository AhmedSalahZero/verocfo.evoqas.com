<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000211_alter_supplier_payments_add_fx_gain_loss
//
//  fx_gain_loss_functional (signed, positive = gain, negative = loss)
//  — the SUM of every settlement row's own value (see the allocations
//  migration right before this one). Mirrors customer_payments'
//  equivalent column exactly. Zero on every payment posted before
//  this feature existed, and every same-currency, same-rate payment
//  from here on.
//
//  @layer    Migration
//  @depends  supplier_payments
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->decimal('fx_gain_loss_functional', 15, 2)->default(0)->after('amount_functional');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            $table->dropColumn('fx_gain_loss_functional');
        });
    }
};
