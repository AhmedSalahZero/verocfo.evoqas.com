<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000116_alter_bank_accounts_add_td_lifecycle_fields
//
//  Time Deposit Lifecycle (July 2026, confirmed with Mahmoud):
//
//  1. td_entry_type ('new' / 'existing') — same principle as the
//     Leasing Amortization Engine's entry_type (migration 000075/109):
//     a 'new' TD is being opened today, so a real opening entry
//     posts, moving cash out of a real funding account into the TD.
//     An 'existing' TD predates Vero CFO — the user just records
//     where it stands today, same as Opening Balances elsewhere; no
//     opening entry posts. Nullable — only meaningful for
//     account_type = 'time_deposit'.
//
//  2. td_funding_bank_account_id — the Current account a 'new' TD's
//     cash is deducted from at opening. Self-referencing FK onto
//     this same table. Nullable — only used for 'new' TDs; an
//     'existing' TD has no funding account to record, and non-TD
//     accounts don't use this field at all.
//
//  3. td_auto_renewal DROPPED. Replaced by a proper Renew action
//     (RenewTd) which keeps its own event history — a static "will
//     this renew" flag added nothing once every renewal is itself a
//     tracked, journaled event. See create_bank_account_td_events.
//
//  4. Composite unique index (company_id, bank_id, account_number).
//     Confirmed requirement: the same account number must never
//     exist twice under the same bank for a company (e.g. two
//     "current accounts" both numbered 102625 at CIB) — this is
//     exactly what the future Treasury report (per bank, per
//     account) depends on to key rows reliably. NULL account
//     numbers are exempt by ordinary SQL unique-index behavior (NULL
//     is never considered equal to NULL), so accounts without a
//     number on file don't collide with each other.
//
//  @layer    Migration
//  @depends  bank_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->enum('td_entry_type', ['new', 'existing'])->nullable()->after('account_type');
            $table->unsignedBigInteger('td_funding_bank_account_id')->nullable()->after('td_entry_type');

            $table->foreign('td_funding_bank_account_id')
                ->references('id')->on('bank_accounts')
                ->nullOnDelete();
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropColumn('td_auto_renewal');
        });

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->unique(['company_id', 'bank_id', 'account_number'], 'bank_accounts_company_bank_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->dropUnique('bank_accounts_company_bank_number_unique');
            $table->dropForeign(['td_funding_bank_account_id']);
            $table->dropColumn(['td_entry_type', 'td_funding_bank_account_id']);
            $table->boolean('td_auto_renewal')->default(false);
        });
    }
};
