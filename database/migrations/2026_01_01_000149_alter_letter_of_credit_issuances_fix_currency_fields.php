<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000149_alter_letter_of_credit_issuances_fix_currency_fields
//
//  Correction pass after re-verifying against CashVero's actual LC
//  Issuance forms (LcFacilityForm.vue / HundredPercentageCashCoverForm.vue
//  — both use identical formulas), confirmed with Mahmoud from a
//  screenshot of the real CashVero form:
//
//  1. exchange_rate (new) — LC Amount is entered in the LC's OWN
//     currency; exchange_rate converts it to the company's
//     FUNCTIONAL currency for cash-cover-% purposes. Defaults to 1
//     when the LC currency IS the functional currency; otherwise
//     auto-fetched from ExchangeRate::rateFor() and still user-
//     editable (exact port of CashVero's fetchExchangeRateForLcCurrency()).
//
//  2. amount_in_payment_currency (new) — LC Amount × exchange_rate,
//     stored (not just computed on the fly) so a report always shows
//     the exact functional-currency exposure that was actually used
//     to size the cash cover at issuance time, even if FX rates move
//     later. Matches CashVero's amount_in_main_currency exactly.
//
//  3. commission_fees_currency_id DROPPED — this was a mistake in the
//     first pass. CashVero has no such field at all: the Fees &
//     Commission account is ALWAYS in the LC's own currency
//     (confirmed: feesAccountOptions filters strictly by
//     a.currency === lc_currency in both CashVero forms). There is
//     nothing to separately pick — LetterOfCreditIssuanceService now
//     resolves commission/fee movements' currency straight from
//     currency_id.
//
//  cash_cover_currency_id is UNCHANGED here (still a real, separate,
//  user-picked field) — but its allowed values are now enforced at
//  the application layer to just [functional currency, LC currency],
//  matching CashVero's cashCoverCurrencyOptions exactly.
//
//  @layer    Migration
//  @depends  letter_of_credit_issuances
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_of_credit_issuances', function (Blueprint $table) {
            $table->dropForeign(['commission_fees_currency_id']);
            $table->dropColumn('commission_fees_currency_id');

            $table->decimal('exchange_rate', 15, 6)->default(1)->after('currency_id');
            $table->decimal('amount_in_payment_currency', 15, 2)->nullable()->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('letter_of_credit_issuances', function (Blueprint $table) {
            $table->dropColumn(['exchange_rate', 'amount_in_payment_currency']);

            $table->foreignId('commission_fees_currency_id')->nullable()->constrained('currencies')->nullOnDelete();
        });
    }
};
