<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000031_create_units_of_measure_table
//
//  Master list of units of measure.
//  System UOMs: company_id = NULL — available to all companies.
//  Custom UOMs: company_id = SET — company-specific additions.
//
//  Types: weight / volume / count / time / area / length
//
//  @layer    Migration
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name_en', 100);
            $table->string('name_ar', 100)->nullable();
            $table->string('type', 20)->default('count'); // weight/volume/count/time/area/length
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units_of_measure');
    }
};
