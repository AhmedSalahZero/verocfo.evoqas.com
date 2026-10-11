<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000289_create_mtl_movements_table
//
//  TRS-002 fix. A Medium-Term Loan recorded its facility, its
//  repayment schedule and every settlement correctly, and posted
//  NOTHING to the general ledger at either end — both posting points
//  were ACCOUNTING ENGINE TODO comments. A 1,200,000 loan could be
//  drawn and partially repaid without the bank balance moving, without
//  a liability being recorded, and without an interest expense entry.
//
//  Deliberately the same shape as lg_issuance_movements, because the
//  TODO said so and because it is the right shape: a movement row per
//  accounting event, carrying its own two legs, its own status, and a
//  link to the journal entry that posted it. That gives the loan an
//  auditable movement history independent of the ledger — the same
//  thing the LG module has — rather than journal entries whose only
//  connection back to the loan is a source_id.
//
//  A leg is either a COA account or a bank account; the bank account
//  is resolved to its COA link at posting time, exactly as LG does.
//
//  @layer    Migration
//  @depends  credit_facilities, mtl_schedule_lines, mtl_settlements,
//            chart_of_accounts, bank_accounts, currencies, journal_entries
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mtl_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('credit_facility_id')->constrained('credit_facilities')->cascadeOnDelete();

            // Set on settlement movements; null on the initial drawdown.
            $table->foreignId('mtl_schedule_line_id')->nullable()
                  ->constrained('mtl_schedule_lines')->nullOnDelete();
            $table->foreignId('mtl_settlement_id')->nullable()
                  ->constrained('mtl_settlements')->nullOnDelete();

            $table->enum('movement_type', [
                'drawdown',              // Dr bank / Cr loan liability
                'principal_repayment',   // Dr loan liability / Cr bank
                'interest_expense',      // Dr interest expense / Cr bank
            ]);

            $table->date('movement_date');

            $table->foreignId('debit_coa_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('credit_coa_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
            $table->foreignId('debit_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('credit_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            $table->decimal('amount', 15, 2);
            $table->foreignId('currency_id')->constrained('currencies');
            $table->string('description', 300);

            $table->enum('status', ['pending_gl_posting', 'posted', 'reversed', 'memo_only'])
                  ->default('pending_gl_posting');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_journal_entry_id')->nullable()
                  ->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'credit_facility_id']);
            $table->index(['company_id', 'movement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mtl_movements');
    }
};
