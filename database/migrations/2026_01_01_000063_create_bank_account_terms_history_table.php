<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000063_create_bank_account_terms_history_table
//
//  Tracks changes to interest_rate and minimum_balance_for_interest
//  on bank accounts over time. Append-only — records are never
//  updated or deleted. Same pattern as partner_employee_history.
//
//  A new row is inserted by BankService whenever interest_rate or
//  minimum_balance_for_interest changes. The service compares old
//  vs new value and only writes if there is an actual change.
//
//  effective_date: the date the change took effect (defaults to
//  today, can be backdated for historical entry).
//  changed_by: the user_id who made the change — always set.
//
//  @layer    Migration
//  @depends  bank_accounts, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_account_terms_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            $table->foreignId('bank_account_id')
                  ->constrained('bank_accounts')
                  ->cascadeOnDelete();

            // field: 'interest_rate' | 'minimum_balance_for_interest'
            $table->string('field', 40);
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();

            $table->date('effective_date');

            $table->foreignId('changed_by')
                  ->constrained('users')
                  ->restrictOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'bank_account_id'], 'baht_company_account');
            $table->index(['company_id', 'bank_account_id', 'field'], 'baht_company_account_field');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_terms_history');
    }
};
