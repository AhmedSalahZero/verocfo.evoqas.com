<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000028_create_company_active_currencies_table
//
//  Tracks which system currencies each company has activated.
//  System currencies (company_id = NULL on currencies table) are
//  available to all companies, but each company activates only
//  the ones it actually uses.
//
//  Why a pivot table instead of duplicating currencies:
//  — System currencies are immutable master data
//  — Company activation is a preference, not a new record
//  — Exchange rates reference currency_id from currencies table
//  — This keeps the currencies table clean and single-source
//
//  The functional currency (is_functional = true) is set once
//  at company creation and cannot be deactivated.
//
//  One rate per currency per day per company is enforced on
//  the exchange_rates table (existing unique constraint).
//
//  @layer    Migration
//  @depends  companies, currencies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_active_currencies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // References the system currency (company_id = NULL on currencies)
            $table->foreignId('currency_id')
                  ->constrained('currencies')
                  ->cascadeOnDelete();

            // TRUE = this is the company's functional currency (EGP for Egyptian co.)
            // Only one per company — enforced in service layer
            $table->boolean('is_functional')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One activation record per currency per company
            $table->unique(['company_id', 'currency_id']);

            // Indexes
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'is_functional']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_active_currencies');
    }
};
