<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000032_create_product_categories_table
//
//  Company-specific product categories and sub-categories.
//
//  expense_account_id:
//    Required for Consumable categories only.
//    Determines where consumption posts:
//      overhead      → Manufacturing Overhead Pool account
//      period_expense → Direct P&L expense account
//    NULL for non-consumable categories.
//
//  cost_treatment:
//    overhead | period_expense | NULL
//    Only meaningful for consumable categories.
//
//  @layer    Migration
//  @depends  companies, chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            // Self-referencing for sub-categories
            $table->foreignId('parent_id')
                  ->nullable()
                  ->constrained('product_categories')
                  ->nullOnDelete();

            $table->string('name_en', 150);
            $table->string('name_ar', 150)->nullable();

            // 1 = main category, 2 = sub-category
            $table->unsignedTinyInteger('level')->default(1);

            // overhead | period_expense | null
            // Only for consumable categories
            $table->string('cost_treatment', 20)->nullable();

            // Expense account for consumable categories
            // NULL for non-consumable categories
            $table->foreignId('expense_account_id')
                  ->nullable()
                  ->constrained('chart_of_accounts')
                  ->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['company_id', 'level']);
            $table->index(['company_id', 'parent_id']);
            $table->index(['company_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
