<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000189_add_draft_status_to_factoring_transactions
//
//  Set to Draft — same pattern already used by CustomerPaymentService
//  and LgIssuanceService: reverse everything a pending transaction's
//  disbursement posted, and put it back into a harmless empty-shell
//  state that can then be deleted outright. Confirmed with Mahmoud:
//  Factoring transactions needed this too — until now a mistaken
//  disbursement (wrong invoice, wrong percentage, wrong bank
//  account) had no way back except a manual correcting journal entry
//  outside the feature entirely.
//
//  MySQL enum columns require every value listed up front, so adding
//  'draft' means rebuilding the column — safe here since the table
//  is brand new and empty on every environment that's applied
//  migrations 186-188 so far.
//
//  @layer    Migration
//  @depends  factoring_transactions
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE factoring_transactions MODIFY status ENUM('draft', 'pending', 'collected', 'rejected') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE factoring_transactions MODIFY status ENUM('pending', 'collected', 'rejected') NOT NULL DEFAULT 'pending'");
    }
};
