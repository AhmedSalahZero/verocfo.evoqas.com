<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000108_alter_lg_issuances_add_liquidation_expense_account
//
//  Fix (July 2026 audit): LG liquidation previously had nowhere for
//  the user to say which expense/loss account absorbs the claim.
//  The service was defaulting to account 227 (LG Contingent
//  Liabilities) instead — which is wrong on two counts: (1) Vero CFO
//  never books a contingent liability journal entry at all (per
//  Mahmoud — contingent liabilities are disclosed, not journaled),
//  and (2) even if it did, 227 has no business being debited at
//  liquidation, since it was never credited for the LG amount in the
//  first place (see 000068 migration's original comment intent:
//  "Dr claim/loss / Cr 138001" — this column is what was missing to
//  make that real).
//
//  liquidation_expense_account_id — the user picks this on the
//  liquidation form each time (same pattern as the existing
//  liquidation_shortfall_account_id, which picks the bank account
//  for any amount beyond the cash cover held). Nullable because it's
//  only required at the moment of liquidation, not at issuance.
//
//  @layer    Migration
//  @depends  lg_issuances, chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lg_issuances', function (Blueprint $table) {
            $table->foreignId('liquidation_expense_account_id')
                ->nullable()
                ->after('liquidation_shortfall_account_id')
                ->constrained('chart_of_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lg_issuances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('liquidation_expense_account_id');
        });
    }
};
