<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000059_alter_contract_od_lending_limit_to_facility
//
//  Design correction: max_lending_limit_per_contract belongs on the
//  FACILITY, not on each individual assignment row.
//
//  Business rule (confirmed):
//    The bank sets ONE cap that applies to every contract assigned
//    to this OD line. Any single contract's effective funding is:
//      MIN(contract_value × lending_pct / 100,
//          facility.max_lending_limit_per_contract)
//
//  Example:
//    max_lending_limit_per_contract = 700,000
//    Contract A: 1,000,000 × 80% = 800,000 → capped at 700,000
//    Contract B:   500,000 × 80% = 400,000 → under cap, 400,000
//
//  Changes:
//    1. credit_facilities: add max_lending_limit_per_contract
//       (nullable — NULL means no cap, full lending_amount applies)
//    2. facility_contract_assignments: drop max_lending_limit
//       (was per-assignment, now facility-level)
//
//  Note: max_lending_limit_per_customer on credit_facilities (added
//  in migration 000055) is a different field for Commercial Paper
//  facilities — not touched here.
//
//  @layer    Migration
//  @depends  credit_facilities (000043), facility_contract_assignments (000057)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add the cap to the facility — one value governs all assignments
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->decimal('max_lending_limit_per_contract', 15, 2)
                  ->nullable()
                  ->after('max_lending_limit_per_customer')
                  ->comment('OD Against Contract SO: max bank will fund per assigned contract. NULL = no cap.');
        });

        // 2. Remove the per-assignment cap — it was in the wrong place
        Schema::table('facility_contract_assignments', function (Blueprint $table) {
            $table->dropColumn('max_lending_limit');
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropColumn('max_lending_limit_per_contract');
        });

        Schema::table('facility_contract_assignments', function (Blueprint $table) {
            $table->decimal('max_lending_limit', 15, 2)->after('lending_amount');
        });
    }
};
