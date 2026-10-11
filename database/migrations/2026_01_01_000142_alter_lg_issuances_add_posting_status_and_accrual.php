<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000142_alter_lg_issuances_add_posting_status_and_accrual
//
//  Part of the August 2026 "LG Issuance is not frozen anymore" fix
//  (confirmed with Mahmoud). Two independent additions:
//
//  1. posting_status ('draft' | 'posted') — a NEW, separate dimension
//     from the existing `status` column (running/expired/cancelled).
//     `status` describes whether the LG is currently valid/alive;
//     `posting_status` describes whether its own journal entry (Cash
//     Cover + Issuance Fee + first Commission) is live on the GL and
//     therefore whether the core form fields are locked. Every
//     existing row is backfilled to 'posted' — this migration does
//     not change behavior for anything already issued; it only adds
//     the mechanism going forward (LgIssuanceService::setToDraft()).
//
//  2. next_commission_accrual_date / last_commission_accrual_date —
//     drives the new recurring commission accrual job
//     (postDueCommissionAccruals(), run by the lg:accrue-commission
//     scheduled command). The FIRST commission accrual already
//     happens for free at issuance (postIssuanceMovements()) — this
//     column is seeded to issuance_date + one commission_interval so
//     the scheduler never double-counts that first one. Left NULL
//     for opening_issuance rows and for any issuance whose GL
//     posting has been reversed back to draft (no schedule while the
//     LG isn't actually posted).
//
//  @layer    Migration
//  @depends  lg_issuances
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lg_issuances', function (Blueprint $table) {
            $table->enum('posting_status', ['draft', 'posted'])->default('posted')->after('status');
            $table->date('next_commission_accrual_date')->nullable()->after('commission_interval');
            $table->date('last_commission_accrual_date')->nullable()->after('next_commission_accrual_date');

            $table->index(['company_id', 'posting_status']);
            $table->index(['company_id', 'next_commission_accrual_date']);
        });
    }

    public function down(): void
    {
        Schema::table('lg_issuances', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'posting_status']);
            $table->dropIndex(['company_id', 'next_commission_accrual_date']);
            $table->dropColumn(['posting_status', 'next_commission_accrual_date', 'last_commission_accrual_date']);
        });
    }
};
