<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000055_alter_credit_facilities_add_lending_limit
//
//  Adds max_lending_limit_per_customer to credit_facilities —
//  OD Against Commercial Paper specific (facility_type =
//  'commercial_paper'). Some banks cap total exposure against any
//  single customer's cheques, even when the facility overall has
//  room. This is a single contract-level number set at setup —
//  not a per-partner table. Enforcing it against actual deposited
//  cheques is a later build (the money-transaction / cheque
//  tracking feature), once cheques are entered into the system.
//
//  Nullable so other facility types are unaffected — same pattern
//  as the OD fields added in migration 000052.
//
//  @layer    Migration
//  @depends  credit_facilities
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->decimal('max_lending_limit_per_customer', 15, 2)
                  ->nullable()
                  ->after('follows_corridor_rate');
        });
    }

    public function down(): void
    {
        Schema::table('credit_facilities', function (Blueprint $table) {
            $table->dropColumn('max_lending_limit_per_customer');
        });
    }
};
