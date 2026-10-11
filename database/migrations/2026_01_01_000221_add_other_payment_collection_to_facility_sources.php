<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000221_add_other_payment_collection_to_facility_sources
//
//  Closes the last confirmed gap in Building Bible Rule 34's Facility
//  Settlement Engine rollout: Other Payment and Other Collection both
//  already let the user pick a Credit Facility as the money source/
//  destination (OtherPayment::SOURCE_CREDIT_FACILITY,
//  OtherCollection::DESTINATION_CREDIT_FACILITY) — the GL entry has
//  always posted correctly — but neither ever called
//  FacilitySettlementEngine, so the facility's own sub-ledger
//  (outstanding balance, which withdrawal is open, aging) silently
//  never learned about these two transaction types. Supplier
//  Payment / Customer Payment / Internal Transfer already do this
//  correctly — this migration + the matching service changes bring
//  Other Payment / Other Collection to the same standard.
//
//  MySQL requires a raw MODIFY for enum changes (no doctrine/dbal in
//  this project — same pattern as 000144, 000164). Existing rows are
//  untouched, only the allowed value set grows.
//
//  @layer    Migration
//  @depends  facility_withdrawals, facility_settlements
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE facility_withdrawals MODIFY source_type ENUM(
            'opening_balance',
            'supplier_payment',
            'internal_money_transfer',
            'other_payment'
        ) NULL");

        DB::statement("ALTER TABLE facility_settlements MODIFY source_type ENUM(
            'customer_payment',
            'internal_money_transfer',
            'other_collection'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE facility_withdrawals MODIFY source_type ENUM(
            'opening_balance',
            'supplier_payment',
            'internal_money_transfer'
        ) NULL");

        DB::statement("ALTER TABLE facility_settlements MODIFY source_type ENUM(
            'customer_payment',
            'internal_money_transfer'
        ) NOT NULL");
    }
};
