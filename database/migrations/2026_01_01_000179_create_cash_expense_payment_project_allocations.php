<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000179_create_cash_expense_payment_project_allocations
//
//  "Allocating with Customer Contracts" (August 2026, per Mahmoud) —
//  lets a Cash Expense Payment be split across one or more of a
//  customer's Projects/Contracts, for PROJECT REPORTING ONLY:
//
//    - Confirmed with Mahmoud: this has ZERO effect on the journal
//      entry. The posted entry (migration 000177) stays exactly as
//      it is, whether or not any allocation rows exist.
//    - The same allocated_amount feeds BOTH the future Project P&L
//      (as an expense line) and the future Project Cash Flow report
//      (as a cash outflow line) once those reports exist (currently
//      NOT BUILT — see VeroCFO_Project_Module_Structure_v1_1.md
//      Section 7 — this table just makes sure the data is captured
//      and ready for whenever they are).
//    - Confirmed with Mahmoud: allocation does NOT have to add up to
//      100% of the payment. A 60,000 expense can allocate 40,000 to
//      one project and leave the remaining 20,000 unallocated
//      entirely — there is no "unallocated remainder" row to manage.
//    - Enforced (in CashExpensePaymentRequest, not here): the SUM of
//      every row's allocated_amount for one payment must never
//      exceed that payment's subtotal (the pre-tax expense amount —
//      not net_amount, since VAT is recoverable and WHT is a tax
//      mechanic, neither is a real project cost).
//
//  "Customer Contracts" = the existing Projects table (Project model
//  already self-describes as a Contract — see
//  Project::getDisplayNameAttribute()). Deliberately NOT Sales
//  Orders — the screenshot's "Contract Name"/"Contract Code"/
//  "Contract Amount" columns map 1:1 to Project.name_en /
//  Project.contract_code / Project.contract_value.
//
//  One payment can allocate across MULTIPLE projects, and (unlike
//  the screenshot's single-customer example) rows are not
//  constrained to one customer — the Vue form's Customer dropdown is
//  just a filter to find the right Project, not a stored value.
//
//  Every constraint below is given an explicit short name — this
//  table's own name is long enough that Laravel's auto-generated
//  names (table_column_foreign/unique/index) exceed MySQL's 64-
//  character identifier limit. Confirmed the hard way: the first
//  version of this migration failed on exactly this ("Identifier
//  name '..._cash_expense_payment_id_foreign' is too long"), and the
//  unique/index constraints below would have failed the same way one
//  statement later had the FK not been fixed first.
//
//  @layer    Migration
//  @depends  cash_expense_payments, projects
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_expense_payment_project_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->unsignedBigInteger('cash_expense_payment_id');
            $table->foreign('cash_expense_payment_id', 'cep_alloc_payment_fk')
                ->references('id')->on('cash_expense_payments')->cascadeOnDelete();

            $table->unsignedBigInteger('project_id');
            $table->foreign('project_id', 'cep_alloc_project_fk')
                ->references('id')->on('projects')->cascadeOnDelete();

            // Pay-currency amount, same currency as the parent payment's
            // subtotal — no separate currency/rate needed here.
            $table->decimal('allocated_amount', 15, 2);
            $table->decimal('allocated_amount_functional', 15, 2);

            $table->timestamps();

            $table->unique(['cash_expense_payment_id', 'project_id'], 'cep_alloc_payment_project_unique');
            $table->index(['company_id', 'project_id'], 'cep_alloc_company_project_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_expense_payment_project_allocations');
    }
};