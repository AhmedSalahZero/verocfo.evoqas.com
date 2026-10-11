<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000206_alter_customer_payment_allocations_add_wht_and_fx
//
//  Adds Debit WHT and realized FX Gain/Loss to the Settlement side of
//  Customer Collection (per Mahmoud, September 2026). Both are
//  entirely OPTIONAL per settlement row — a normal, same-currency,
//  no-withholding collection leaves both columns at zero and behaves
//  exactly as before this migration.
//
//  wht_amount / wht_amount_functional — the Debit WHT the customer
//  withheld against THIS invoice, entered by the user on the
//  Settlement row (not calculated from the invoice — this is the
//  real amount on the certificate the customer hands over). Valued
//  at the INVOICE's own exchange_rate, same as allocated_amount —
//  WHT is a paper amount cleared at book value, not something
//  exposed to market-rate movement. Deliberately kept SEPARATE from
//  allocated_amount (which remains real-cash-only, unchanged
//  meaning) so CustomerPayment's own allocated_amount/
//  unallocated_amount bookkeeping — which tracks the actual cash
//  pool — is never contaminated by a non-cash clearing.
//
//  fx_gain_loss_functional — signed (positive = gain, negative =
//  loss). The difference between this settlement row's cash valued
//  at the invoice's own original exchange_rate (what AR was cleared
//  at) vs. valued at today's actual collection-time rate (what the
//  cash is really worth). Stored per-row so a future customer
//  statement can show the FX effect of each individual collection
//  event, not just a lump sum.
//
//  @layer    Migration
//  @depends  customer_payment_allocations
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payment_allocations', function (Blueprint $table) {
            $table->decimal('wht_amount', 15, 2)->default(0)->after('allocated_amount'); // invoice currency
            $table->decimal('wht_amount_functional', 15, 2)->default(0)->after('wht_amount');
            $table->decimal('fx_gain_loss_functional', 15, 2)->default(0)->after('wht_amount_functional'); // signed
        });
    }

    public function down(): void
    {
        Schema::table('customer_payment_allocations', function (Blueprint $table) {
            $table->dropColumn(['wht_amount', 'wht_amount_functional', 'fx_gain_loss_functional']);
        });
    }
};
