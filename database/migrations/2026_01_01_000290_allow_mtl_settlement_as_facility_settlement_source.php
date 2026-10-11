<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000290_allow_mtl_settlement_as_facility_settlement_source
//
//  TRS-002. A Medium-Term Loan is now a payment source like a Clean
//  Overdraft: paying a supplier from it draws it down, and repaying an
//  instalment pays that drawdown back. The repayment is recorded
//  through FacilitySettlementEngine like every other settlement, so
//  facility_settlements.source_type needs the new value.
//
//  Only the PRINCIPAL half of an instalment reaches this table.
//  Interest is a cost of carrying the debt, not a repayment of it, so
//  it never reduces the drawn balance or replenishes room — the same
//  rule CashVero settled on 2026-08-16.
//
//  @layer    Migration
//  @depends  facility_settlements
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    private const OLD = "'customer_payment','internal_money_transfer','other_collection'";
    private const NEW = "'customer_payment','internal_money_transfer','other_collection','mtl_settlement'";

    public function up(): void
    {
        DB::statement('ALTER TABLE facility_settlements MODIFY source_type ENUM('.self::NEW.') NOT NULL');
    }

    public function down(): void
    {
        // Audit L-11: a rollback must never delete financial history.
        if (DB::table('facility_settlements')->where('source_type', 'mtl_settlement')->exists()) {
            throw new \RuntimeException(
                'Cannot roll back: medium-term-loan settlements exist in facility_settlements. '.
                'Rolling back would delete them. Leave this migration in place.'
            );
        }
        DB::statement('ALTER TABLE facility_settlements MODIFY source_type ENUM('.self::OLD.') NOT NULL');
    }
};
