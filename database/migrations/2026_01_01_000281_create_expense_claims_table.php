<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000281_create_expense_claims_table
//
//  Expense Claims, part 1 of 2. An employee spends their own money on
//  the company's behalf, claims it back, someone approves it, and the
//  company pays them.
//
//  ══ WHY THIS EXISTS WHEN REIMBURSEMENT ALREADY DID ══
//  Migration 000234 added 'reimbursement' to
//  other_payments.employee_advance_type, so paying an employee back
//  was ALREADY possible. What was missing is everything before the
//  payment: there was no claim, no submission, no approval, no
//  liability recognised between "the employee spent it" and "we paid
//  them", and no record of a claim that was refused.
//
//  This module is that missing front half. It deliberately does NOT
//  replace or modify Other Payment — that path stays exactly as it
//  is for the case where someone is simply handed their money back
//  with no formal claim behind it.
//
//  ══ THE ACCOUNTING, AND WHEN IT HAPPENS ══
//  Nothing posts while a claim is a draft or merely submitted — an
//  unapproved claim is not yet the company's obligation.
//
//  On APPROVE (the claim becomes a real liability):
//      DR  each line's own expense account        line amount
//      CR  232 Other Creditors                    claim total
//
//  On MARK AS PAID (the money actually leaves):
//      DR  232 Other Creditors                    claim total
//      CR  the bank account's own COA account, or the cash safe
//
//  Two entries, not one, because approval and payment are genuinely
//  different events that routinely fall in different months — the
//  same reason Goods Receipt Note and Supplier Bill are two entries
//  either side of GRNI (233) rather than one.
//
//  ══ WHY 232 OTHER CREDITORS ══
//  It is a postable liability account that already exists in the
//  shared Chart of Accounts, and an approved-but-unpaid claim is
//  precisely a non-trade amount owed to someone who is not a
//  supplier. The alternatives were considered and rejected:
//  221001 is AP — Local Suppliers, and an employee is not a
//  supplier; 228 Accrued Expenses is for costs incurred but not yet
//  billed, whereas an approved claim IS billed and agreed. 147/148
//  are ASSET accounts for money advanced TO an employee — the
//  opposite direction to what a claim represents.
//  Recorded as a decision to confirm with the business owner, not as
//  a settled fact.
//
//  ══ WHAT THIS DELIBERATELY DOES NOT DO ══
//  No VAT and no withholding tax. Cash Expense Payment (000177) and
//  Supplier Bill both handle them, so the pattern exists — but the
//  tax treatment of an employee's own receipt is a real tax question
//  (is the company the recipient of the supply?) that nothing in
//  this repository answers. Inventing it would be inventing a
//  business rule. Amounts here are gross, single-figure, and the
//  gap is flagged rather than guessed at.
//
//  @layer    Migration
//  @depends  companies, partners, currencies, chart_of_accounts,
//            bank_accounts, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->string('claim_code', 30);

            // The employee claiming. An employee-type Partner, checked
            // by the service — a claim from a customer is meaningless.
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();

            $table->date('claim_date');
            $table->string('title')->nullable();
            $table->text('description')->nullable();

            // Same currency treatment every other document in this
            // system uses: the claim is entered in its own currency,
            // and amount_functional is what reaches the ledger.
            $table->foreignId('currency_id')->constrained()->restrictOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1);

            // Aggregate of expense_claim_lines, recalculated on every
            // save — for list-page display and for the journal total.
            // The real detail lives on the lines.
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('amount_functional', 15, 2)->default(0);

            $table->enum('status', [
                'draft', 'submitted', 'approved', 'rejected', 'cancelled', 'paid',
            ])->default('draft');

            // ── Approval trail ────────────────────────────────────
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();

            // ── Accrual entry, raised on approve ──────────────────
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();

            // ── Payment, raised on mark-as-paid ───────────────────
            $table->date('payment_date')->nullable();
            $table->enum('payment_source_type', ['bank_account', 'cash_safe'])->nullable();
            $table->foreignId('payment_bank_account_id')->nullable()
                ->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('payment_safe_account_id')->nullable()
                ->constrained('chart_of_accounts')->nullOnDelete();
            $table->string('payment_reference')->nullable();
            $table->foreignId('payment_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'claim_code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claims');
    }
};
