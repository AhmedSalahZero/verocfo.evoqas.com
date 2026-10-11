<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000306_add_lc_cash_cover_release
//
//  Audit H-21 — LC cash cover was placed (Dr 138002 / Cr Bank) but
//  never released, so 138002 and the bank stayed misstated forever.
//
//  letter_of_credit_issuances
//    + cash_cover_released_amount   how much cover has been cleared
//    + cash_cover_released_at       the date it was cleared
//    + cash_cover_release_mode      'released' (cash back to the bank
//                                   account) or 'applied' (used to
//                                   reduce what the bank financed)
//    + status 'expired'             LC closed unused (expired/cancelled)
//
//  lc_issuance_history
//    + event_type 'cash_cover_release'  (expiry release — undoable)
//    + cash_cover_released_amount
//
//  The enum widening is only needed on MySQL (the real database).
//
//  @layer    Migration
//  @depends  letter_of_credit_issuances, lc_issuance_history
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_of_credit_issuances', function (Blueprint $table) {
            $table->decimal('cash_cover_released_amount', 15, 2)->nullable()->after('cash_cover_account_id');
            $table->date('cash_cover_released_at')->nullable()->after('cash_cover_released_amount');
            $table->string('cash_cover_release_mode', 20)->nullable()->after('cash_cover_released_at');
        });

        Schema::table('lc_issuance_history', function (Blueprint $table) {
            $table->decimal('cash_cover_released_amount', 15, 2)->nullable();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE letter_of_credit_issuances MODIFY COLUMN status ENUM('running','paid','expired') NOT NULL DEFAULT 'running'");
            DB::statement("ALTER TABLE lc_issuance_history MODIFY COLUMN event_type ENUM('mark_paid','settlement','cash_cover_release') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE letter_of_credit_issuances SET status = 'running' WHERE status = 'expired'");
            DB::statement("DELETE FROM lc_issuance_history WHERE event_type = 'cash_cover_release'");
            DB::statement("ALTER TABLE letter_of_credit_issuances MODIFY COLUMN status ENUM('running','paid') NOT NULL DEFAULT 'running'");
            DB::statement("ALTER TABLE lc_issuance_history MODIFY COLUMN event_type ENUM('mark_paid','settlement') NOT NULL");
        }

        Schema::table('lc_issuance_history', function (Blueprint $table) {
            $table->dropColumn('cash_cover_released_amount');
        });
        Schema::table('letter_of_credit_issuances', function (Blueprint $table) {
            $table->dropColumn(['cash_cover_released_amount', 'cash_cover_released_at', 'cash_cover_release_mode']);
        });
    }
};
