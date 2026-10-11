<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000282_create_expense_claim_lines_table
//
//  Expense Claims, part 2 of 2. One receipt on a claim.
//
//  A claim is multi-line for the same reason Cash Expense Payment
//  became multi-line in migration 000181: one trip produces a taxi
//  receipt, a hotel receipt and a meal receipt, and they do not all
//  belong to the same expense account. One claim, one employee, one
//  approval — several receipts underneath.
//
//  expense_account_id IS CHOSEN PER LINE, NOT DERIVED. This follows
//  the precedent migration 000234 set explicitly for employee
//  reimbursements: "Deliberately NO default account (confirmed with
//  Mahmoud) ... Reimbursement has no fixed account at all. The user
//  picks whichever expense account they've set up in the Chart of
//  Accounts each time." Accounts 522/532/543 (Employee
//  Reimbursements — Sales / Marketing / G&A, seeded by migration
//  000225) are the natural picks and are what the account picker
//  surfaces first, but nothing here forces them.
//
//  expense_date is the date on the RECEIPT, which is not the claim
//  date and not the approval date. It is recorded because an
//  expense policy question ("this receipt is four months old") can
//  only be asked if the date is kept. It does NOT drive the journal
//  entry — the accrual is dated by approval, when the obligation
//  actually arises.
//
//  cost_center_id mirrors every other expense line in this system
//  (journal_entry_lines carries one, so the analysis holds up).
//
//  amount is in the CLAIM's currency; the functional figure is
//  computed at posting time from the claim's exchange_rate, exactly
//  as Cash Expense Payment lines do. Storing a per-line functional
//  amount as well would let the two drift apart.
//
//  @layer    Migration
//  @depends  companies, expense_claims, chart_of_accounts, cost_centers
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_claim_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_claim_id')->constrained()->cascadeOnDelete();

            // Picked by the user, never derived — see header.
            $table->foreignId('expense_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();

            $table->date('expense_date');
            $table->string('description');
            $table->decimal('amount', 15, 2);

            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'expense_claim_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claim_lines');
    }
};
