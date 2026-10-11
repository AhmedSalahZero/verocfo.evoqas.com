<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000071_create_facility_lc_types_table
//
//  The rate card for a Letter of Credit facility (agreement) —
//  same pattern as facility_lg_types, one facility → many LC TYPES
//  (Sight LC / Deferred / Cash Against Document / custom), each
//  with its own cash cover %, commission rate, min commission fee,
//  and issuance fee.
//
//  Deliberately has NO commission_interval column — confirmed with
//  Mahmoud this is genuinely absent from the LC rate card (unlike
//  LG's periodic billing), not an oversight.
//
//  @layer    Migration
//  @depends  credit_facilities, companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_lc_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();

            $table->string('lc_type', 150); // e.g. "Sight LC", "Deferred", "Cash Against Document", or custom
            $table->decimal('cash_cover_pct', 5, 2)->default(0);
            $table->decimal('commission_rate_pct', 6, 4)->default(0);
            $table->decimal('min_commission_fee_amount', 15, 2)->default(0);
            $table->decimal('issuance_fee_amount', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['credit_facility_id', 'lc_type'], 'lc_types_facility_type_unique');
            $table->index(['company_id', 'credit_facility_id'], 'lc_types_company_facility_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_lc_types');
    }
};
