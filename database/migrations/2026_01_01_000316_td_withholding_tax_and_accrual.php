<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000316_td_withholding_tax_and_accrual   (audit M-33)
//
//  Egyptian banks withhold 20% tax at source on deposit interest.
//    bank_accounts.td_wht_rate       — % withheld on this deposit's interest
//                                      (default 20, editable per deposit).
//    bank_account_td_events.wht_*    — what was withheld on each event.
//    facility_period_end_entries.bank_account_id — lets the month-end
//                                      accrual run reference a deposit.
//
//  @layer    Migration
//  @depends  bank_accounts, bank_account_td_events, facility_period_end_entries
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->decimal('td_wht_rate', 5, 2)->default(20)->after('td_interest_rate');
        });
        Schema::table('bank_account_td_events', function (Blueprint $table) {
            $table->decimal('wht_rate', 5, 2)->nullable()->after('interest_amount');
            $table->decimal('wht_amount', 15, 2)->default(0)->after('wht_rate');
        });
        Schema::table('facility_period_end_entries', function (Blueprint $table) {
            $table->foreignId('bank_account_id')->nullable()->after('leasing_contract_id')->constrained('bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('facility_period_end_entries', fn (Blueprint $t) => $t->dropConstrainedForeignId('bank_account_id'));
        Schema::table('bank_account_td_events', fn (Blueprint $t) => $t->dropColumn(['wht_rate', 'wht_amount']));
        Schema::table('bank_accounts', fn (Blueprint $t) => $t->dropColumn('td_wht_rate'));
    }
};
