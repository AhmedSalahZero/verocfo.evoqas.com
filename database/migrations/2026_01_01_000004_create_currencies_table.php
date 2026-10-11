<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── Currency Identity ─────────────────────────────────────
            // ISO 4217 code — e.g. EGP, USD, EUR, SAR
            $table->string('code', 3);
            $table->string('name_en', 50);
            $table->string('name_ar', 50)->nullable();

            // Currency symbol — e.g. £, $, €
            $table->string('symbol', 10)->nullable();

            // ── Base Currency Flag ────────────────────────────────────
            // Only one currency per company can be the base currency
            $table->boolean('is_base')->default(false);

            // ── State ─────────────────────────────────────────────────
            $table->boolean('is_active')->default(true);

            // ── Timestamps ────────────────────────────────────────────
            $table->timestamps();

            // ── Constraints ───────────────────────────────────────────
            // Each currency code is unique per company
            $table->unique(['company_id', 'code']);

            // ── Indexes ───────────────────────────────────────────────
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'is_base']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
