<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000207_alter_customer_invoices_add_amount_wht_deducted
//
//  amount_wht_deducted (functional) — running total of Debit WHT
//  withheld against this invoice across every Settlement row that's
//  entered one (see CustomerPaymentService::postPaymentTransaction()).
//  Mirrors amount_credited/amount_debited exactly: a running total
//  maintained on every settle/reverse, folded into amount_outstanding
//  the same way. Zero by default — an invoice nobody has ever
//  withheld tax against behaves exactly as it did before this column
//  existed.
//
//  @layer    Migration
//  @depends  customer_invoices
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->decimal('amount_wht_deducted', 15, 2)->default(0)->after('amount_debited');
        });
    }

    public function down(): void
    {
        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropColumn('amount_wht_deducted');
        });
    }
};
