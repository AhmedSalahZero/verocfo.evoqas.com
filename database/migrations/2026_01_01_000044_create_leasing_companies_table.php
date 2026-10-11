<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000044_create_leasing_companies_table
//
//  Leasing company master — FRA-regulated non-bank financial institutions.
//  Interest rates live on each contract, not the master.
//
//  @layer    Migration
//  @depends  companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leasing_companies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name_en', 200);
            $table->string('name_ar', 200)->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['company_id']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void { Schema::dropIfExists('leasing_companies'); }
};
