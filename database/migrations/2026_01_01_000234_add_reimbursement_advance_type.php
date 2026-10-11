<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000234_add_reimbursement_advance_type
//
//  Employee Expense Reimbursements (2026-09). Adds 'reimbursement' to
//  other_payments.employee_advance_type (migration 000233).
//
//  Deliberately NOT added to other_collections — same reasoning as
//  per_diem: you don't "collect" a reimbursement back, it's an
//  outright expense.
//
//  Deliberately NO default account (confirmed with Mahmoud,
//  September 2026) — unlike Custody (148) and Advance from Salary
//  (147), which always route to one specific account, Reimbursement
//  has no fixed account at all. The user picks whichever expense
//  account they've set up in the Chart of Accounts each time —
//  OtherPayment::defaultDebitAccountCode() already returns null for
//  any employee_advance_type absent from EMPLOYEE_ADVANCE_ACCOUNT_CODE,
//  so 'reimbursement' needed no code change there, only this schema
//  change plus the model constant/label. Same "no default — user's
//  own judgment" treatment Per Diem already has, and like Per Diem,
//  the account picker searches Income Statement (expense) accounts,
//  not Balance Sheet — see OtherPaymentController::debitAccounts()
//  and the Vue form's debitAccountSection.
//
//  522/532/543 (Employee Reimbursements — Sales/Marketing/G&A, from
//  migration 000225) remain in the Chart of Accounts as suggested
//  starting points the user can pick manually — they are NOT wired
//  as an automatic default here, by design.
//
//  @layer    Migration
//  @depends  other_payments
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's schema builder can't widen an existing MySQL enum
        // in place — same reasoning/approach used anywhere else in
        // this codebase an enum needed a new value added after the
        // fact. Raw ALTER, nullable preserved.
        DB::statement("ALTER TABLE other_payments MODIFY employee_advance_type ENUM('custody', 'advance_salary', 'per_diem', 'reimbursement') NULL");
    }

    public function down(): void
    {
        // Any existing 'reimbursement' rows would violate the narrowed
        // enum — null them out first so the rollback doesn't fail.
        DB::table('other_payments')->where('employee_advance_type', 'reimbursement')->update(['employee_advance_type' => null]);
        DB::statement("ALTER TABLE other_payments MODIFY employee_advance_type ENUM('custody', 'advance_salary', 'per_diem') NULL");
    }
};
