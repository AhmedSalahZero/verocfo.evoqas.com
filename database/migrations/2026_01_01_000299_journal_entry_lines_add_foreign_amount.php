<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000299 — journal lines remember their foreign amount
//
//  AUDIT H12 + H15 (Sept 2026). Every journal line is in the main
//  (functional) currency, EGP. For an account that holds ONE foreign
//  currency — a USD bank account, a EUR loan, a USD lease liability —
//  the ledger could say "EGP 5,000,000" but nobody could say "that is
//  USD 100,000". Without that:
//    • the treasury dashboards showed EGP figures under USD headings
//      (H15), and
//    • no month-end revaluation was possible, because revaluation is
//      "foreign balance × month-end rate − EGP book balance" (H12).
//
//  Three new, nullable columns, filled automatically by
//  JournalEntryService for lines on single-foreign-currency accounts
//  (see ForeignCurrencyLedgerService for how an account's currency is
//  known). Every other line leaves them NULL — nothing else changes.
//
//    currency_id      the account's foreign currency
//    amount_currency  signed foreign amount: + debit, − credit.
//                     NULL = not known (no rate on file when posted).
//    exchange_rate    the rate used (foreign → functional)
//
//  Lines posted BEFORE this migration are filled by
//  `php artisan fx:backfill-foreign-amounts` (see that command).
//
//  @layer    Migration
//  @depends  journal_entry_lines, currencies
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->foreignId('currency_id')->nullable()->after('credit')->constrained('currencies')->nullOnDelete();
            $table->decimal('amount_currency', 18, 2)->nullable()->after('currency_id');
            $table->decimal('exchange_rate', 15, 6)->nullable()->after('amount_currency');

            $table->index(['company_id', 'chart_of_account_id', 'currency_id'], 'jel_company_account_currency_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex('jel_company_account_currency_idx');
            $table->dropConstrainedForeignId('currency_id');
            $table->dropColumn(['amount_currency', 'exchange_rate']);
        });
    }
};
