<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── Currency ──────────────────────────────────────────────
            $table->foreignId('currency_id')
                  ->constrained('currencies')
                  ->cascadeOnDelete();

            // ── Rate ──────────────────────────────────────────────────
            // How many units of base currency = 1 unit of this currency
            // e.g. if base is EGP and currency is USD: rate = 49.50
            // Stored to 6dp per bible Rule 6.2
            $table->decimal('rate', 15, 6);

            // ── Date ──────────────────────────────────────────────────
            // One rate per currency per day per company
            $table->date('rate_date');

            // ── Source ────────────────────────────────────────────────
            // manual (Phase 1) | cbe_api (Phase 2)
            $table->string('source', 20)->default('manual');

            // ── Audit ─────────────────────────────────────────────────
            $table->foreignId('created_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            // ── Constraints ───────────────────────────────────────────
            // One rate per currency per day per company
            $table->unique(['company_id', 'currency_id', 'rate_date']);

            // ── Indexes ───────────────────────────────────────────────
            $table->index(['company_id', 'currency_id', 'rate_date']);
            $table->index(['company_id', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
