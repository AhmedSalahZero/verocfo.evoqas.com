<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000318_journal_integrity_checks_and_period_link   (audit M-38)
//
//  1. Database-level guard on journal_entry_lines: debit ≥ 0, credit ≥ 0
//     and never both on one line. (The application already behaves this
//     way — this makes the DATABASE refuse anything else.) If old data
//     already breaks the rule the constraint is skipped and a warning is
//     logged, so the migration never blocks an upgrade.
//  2. journal_entries.accounting_period_id — every entry points at the
//     accounting period its date falls in (backfilled here; filled by
//     JournalEntryService from now on).
//
//  The foreign-currency amount column the audit also asked for already
//  exists (migration 000299: currency_id / amount_currency / exchange_rate).
//
//  @layer    Migration
//  @depends  journal_entries, journal_entry_lines, accounting_periods
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('accounting_period_id')->nullable()->after('entry_date')
                ->constrained('accounting_periods')->nullOnDelete();
        });

        DB::statement('UPDATE journal_entries je
            JOIN accounting_periods ap ON ap.company_id = je.company_id
                AND je.entry_date BETWEEN ap.start_date AND ap.end_date
            SET je.accounting_period_id = ap.id
            WHERE je.accounting_period_id IS NULL');

        $bad = DB::table('journal_entry_lines')
            ->where(fn ($q) => $q->where('debit', '<', 0)->orWhere('credit', '<', 0)->orWhere(fn ($q2) => $q2->where('debit', '>', 0)->where('credit', '>', 0)))
            ->count();

        if ($bad > 0) {
            Log::warning("M-38: {$bad} existing journal line(s) break the debit/credit rule — database check NOT added. Review them, then add the check manually.");
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE journal_entry_lines
                ADD CONSTRAINT jel_amounts_valid CHECK (debit >= 0 AND credit >= 0 AND NOT (debit > 0 AND credit > 0))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            try { DB::statement('ALTER TABLE journal_entry_lines DROP CHECK jel_amounts_valid'); } catch (\Throwable $e) {}
        }
        Schema::table('journal_entries', fn (Blueprint $t) => $t->dropConstrainedForeignId('accounting_period_id'));
    }
};
