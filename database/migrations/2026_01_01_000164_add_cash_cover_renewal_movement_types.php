<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000164_add_cash_cover_renewal_movement_types
//
//  Two new movement_type values for the LG Issuance Renewal cash
//  cover rate change (see 000163 header for the business rule):
//
//    - cash_cover_renewal_increase   Dr 138001 / Cr Bank — the
//                                    incremental amount only
//    - cash_cover_renewal_decrease   Dr Bank / Cr 138001 — the
//                                    incremental amount only
//
//  Kept as two distinct values (rather than one generic
//  "cash_cover_renewal_adjustment") so the movements ledger and any
//  future report can tell direction apart at a glance, matching the
//  existing pattern of separate cash_cover_release_cancel /
//  cash_cover_release_liquidate values rather than one shared type.
//
//  MySQL requires a raw MODIFY for enum changes (no doctrine/dbal in
//  this project, same as 000144) — existing rows are untouched, only
//  the allowed value set grows.
//
//  @layer    Migration
//  @depends  lg_issuance_movements
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE lg_issuance_movements MODIFY movement_type ENUM(
            'cash_cover_placement',
            'contingent_liability',
            'commission_accrual',
            'issuance_fee',
            'renewal_fee',
            'cash_cover_renewal_increase',
            'cash_cover_renewal_decrease',
            'cash_cover_release_cancel',
            'cash_cover_release_liquidate',
            'liquidation_shortfall',
            'decrease_adjustment'
        ) NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE lg_issuance_movements MODIFY movement_type ENUM(
            'cash_cover_placement',
            'contingent_liability',
            'commission_accrual',
            'issuance_fee',
            'renewal_fee',
            'cash_cover_release_cancel',
            'cash_cover_release_liquidate',
            'liquidation_shortfall',
            'decrease_adjustment'
        ) NOT NULL");
    }
};
