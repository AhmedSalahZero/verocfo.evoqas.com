<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000269_alter_overhead_pools_add_applied_account
//
//  Section 7 (Applied vs Actual — month-end variance closing).
//  Confirmed with Mahmoud: overhead variance closes PER POOL, not as
//  one lumped company-wide number — so unlike labor (one shared 237
//  Direct Labor Clearing account, since all Direct Labor employees
//  settle against the same real payroll total), each Overhead Pool
//  now needs its OWN "Applied" clearing account.
//
//  This corrects the account-238 design note in ChartOfAccountSeeder
//  ("Deliberately kept as ONE account — any breakdown comes from
//  OverheadPool, not COA sub-accounts") — that was the right call
//  before per-pool variance reconciliation was a confirmed
//  requirement; it no longer holds now that it is. 238 becomes a
//  non-postable PARENT; each pool gets its own postable Level 4
//  child underneath, auto-created the moment the pool itself is
//  created (OverheadPoolService::create()) — same auto-creation
//  pattern already used for Machine/Lease sub-accounts.
//
//  No new "Actual" account is needed, by design — Actual is computed
//  directly from source data each reconciliation (real Accrual rows
//  tagged to this pool + real payroll cost for this pool's Overhead-
//  role employees, both for the Payroll Run's own period), exactly
//  mirroring how labor's "Actual" was never a posted GL balance
//  either — see PayrollRunService::postManufacturingSettlement()'s
//  existing $expenseOwed calculation for the precedent this follows.
//
//  @layer    Migration
//  @depends  overhead_pools, chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('overhead_pools', function (Blueprint $table) {
            $table->foreignId('applied_account_id')->nullable()->after('allocation_base')
                  ->constrained('chart_of_accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('overhead_pools', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applied_account_id');
        });
    }
};
