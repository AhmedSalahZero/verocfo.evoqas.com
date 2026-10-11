<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000022_create_sales_channels_table
//
//  Stores the sales channel master list per company.
//  Used on the Partner (Customer) form and on Sales Orders
//  as a selector — ensuring consistent channel naming across
//  all transactions and analysis reports.
//
//  System channels (company_id = NULL, is_system = TRUE) are
//  seeded by SalesChannelSeeder and available to all companies.
//  Companies can add their own custom channels on top.
//
//  Analysis integrity: by using a master list rather than free
//  text, the Business Analysis module can reliably group and
//  filter by sales channel without deduplication issues.
//
//  @layer    Migration
//  @depends  companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();

            // NULL = system-level (available to all companies)
            // SET  = company-defined custom channel
            $table->foreignId('company_id')
                  ->nullable()
                  ->constrained('companies')
                  ->cascadeOnDelete();

            $table->string('name_en', 100);
            $table->string('name_ar', 100)->nullable();

            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // ── Constraints ───────────────────────────────────────
            $table->unique(['company_id', 'name_en']);

            // ── Indexes ───────────────────────────────────────────
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'is_system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_channels');
    }
};
