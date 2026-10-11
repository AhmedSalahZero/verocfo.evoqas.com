<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  Migration: create_business_sectors_table
//
//  Platform-level reference table — no company_id.
//  Shared across all companies, customers, suppliers, and analytics.
//  Managed by Super Admin only via a future settings page.
//
//  Used by:
//    - companies.business_sector_id
//    - partners.business_sector_id (customers & suppliers)
//    - Analytics grouping by sector (future)
//
//  Seeded by: BusinessSectorSeeder
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_sectors', function (Blueprint $table) {
            $table->id();

            $table->string('name_en');
            $table->string('name_ar')->nullable();

            // Display order in dropdowns
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_sectors');
    }
};
