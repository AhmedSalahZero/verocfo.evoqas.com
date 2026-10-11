<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000064_create_facility_lg_types_table
//
//  The rate card for a Letter of Guarantee facility (agreement).
//  A single LG agreement with a bank covers several LG TYPES
//  (Bid Bond, Final LG, Advance Payment LG, Performance LG, or a
//  custom type the user adds) — each with its own cash cover %,
//  commission rate, commission billing interval, minimum
//  commission fee floor, and one-time issuance fee.
//
//  Example — one facility, four rows:
//    Bid Bond            → 5%  cash cover, 0.30% commission, quarterly
//    Final LG             → 10% cash cover, 0.60% commission, quarterly
//    Advanced Payment LG  → 10% cash cover, 0.60% commission, quarterly
//    Performance LG       → 10% cash cover, 0.60% commission, quarterly
//
//  This table holds PRICING RULES only — it does NOT represent an
//  actual issued guarantee (beneficiary, amount, expiry). Individual
//  LG issuances are a separate, later table (one facility → many
//  issuances), designed when that layer is built. See CreditFacility
//  model header for the columns already reserved for that layer.
//
//  lg_type is intentionally a free string, not an enum — the four
//  types above are seeded as UI defaults, but the person can add
//  custom rows (confirmed business rule).
//
//  One rate-card row per LG type per facility — a facility can't
//  define "Bid Bond" twice with two different rates.
//
//  @layer    Migration
//  @depends  credit_facilities, companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_lg_types', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            $table->foreignId('credit_facility_id')
                  ->constrained('credit_facilities')
                  ->cascadeOnDelete();

            $table->string('lg_type', 150);                          // e.g. "Bid Bond", "Final LG", or custom
            $table->decimal('cash_cover_pct', 5, 2)->default(0);      // % of LG amount held as cash cover
            $table->decimal('commission_rate_pct', 6, 4)->default(0); // % commission, billed per interval
            $table->enum('commission_interval', [
                'monthly', 'quarterly', 'semi_annual', 'annual',
            ])->default('quarterly');
            $table->decimal('min_commission_fee_amount', 15, 2)->default(0); // floor if calculated commission is lower
            $table->decimal('issuance_fee_amount', 15, 2)->default(0);       // one-time fee charged at issuance
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['credit_facility_id', 'lg_type'], 'lg_types_facility_type_unique');
            $table->index(['company_id', 'credit_facility_id'], 'lg_types_company_facility_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_lg_types');
    }
};
