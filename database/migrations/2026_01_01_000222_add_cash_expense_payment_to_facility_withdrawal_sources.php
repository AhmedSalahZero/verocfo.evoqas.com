<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000222_add_cash_expense_payment_to_facility_withdrawal_sources
//
//  Same fix as 000221 (Other Payment / Other Collection), for a third
//  transaction type found on the same audit pass: CashExpensePaymentService
//  also lets the user pick a Credit Facility as the money source
//  (its own header comment: "mirrors OtherPaymentService") — the GL
//  entry has always posted correctly, but the facility's own
//  sub-ledger never learned about it until now.
//
//  Settlement side not needed here — Cash Expense Payment is a
//  MONEY-OUT document only (paying an expense), never money-in, so
//  only facility_withdrawals.source_type needs extending, not
//  facility_settlements.source_type.
//
//  MySQL requires a raw MODIFY for enum changes (no doctrine/dbal in
//  this project — same pattern as 000144, 000164, 000221).
//
//  @layer    Migration
//  @depends  facility_withdrawals
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE facility_withdrawals MODIFY source_type ENUM(
            'opening_balance',
            'supplier_payment',
            'internal_money_transfer',
            'other_payment',
            'cash_expense_payment'
        ) NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE facility_withdrawals MODIFY source_type ENUM(
            'opening_balance',
            'supplier_payment',
            'internal_money_transfer',
            'other_payment'
        ) NULL");
    }
};
