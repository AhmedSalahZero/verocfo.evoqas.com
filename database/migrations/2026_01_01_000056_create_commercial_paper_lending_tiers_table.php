<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000056_create_commercial_paper_lending_tiers_table
//
//  The rate card for OD Against Commercial Paper facilities.
//  Each row says: "a cheque due within N days gets lent at X%
//  of its face value." Set once at contract setup (or edited
//  later), used to price each cheque as it's deposited.
//
//  Example — a facility with three tiers:
//    due_within_days = 30, lending_rate_pct = 80
//    due_within_days = 60, lending_rate_pct = 70
//    due_within_days = 90, lending_rate_pct = 50
//
//  This table holds the PRICING RULES only. It does NOT track
//  actual cheques deposited for collection — that's a separate,
//  later build (the money-transaction / cheque tracking feature).
//  Matching a real cheque to a tier means finding the smallest
//  due_within_days that is >= the cheque's actual days-to-due —
//  not built yet, just noting it for when that layer is added.
//
//  One rate per day-bracket per facility — a facility can't have
//  two different rates both meaning "due within 30 days."
//
//  @layer    Migration
//  @depends  credit_facilities, companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_paper_lending_tiers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            $table->foreignId('credit_facility_id')
                  ->constrained('credit_facilities')
                  ->cascadeOnDelete();

            $table->unsignedInteger('due_within_days');     // e.g. 30, 60, 90
            $table->decimal('lending_rate_pct', 5, 2);       // e.g. 80.00

            $table->timestamps();

            $table->unique(['credit_facility_id', 'due_within_days'], 'cp_lending_tiers_facility_days_unique');
            $table->index(['company_id', 'credit_facility_id'], 'cp_lending_tiers_company_facility_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_paper_lending_tiers');
    }
};