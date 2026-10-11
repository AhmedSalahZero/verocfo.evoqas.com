<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000270_alter_accruals_add_overhead_pool
//
//  Section 7 — confirmed with Mahmoud: Accrual (not Supplier Bill)
//  is the mechanism for tagging a REAL recurring overhead cost
//  (rent, electricity, maintenance) to a specific Overhead Pool, so
//  it counts toward that pool's Actual side at month-end
//  reconciliation. A one-off Supplier Bill tagged to overhead was
//  explicitly deferred — Accrual covers the recurring cases that
//  actually matter here.
//
//  Nullable and optional — an Accrual completely unrelated to
//  manufacturing (e.g. a G&A prepaid insurance amortization) simply
//  leaves this null and is invisible to overhead reconciliation,
//  exactly as before this migration.
//
//  No change to Accrual's own posting mechanics (still Dr Expense /
//  Cr Accrued Expenses, untouched) — this field is read-only input
//  to PayrollRunService::postManufacturingSettlement()'s overhead
//  reconciliation query, not part of the accrual's own journal entry.
//
//  @layer    Migration
//  @depends  accruals, overhead_pools
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accruals', function (Blueprint $table) {
            $table->foreignId('overhead_pool_id')->nullable()->after('amount')
                  ->constrained('overhead_pools')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accruals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overhead_pool_id');
        });
    }
};
