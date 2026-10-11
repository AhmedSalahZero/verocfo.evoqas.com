<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration: alter_companies_add_sector_and_legal_structure
//
//  Two changes to the companies table:
//
//  1. Remove  industry (free text) — replaced by business_sector_id FK
//  2. Add     business_sector_id  — FK to platform-level business_sectors
//  3. Add     legal_structure     — closed enum: JSC, LLC, Sole, etc.
//
//  business_sector_id is nullable — existing companies are not
//  forced to have a sector assigned immediately.
//
//  Runs after: 2026_01_01_000012_create_business_sectors_table
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {

            // Remove old free-text industry column
            $table->dropColumn('industry');

            // Add business sector FK
            $table->foreignId('business_sector_id')
                  ->nullable()
                  ->after('company_type')
                  ->constrained('business_sectors')
                  ->nullOnDelete();

            // Add legal structure
            // Joint Stock | LLC | Sole Proprietorship | Partnership
            // Limited Partnership | Branch of Foreign Company
            // Holding Company | Free Zone Company
            $table->string('legal_structure', 50)
                  ->nullable()
                  ->after('business_sector_id');

            $table->index(['business_sector_id']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['business_sector_id']);
            $table->dropIndex(['business_sector_id']);
            $table->dropColumn(['business_sector_id', 'legal_structure']);
            $table->string('industry', 50)->nullable()->after('company_type');
        });
    }
};
