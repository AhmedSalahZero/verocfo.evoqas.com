<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            // ── Identity ──────────────────────────────────────────────
            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('tax_registration_number')->nullable();

            // ── Classification ────────────────────────────────────────
            // industry: Trading | Manufacturing | Construction | Retail
            //           Services | Hospitality
            $table->string('industry', 50)->nullable();

            // company_type drives which modules are available
            // Trading | Manufacturing | Services | Mixed | System Integrator
            $table->string('company_type', 30);

            // ── Financial Settings ────────────────────────────────────
            // base_currency: ISO 4217 currency code — default EGP
            $table->string('base_currency', 3)->default('EGP');

            // fiscal_year_start_month: 1 = January ... 12 = December
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(1);

            // ── Locale & Contact ──────────────────────────────────────
            $table->string('country', 50)->default('Egypt');
            $table->string('timezone', 50)->default('Africa/Cairo');
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();

            // ── Multi-Entity (Group Structure) ────────────────────────
            // Self-referencing — null means this is a standalone or parent company
            $table->foreignId('parent_company_id')
                  ->nullable()
                  ->constrained('companies')
                  ->nullOnDelete();

            // ── Subscription ──────────────────────────────────────────
            // tier: Starter | Growth | Pro | Enterprise
            // Base user limits per tier (enforced in SubscriptionService):
            //   Starter=3 | Growth=5 | Pro=10 | Enterprise=20
            $table->string('subscription_tier', 20)->default('Starter');

            // Additional purchased seats on top of the tier base limit
            $table->unsignedSmallInteger('additional_users')->default(0);

            // When the subscription expires — null means no expiry set yet
            $table->timestamp('subscription_expires_at')->nullable();

            // ── Access Control ────────────────────────────────────────
            // Deactivated companies lose all access — data fully preserved
            $table->boolean('is_active')->default(true);

            // ── Audit ─────────────────────────────────────────────────
            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────
            $table->index(['is_active']);
            $table->index(['subscription_tier', 'is_active']);
            $table->index(['parent_company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
