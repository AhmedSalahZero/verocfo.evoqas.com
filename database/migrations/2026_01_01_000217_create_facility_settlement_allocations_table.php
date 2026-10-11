<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000217_create_facility_settlement_allocations_table
//
//  Facility Settlement Engine (Building Bible Rule 34) — DETAIL rows.
//  One row per bucket a facility_settlement (migration 000216) paid
//  into. A single settlement can span several rows here — e.g. one
//  EGP 50,000 collection might clear a EGP 2,000 interest charge, a
//  EGP 3,000 HDB fee, and EGP 45,000 across three withdrawals, in
//  that exact priority order (Rule 34.2). This table is what makes
//  reversal (Rule 34.5) and resettlement (Rule 34.4) precise: to
//  undo a settlement, walk its allocation rows and add each amount
//  straight back onto the target charge's/withdrawal's
//  remaining_amount — never a guess, never a recalculation from
//  scratch.
//
//  allocatable_type / allocatable_id — polymorphic by hand (not
//  Eloquent's morphTo) to keep the enum explicit and match this
//  project's existing preference for named enums over generic
//  polymorphism (see source_type columns elsewhere in this module).
//
//  @layer    Migration
//  @depends  facility_settlements, facility_liability_charges,
//            facility_withdrawals
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_settlement_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_settlement_id')->constrained()->cascadeOnDelete();

            $table->enum('allocatable_type', ['facility_liability_charge', 'facility_withdrawal']);
            $table->unsignedBigInteger('allocatable_id');

            $table->decimal('amount', 15, 2); // portion of the settlement that hit this bucket

            $table->timestamps();

            $table->index(['company_id', 'facility_settlement_id'], 'fsa_company_settlement');
            $table->index(['company_id', 'allocatable_type', 'allocatable_id'], 'fsa_company_allocatable');
        });
    }

    public function down(): void { Schema::dropIfExists('facility_settlement_allocations'); }
};
