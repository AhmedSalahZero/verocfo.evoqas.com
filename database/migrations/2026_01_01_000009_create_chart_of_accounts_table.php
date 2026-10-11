<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();

            // ── Tenant ────────────────────────────────────────────────
            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            // ── Identity ──────────────────────────────────────────────
            $table->string('code', 20);
            $table->string('name_en');
            $table->string('name_ar')->nullable();

            // ── Classification ────────────────────────────────────────
            // Drives P&L and Balance Sheet classification automatically
            // Income Statement: Revenue | Cost of Goods Sold | Sales Expenses
            //                   Marketing Expenses | General & Administrative Expenses
            //                   Finance Expenses | Depreciation & Amortization
            //                   Tax Provision
            // Balance Sheet:    Current Assets | Fixed Assets
            //                   Accumulated Depreciation
            //                   Current Liabilities | Long-term Liabilities | Equity
            $table->string('account_type', 50);

            // Normal balance side — debit | credit
            $table->string('normal_balance', 6);

            // ── Hierarchy ─────────────────────────────────────────────
            // Self-referencing for parent/child account grouping
            $table->foreignId('parent_id')
                  ->nullable()
                  ->constrained('chart_of_accounts')
                  ->nullOnDelete();

            // ── System Account Flag ───────────────────────────────────
            // System accounts are auto-posting targets — protected from deletion
            // or renaming of account_type
            $table->boolean('is_system')->default(false);

            // ── Analytical Dimensions ─────────────────────────────────
            // Whether this account requires a cost center on journal lines
            $table->boolean('requires_cost_center')->default(false);

            // ── Currency ──────────────────────────────────────────────
            // Null means the account uses the company base currency
            $table->foreignId('currency_id')
                  ->nullable()
                  ->constrained('currencies')
                  ->nullOnDelete();

            // ── State ─────────────────────────────────────────────────
            $table->boolean('is_active')->default(true);

            // ── Timestamps ────────────────────────────────────────────
            $table->timestamps();

            // ── Constraints ───────────────────────────────────────────
            // Account code must be unique per company
            $table->unique(['company_id', 'code']);

            // ── Indexes ───────────────────────────────────────────────
            $table->index(['company_id', 'account_type']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'is_system']);
            $table->index(['company_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
