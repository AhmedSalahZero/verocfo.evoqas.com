<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000313 — month-end facility entries, rate true-ups,
//                     lease cheque ↔ schedule link
//
//  AUDIT M21–M25 (Oct 2026).
//
//  facility_period_end_entries
//    One row per month-end entry the system posts for a facility or a
//    lease, together with its automatic reversal on the 1st of the
//    next month (same pattern as the month-end FX revaluation, 000300).
//    kind:
//      od_interest_accrual   M23 — overdraft interest earned but not yet
//                            posted from the bank statement
//      mtl_interest_accrual  M22 — loan interest earned but not yet paid
//      mtl_current_portion   M22 — principal due within 12 months,
//                            moved from Long-Term Loans (211) to
//                            Current Portion of Long-Term Loans (225)
//      lease_current_portion M21 — lease principal due within 12 months,
//                            moved from 213 to 234
//    status: posted | cancelled (a cancelled row has both of its
//    journal entries reversed — rows are never deleted).
//
//  facility_rate_trueups
//    M24 — when a back-dated CBE corridor rate re-prices history, one
//    row per facility records what the theoretical interest was
//    before and after, and how much interest the user had already
//    posted from bank statements for those days. The ledger itself is
//    NOT changed (it follows the bank's real statements); the row is
//    the visible record that the theoretical figure moved.
//
//  lease_cheques.lease_amortization_line_id
//    M21 — each real cheque is linked to the schedule instalment it
//    pays (matched by date), so the two records can be compared.
//
//  @layer    Migration
//  @depends  companies, credit_facilities, leasing_contracts, journal_entries, users
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_period_end_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('credit_facility_id')->nullable()->constrained('credit_facilities')->nullOnDelete();
            $table->foreignId('leasing_contract_id')->nullable()->constrained('leasing_contracts')->nullOnDelete();
            $table->string('kind', 30);
            $table->date('period_end');
            $table->date('reversal_date');
            $table->decimal('amount', 15, 2);                 // in the facility's / lease's own currency
            $table->decimal('amount_functional', 15, 2);      // what was posted to the ledger
            $table->enum('status', ['posted', 'cancelled'])->default('posted');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->json('details')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'kind', 'period_end', 'status'], 'fpe_company_kind_period_idx');
        });

        Schema::create('facility_rate_trueups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();
            $table->foreignId('cbe_corridor_rate_id')->constrained('cbe_corridor_rates')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('old_interest_rate', 8, 4);
            $table->decimal('new_interest_rate', 8, 4);
            $table->decimal('old_theoretical', 15, 6);
            $table->decimal('new_theoretical', 15, 6);
            $table->decimal('difference', 15, 6);
            $table->decimal('posted_interest', 15, 2)->default(0);   // interest already posted for those days
            $table->boolean('affects_posted_months')->default(false);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'credit_facility_id'], 'frt_company_facility_idx');
        });

        Schema::table('lease_cheques', function (Blueprint $table) {
            $table->foreignId('lease_amortization_line_id')->nullable()->after('leasing_contract_id')
                ->constrained('lease_amortization_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lease_cheques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lease_amortization_line_id');
        });
        Schema::dropIfExists('facility_rate_trueups');
        Schema::dropIfExists('facility_period_end_entries');
    }
};
