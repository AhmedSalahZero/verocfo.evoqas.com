<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000038_create_projects_table
//
//  Projects / Contracts master data.
//  Optional top-level entity — Sales Orders can exist without a parent Project.
//  On creation, a project_site Inventory Location is auto-created (in service).
//  On close, the project_site location is auto-deactivated.
//
//  Contract code format: CC/[CU][PR]/YYYY/MM/0001
//
//  @layer    Migration
//  @depends  companies, partners, currencies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('contract_code', 40)->unique();
            $table->string('name_en', 200);
            $table->string('name_ar', 200)->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('partner_id');              // Customer
            $table->decimal('contract_value', 15, 2)->default(0);
            $table->unsignedBigInteger('currency_id');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['active', 'on_hold', 'closed', 'cancelled'])->default('active');
            $table->unsignedBigInteger('project_site_location_id')->nullable(); // auto-created
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['company_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'end_date']);
            $table->index(['company_id', 'contract_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
