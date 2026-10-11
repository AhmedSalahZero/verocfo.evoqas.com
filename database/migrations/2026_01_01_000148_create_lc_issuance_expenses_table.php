<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000148_create_lc_issuance_expenses_table
//
//  Miscellaneous expenses against a live LC (freight, courier, bank
//  admin charges, etc.) — ported from CashVero's LcIssuanceExpense.
//  Deliberately its own table, NOT folded into lc_issuance_history:
//  unlike mark_paid/settlement, expenses are NOT part of the
//  most-recent-first undo chain — they can be added, edited, or
//  deleted independently at any time (confirmed shape, matching
//  CashVero's applyExpense()/updateExpense()/deleteExpense()), so
//  they need their own simple reversible-posting lifecycle instead
//  of a layered one.
//
//  Each row posts its own real journal entry
//  (Dr expense_account_id / Cr paid_from_account_id) the moment it's
//  created. Editing reverses the old entry and posts a new one;
//  deleting just reverses — no "no hard deletes" exception needed
//  here the way LG/LC issuance rows get one, since an expense row
//  itself carries no separate "draft" concept to peel back to; the
//  reversal IS the undo.
//
//  @layer    Migration
//  @depends  letter_of_credit_issuances, chart_of_accounts,
//            bank_accounts, currencies, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lc_issuance_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('lc_issuance_id')->constrained('letter_of_credit_issuances')->cascadeOnDelete();

            $table->string('expense_name', 200);
            $table->date('expense_date');
            $table->decimal('amount', 15, 2);
            $table->foreignId('currency_id')->constrained('currencies');
            $table->decimal('exchange_rate', 15, 6)->default(1);

            $table->foreignId('expense_account_id')->constrained('chart_of_accounts'); // user-picked, same "no fixed account" rule as LG liquidation
            $table->foreignId('paid_from_account_id')->constrained('bank_accounts');

            $table->enum('status', ['posted', 'reversed'])->default('posted');
            $table->unsignedBigInteger('journal_entry_id')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->index(['company_id', 'lc_issuance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lc_issuance_expenses');
    }
};
