<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000080_drop_dormant_single_instrument_lg_lc_columns
//
//  Removes 8 columns from credit_facilities that were built for an
//  earlier "LG/LC as single-instrument contract" design, before the
//  confirmed agreement + rate-card + issuance model was adopted
//  (see migration 000065's own header comment, which first flagged
//  these as dormant).
//
//  LG side — SAFE, confirmed replaced:
//    lg_beneficiary, lg_beneficiary_ar, lg_margin_pct,
//    lg_cash_cover_account_id
//    → superseded by lg_issuances (beneficiary_partner_id,
//      cash_cover_pct, cash_cover_amount, cash_cover_account_id),
//      which is fully built and live. Nothing reads these 4 columns.
//
//  LC side — dropped now on the same reasoning, ahead of LC Issuance:
//    lc_supplier_id, lc_shipment_date, lc_settlement_terms,
//    lc_cash_cover_account_id
//    → LC Issuance is not built yet, but per Mahmoud (confirmed):
//      when it is built, it will get its own purpose-designed fields
//      the same way lg_issuances did — not by resurrecting these.
//      Keeping them adds the same dead-weight risk LG's columns had
//      (still in $fillable, still validated in facilityRules(),
//      still unreachable from any Vue form) for no future benefit.
//
//  IMPORTANT — before running this migration:
//  Confirm no live data exists in any of these 8 columns in any
//  environment (dev, staging, production) — e.g.:
//    SELECT COUNT(*) FROM credit_facilities
//    WHERE lg_beneficiary IS NOT NULL OR lg_margin_pct != 0
//       OR lg_cash_cover_account_id IS NOT NULL
//       OR lc_supplier_id IS NOT NULL OR lc_shipment_date IS NOT NULL
//       OR lc_settlement_terms IS NOT NULL OR lc_cash_cover_account_id IS NOT NULL;
//  If that returns 0, this migration is a clean, safe drop. If it
//  does not, stop and review those rows first — once this runs,
//  `down()` restores the columns but not any data that mattered.
//
//  Companion changes in the same commit:
//    - app/Models/FinancialInstitutions/CreditFacility.php
//        removes the 8 fields from $fillable and casts, and removes
//        the lcSupplier() relationship (dead once lc_supplier_id
//        is gone).
//    - app/Http/Controllers/FinancialInstitutions/BankController.php
//        removes the 8 fields from facilityRules().
//
//  @layer    Migration
//  @depends  credit_facilities
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropColumn([
                'lg_beneficiary',
                'lg_beneficiary_ar',
                'lg_margin_pct',
                'lg_cash_cover_account_id',
                'lc_supplier_id',
                'lc_shipment_date',
                'lc_settlement_terms',
                'lc_cash_cover_account_id',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->string('lg_beneficiary', 300)->nullable();
            $table->string('lg_beneficiary_ar', 300)->nullable();
            $table->decimal('lg_margin_pct', 5, 2)->default(0);
            $table->unsignedBigInteger('lg_cash_cover_account_id')->nullable();

            $table->unsignedBigInteger('lc_supplier_id')->nullable();
            $table->date('lc_shipment_date')->nullable();
            $table->string('lc_settlement_terms', 200)->nullable();
            $table->unsignedBigInteger('lc_cash_cover_account_id')->nullable();
        });
    }
};
