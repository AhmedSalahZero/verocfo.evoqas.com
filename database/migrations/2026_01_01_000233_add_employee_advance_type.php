<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000233_add_employee_advance_type
//
//  Advances & Settlements (2026-09). Adds employee_advance_type to
//  both other_payments and other_collections — ONLY meaningful when
//  partner_type = 'employee' (null for every other partner type).
//  Three values, confirmed with Mahmoud:
//
//    custody        → debits/credits 148 Custody Advances to
//                      Employees. A receivable, settled by the
//                      employee submitting receipts / repaying.
//    advance_salary  → debits/credits 147 Advances to Employees
//                      (unchanged from before this migration). A
//                      receivable, settled by payroll deduction or
//                      manual repayment.
//    per_diem        → OtherPayment ONLY (see check constraint note
//                      below) — an outright expense. The user picks
//                      ANY expense account from the Chart of
//                      Accounts at payment time; no default is
//                      suggested, same "no default — user's own
//                      judgment" treatment PARTNER_TYPE_OTHER already
//                      gets. Never appears as a balance anywhere —
//                      it is not a receivable and is never settled.
//
//  per_diem is deliberately absent from the enum on
//  other_collections — you don't "collect" a per diem back, so the
//  option should never even be selectable there. Application-level
//  enforcement lives in OtherCollectionRequest; the narrower enum
//  here is a second line of defense at the schema level.
//
//  Both columns are nullable — every non-Employee partner type (and
//  any Employee row created before this migration) simply has null
//  here, which the model/service treats as "not an employee
//  advance-type row" (falls back to the plain per-partner-type
//  default, unchanged from before).
//
//  @layer    Migration
//  @depends  other_payments, other_collections
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('other_payments', function (Blueprint $table) {
            $table->enum('employee_advance_type', ['custody', 'advance_salary', 'per_diem'])
                ->nullable()
                ->after('partner_type');
        });

        Schema::table('other_collections', function (Blueprint $table) {
            $table->enum('employee_advance_type', ['custody', 'advance_salary'])
                ->nullable()
                ->after('partner_type');
        });
    }

    public function down(): void
    {
        Schema::table('other_payments', function (Blueprint $table) {
            $table->dropColumn('employee_advance_type');
        });

        Schema::table('other_collections', function (Blueprint $table) {
            $table->dropColumn('employee_advance_type');
        });
    }
};
