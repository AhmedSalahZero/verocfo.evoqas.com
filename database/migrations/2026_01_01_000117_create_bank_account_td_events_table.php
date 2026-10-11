<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000117_create_bank_account_td_events_table
//
//  Time Deposit Lifecycle (July 2026, confirmed with Mahmoud). One
//  row per lifecycle event on a Time Deposit account — the single
//  history table behind all four TD actions:
//
//    'interest' — periodic interest applied while the TD stays open
//                 (some banks pay every 2 weeks — a 3-month TD can
//                 have 6 of these rows). Principal untouched.
//    'break'    — early closure. Full principal + accumulated
//                 interest paid out, TD account deactivated.
//    'maturity' — closure on schedule. Same shape as 'break', just
//                 triggered at/after td_maturity_date instead of
//                 before it.
//    'renewal'  — TD stays open past its maturity date. The interest
//                 for the period just completed is realized and paid
//                 out (same as 'interest'), and the TD's own
//                 td_maturity_date / td_interest_rate move forward to
//                 the new term. Principal (td_amount) is untouched.
//
//  principal_amount is only ever set for 'break'/'maturity' — both
//  always close the full TD (confirmed: no partial break), so this
//  is really just a record of what td_amount was at closure, not a
//  user-editable figure.
//
//  old_maturity_date / new_maturity_date and old_interest_rate /
//  new_interest_rate are only set for 'renewal'.
//
//  Every event carries its own credit_bank_account_id (Rule 31 —
//  Facility Transaction Bank Account Linkage: never inferred only
//  through a parent record) and its own journal_entry_id, so every
//  row here is independently traceable to exactly one posted entry.
//
//  @layer    Migration
//  @depends  bank_accounts, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_account_td_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                  ->constrained('companies')
                  ->cascadeOnDelete();

            $table->foreignId('bank_account_id')
                  ->constrained('bank_accounts')
                  ->cascadeOnDelete();

            $table->enum('event_type', ['interest', 'break', 'maturity', 'renewal']);
            $table->date('event_date');

            // interest / break / maturity / renewal all carry an
            // interest_amount — the one thing every event type shares.
            $table->decimal('interest_amount', 15, 2)->default(0);

            // break / maturity only — the principal returned. Always
            // the TD's full td_amount at the moment of closure (no
            // partial break), kept here as a permanent record even
            // though td_amount itself will no longer be relevant once
            // the account is deactivated.
            $table->decimal('principal_amount', 15, 2)->nullable();

            // renewal only
            $table->date('old_maturity_date')->nullable();
            $table->date('new_maturity_date')->nullable();
            $table->decimal('old_interest_rate', 8, 4)->nullable();
            $table->decimal('new_interest_rate', 8, 4)->nullable();

            // Rule 31 — every facility transaction row carries its own
            // bank account, never inferred only through the parent.
            $table->foreignId('credit_bank_account_id')
                  ->constrained('bank_accounts')
                  ->restrictOnDelete();

            $table->foreignId('journal_entry_id')
                  ->nullable()
                  ->constrained('journal_entries')
                  ->nullOnDelete();

            $table->foreignId('created_by')
                  ->constrained('users')
                  ->restrictOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'bank_account_id'], 'tdev_company_account');
            $table->index(['company_id', 'bank_account_id', 'event_type'], 'tdev_company_account_type');
            $table->index(['company_id', 'event_date'], 'tdev_company_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_account_td_events');
    }
};
