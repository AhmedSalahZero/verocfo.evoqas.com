<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000079_create_mtl_settlements_table
//
//  Confirmed by Mahmoud — this is the structurally important table:
//  ONE schedule line can have MANY settlements. When a company
//  doesn't have enough funds to pay a full installment, they pay
//  part of it — the schedule line stays open, and further
//  settlements are recorded against it later until fully paid.
//
//  This is a genuinely different shape from Leasing's lease_cheques
//  (one cheque = one all-or-nothing event, no partial-payment
//  concept there) — do not merge these two patterns.
//
//  A schedule line's remaining balance = schedule_payment −
//  SUM(its settlements.settlement_amount). Status is derived from
//  that comparison, not stored — see MtlScheduleLine::computedStatus().
//
//  current_account_id is a REAL BankAccount — this is where actual
//  cash leaves the company, unlike the schedule line itself which is
//  just a planned figure. Every settlement is a genuine financial
//  event needing a pending-GL-posting entry once the Accounting
//  Engine exists (principal/interest split) — see MtlService for
//  the exact comment, regardless of entry_type. (Unlike the initial
//  loan liability posting, which only applies to 'new' loans,
//  installment settlements post the same way for both 'new' and
//  'existing' loans once the Accounting Engine exists — confirmed:
//  paying down an existing loan is real money movement happening now,
//  same as a new one. Flagged clearly here since it's easy to
//  conflate with the 'existing = no action' rule that applies to
//  the INITIAL liability recognition only, not to ongoing payments.)
//
//  @layer    Migration
//  @depends  mtl_schedule_lines, bank_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mtl_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('mtl_schedule_line_id')->constrained('mtl_schedule_lines')->cascadeOnDelete();

            $table->date('settlement_date');
            $table->decimal('settlement_amount', 15, 2);
            $table->foreignId('current_account_id')->constrained('bank_accounts');

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'mtl_schedule_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mtl_settlements');
    }
};
