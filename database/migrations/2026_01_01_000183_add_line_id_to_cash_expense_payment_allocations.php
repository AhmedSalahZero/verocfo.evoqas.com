<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000183_add_line_id_to_cash_expense_payment_allocations
//
//  Companion to Cash Expense Payment becoming multi-line (migration
//  000181). Per Mahmoud: "Allocating with Customer Contracts" stays
//  at the whole-payment level (one shared table, not one per line),
//  but each allocation row now says WHICH of the payment's lines it's
//  drawing from — e.g. a payment with a Transportation line and an
//  Accommodation line lets the user allocate the Transportation
//  portion to one customer contract and the Accommodation portion to
//  another.
//
//  Nullable: a payment with only one line doesn't force the user to
//  pick it explicitly (the form pre-selects the only option) and
//  existing pre-multi-line allocation rows remain valid.
//
//  This table's name is long enough that Laravel's auto-generated FK
//  name would exceed MySQL's 64-char limit (confirmed the hard way on
//  this exact table already — see migration 000179's header) — so
//  every constraint here gets an explicit short name too.
//
//  ALSO confirmed the hard way: MySQL was silently using the OLD
//  unique (cep_alloc_payment_project_unique) as the supporting index
//  for the existing foreign key on cash_expense_payment_id — dropping
//  it straight away fails with error 1553 ("needed in a foreign key
//  constraint"). A plain supporting index on cash_expense_payment_id
//  is added FIRST so the old unique is safe to drop afterward.
//
//  @layer    Migration
//  @depends  cash_expense_payment_project_allocations, cash_expense_payment_lines
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_expense_payment_project_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_expense_payment_line_id')->nullable()->after('project_id');
            $table->foreign('cash_expense_payment_line_id', 'cep_alloc_line_fk')
                ->references('id')->on('cash_expense_payment_lines')->nullOnDelete();

            // MySQL was using the OLD unique below as the supporting
            // index for the existing FK on cash_expense_payment_id —
            // give it a replacement plain index BEFORE dropping that
            // unique, or the drop fails with error 1553.
            $table->index('cash_expense_payment_id', 'cep_alloc_payment_idx');

            // The old unique (one payment, one project, ever) is now too
            // restrictive — two different lines on the same payment can
            // each legitimately allocate to the same Project. Replaced
            // with a line-scoped unique (MySQL treats NULL as distinct,
            // so pre-existing rows with no line_id remain valid).
            $table->dropUnique('cep_alloc_payment_project_unique');
            $table->unique(['cash_expense_payment_line_id', 'project_id'], 'cep_alloc_line_project_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cash_expense_payment_project_allocations', function (Blueprint $table) {
            $table->dropUnique('cep_alloc_line_project_unique');
            $table->unique(['cash_expense_payment_id', 'project_id'], 'cep_alloc_payment_project_unique');
            $table->dropIndex('cep_alloc_payment_idx');
            $table->dropForeign('cep_alloc_line_fk');
            $table->dropColumn('cash_expense_payment_line_id');
        });
    }
};