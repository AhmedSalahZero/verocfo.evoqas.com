<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000105_create_accruals_table
//
//  Confirmed by Mahmoud as a crucial, standalone tracking entity —
//  NOT satisfied by simply letting a user pick account 228 (Accrued
//  Expenses) on a Supplier Bill line with nothing behind it.
//
//  THE PROBLEM THIS SOLVES: a company often books an expense before
//  the supplier's actual bill arrives (rent due on the 1st, bill
//  arrives on the 10th; a consultant's retainer; utilities estimated
//  at month-end). That booking is:
//
//      Dr Expense Account (54xxx / 52xxx / etc.)
//      Cr 228 Accrued Expenses
//
//  When the real Supplier Bill later arrives, it must NOT hit the
//  expense account a second time — it must clear the accrual and
//  create the real AP liability:
//
//      Dr 228 Accrued Expenses   [settles the accrual]
//      Cr 221 Accounts Payable   [real liability]
//
//  Without a real record of what was accrued, for how much, and how
//  much of it is still open, there is nothing for a Supplier Bill to
//  reliably "settle against" beyond a free-text account pick — which
//  is exactly the gap this table closes.
//
//  LIFECYCLE: create() posts the initial Dr Expense / Cr 228 entry
//  immediately (via JournalEntryService — this is a real GL posting,
//  not a placeholder) and sets status = 'open'. Every Supplier Bill
//  expense line that settles against this accrual (via
//  supplier_bill_lines.accrual_id) increments `settled_amount` and
//  advances status toward 'fully_settled'. An accrual is never
//  edited or hard-deleted once any amount has been settled against
//  it — same append-only principle as every other financial record
//  in this app.
//
//  `chart_of_account_id` is the ORIGINAL expense/asset account that
//  was debited when the accrual was booked (needed so the initial
//  JE knows what to debit) — NOT 228 itself.
//
//  `amount` is the original accrued amount in FUNCTIONAL currency —
//  same stated simplification GRN and the Inventory Engine already
//  use; an accrual for a foreign-currency-denominated expense is
//  expected to be entered already converted.
//
//  Accrual code format: ACR/YYYY/MM/00001 — immutable after creation,
//  same generator pattern as every other document code in this app.
//
//  @layer    Migration
//  @depends  companies, partners, chart_of_accounts, cost_centers,
//            projects, sales_orders, journal_entries, users
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accruals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('accrual_code', 30)->unique();

            $table->foreignId('partner_id')->constrained('partners'); // supplier this is owed to
            $table->foreignId('chart_of_account_id')->constrained('chart_of_accounts'); // original expense/asset account debited

            $table->foreignId('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('sales_orders')->nullOnDelete();

            $table->string('description', 500);
            $table->date('accrual_date');

            $table->decimal('amount', 15, 2); // functional currency — see header
            $table->decimal('settled_amount', 15, 2)->default(0); // cumulative, never decreases

            $table->enum('status', ['open', 'partially_settled', 'fully_settled', 'cancelled'])->default('open');

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete(); // the initial accrual-booking entry

            $table->text('internal_notes')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'partner_id', 'status']);
            $table->index(['company_id', 'accrual_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accruals');
    }
};
