<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000302 — corporate income tax entries
//
//  AUDIT H19 (Sept 2026), confirmed with Mahmoud:
//    • The tax owed is a LIABILITY — 226003 Income Tax Payable.
//    • It is settled first against the Debit WHT that customers
//      deducted during the year (142 — an advance payment of the same
//      tax), and only the balance is paid in cash (Other Payment).
//    • The year's tax charge appears ONCE in the income statement, on
//      its own line under Profit Before Tax (591 / 592 — IAS 12), not
//      inside the six expense categories.
//
//  One row per event, never edited — a mistake is cancelled (its
//  journal entry reversed) and entered again:
//    entry_type 'provision'  Dr 591 Current Income Tax / Cr 226003
//                            (+ optional deferred tax: Dr 592 / Cr 215
//                             or Dr 124 / Cr 592)
//    entry_type 'wht_offset' Dr 226003 / Cr 142
//
//  @layer    Migration
//  @depends  companies, fiscal_years, journal_entries, users
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_tax_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->nullable()->constrained('fiscal_years')->nullOnDelete();
            $table->enum('entry_type', ['provision', 'wht_offset']);
            $table->date('entry_date');
            $table->decimal('current_tax', 15, 2)->default(0);   // provision: 591 ↔ 226003; offset: amount offset
            $table->decimal('deferred_tax', 15, 2)->default(0);  // provision only: + expense (Cr 215) / − income (Dr 124)
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_tax_entries');
    }
};
