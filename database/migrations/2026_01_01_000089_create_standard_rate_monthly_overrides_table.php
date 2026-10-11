<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000089_create_standard_rate_monthly_overrides_table
//
//  Mass Production — Layer 2, piece 3 of 5 (continued). Monthly
//  override rows for standard_rates — kept as its own table rather
//  than columns on standard_rates so recording "March was different"
//  never means editing the annual rate itself. Absence of a row for
//  a given month means "use the annual rate," so most fiscal
//  years/pools will have zero override rows, some months.
//
//  @layer    Migration
//  @depends  standard_rates
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('standard_rate_monthly_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('standard_rate_id')->constrained('standard_rates')->cascadeOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month'); // 1–12, validated in the Request/Service

            $table->decimal('rate', 15, 4);
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['standard_rate_id', 'year', 'month'], 'sro_rate_year_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('standard_rate_monthly_overrides');
    }
};
