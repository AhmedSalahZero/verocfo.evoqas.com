<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_contacts', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── Parent Partner ────────────────────────────────────────
            $table->foreignId('partner_id')
                  ->constrained('partners')
                  ->cascadeOnDelete();

            // ── Identity ──────────────────────────────────────────────
            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->string('job_title')->nullable();

            // ── Contact Details ───────────────────────────────────────
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();

            // ── Flags ─────────────────────────────────────────────────
            // Primary contact — the default contact for this partner
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);

            // ── Timestamps ────────────────────────────────────────────
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────
            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_contacts');
    }
};
