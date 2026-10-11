<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  create_internal_money_transfers_table
//
//  Moves cash between the company's own accounts — Bank or Safe (a
//  131xxx Chart of Accounts sub-account, per Mahmoud's confirmation;
//  no separate Safe/Branch entity) on either side, four leg
//  combinations. See InternalMoneyTransferService header for the
//  full posting design (instant vs. in-transit via COA 145).
//
//  Functional pattern re-architected from CashVero's own
//  internal_money_transfers table (see CashVero → Vero CFO Treasury
//  Gap Analysis, Section 1.10) — re-expressed against Vero CFO's
//  service-first, bcmath, company-scoped, journal-posting standard.
//  Nothing here is a direct schema copy: no Odoo sync columns, no
//  Branch FK (Safe = a COA account, not a physical location), and a
//  real journal_entry_id/status lifecycle CashVero never had.
//
//  @layer    Migration
//  @depends  companies, chart_of_accounts, bank_accounts, currencies,
//            journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_money_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('transfer_code', 30);

            // Each leg is Bank OR Safe — exactly one of the pair is
            // set, enforced in InternalMoneyTransferService, not here.
            $table->string('from_leg_type');   // 'bank' | 'safe'
            $table->foreignId('from_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('from_safe_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();

            $table->string('to_leg_type');     // 'bank' | 'safe'
            $table->foreignId('to_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('to_safe_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();

            $table->foreignId('currency_id')->constrained();
            $table->decimal('amount', 18, 2);
            $table->decimal('amount_functional', 18, 2);
            $table->decimal('exchange_rate', 20, 10)->default(1);

            $table->date('transfer_date');
            $table->unsignedInteger('transfer_days')->default(0);
            $table->date('expected_clearing_date')->nullable();
            $table->date('cleared_date')->nullable();

            // draft never happens here (record() posts immediately,
            // same "one atomic action" shape as Customer/Supplier
            // Payment) — only in_transit/cleared/cancelled apply.
            $table->string('status')->default('cleared');

            // Instant transfer: one entry, this column only.
            // In-transit: this is the INITIATE entry (Dr 145/Cr
            // source); clearing_journal_entry_id below is the
            // CONFIRM entry (Dr destination/Cr 145) — see service.
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('clearing_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->text('comment')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'transfer_code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'transfer_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_money_transfers');
    }
};
