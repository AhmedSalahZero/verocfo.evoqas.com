<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── Identity ──────────────────────────────────────────────
            $table->string('code', 20)->nullable();
            $table->string('name_en');
            $table->string('name_ar')->nullable();

            // ── Type ──────────────────────────────────────────────────
            // customer | supplier | employee | shareholder | sister_company
            $table->string('type', 20);

            // ── Customer Fields ───────────────────────────────────────
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->string('payment_terms')->nullable();      // e.g. Net 30, Net 60
            $table->string('sales_zone')->nullable();
            $table->string('customer_category')->nullable();  // e.g. A, B, C / VIP / Regular

            // ── Supplier Fields ───────────────────────────────────────
            $table->string('expense_category')->nullable();

            // ── Employee Fields ───────────────────────────────────────
            $table->string('department')->nullable();
            $table->string('job_title')->nullable();
            $table->decimal('daily_rate', 15, 2)->nullable();
            $table->decimal('hourly_rate', 15, 2)->nullable();

            // ── Shareholder Fields ────────────────────────────────────
            $table->decimal('shareholding_pct', 5, 2)->nullable();  // e.g. 25.50 = 25.5%
            $table->date('contribution_date')->nullable();

            // ── Sister Company Fields ─────────────────────────────────
            $table->string('relationship_type')->nullable();   // e.g. Subsidiary, Affiliate

            // ── Bank Details (for payments) ───────────────────────────
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('iban')->nullable();
            $table->string('swift_code')->nullable();

            // ── Contact ───────────────────────────────────────────────
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('country', 50)->nullable();
            $table->string('tax_number')->nullable();

            // ── AR/AP Ledger Account ──────────────────────────────────
            // Links this partner to their control account in the COA
            $table->foreignId('ar_account_id')
                  ->nullable()
                  ->constrained('chart_of_accounts')
                  ->nullOnDelete();

            $table->foreignId('ap_account_id')
                  ->nullable()
                  ->constrained('chart_of_accounts')
                  ->nullOnDelete();

            // ── State ─────────────────────────────────────────────────
            $table->boolean('is_active')->default(true);

            // ── Timestamps ────────────────────────────────────────────
            $table->timestamps();

            // ── Constraints ───────────────────────────────────────────
            $table->unique(['company_id', 'code']);

            // ── Indexes ───────────────────────────────────────────────
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};
