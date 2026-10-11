<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000041_create_banks_table
//
//  Bank master data — one record per bank per company.
//  Multiple bank accounts per bank (bank_accounts table).
//  Multiple credit facilities per bank (credit_facilities table).
//
//  @layer    Migration
//  @depends  companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('name_en', 200);
            $table->string('name_ar', 200)->nullable();
            $table->string('branch_name', 200)->nullable();
            $table->string('branch_address', 500)->nullable();
            $table->string('contact_person', 150)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('swift_code', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();

            $table->index(['company_id']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void { Schema::dropIfExists('banks'); }
};
