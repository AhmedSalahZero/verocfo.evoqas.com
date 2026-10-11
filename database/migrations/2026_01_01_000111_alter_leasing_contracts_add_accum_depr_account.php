<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000111_alter_leasing_contracts_add_accum_depr_account
//
//  Lease Amortization Engine — Piece 2/3 (July 2026). Capital Lease
//  needs a dedicated place to point its accumulated depreciation
//  sub-account (parallel to rou_amort_account_id, which is the
//  Operational Treatment B equivalent under 115). Auto-created per
//  contract at activation, same mechanism as the other asset-side
//  accounts — see LeaseAmortizationService::ensureAssetAccounts().
//
//  @layer    Migration
//  @depends  leasing_contracts, chart_of_accounts
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leasing_contracts', function (Blueprint $table) {
            $table->foreignId('accumulated_depreciation_account_id')
                ->nullable()
                ->after('fixed_asset_account_id')
                ->constrained('chart_of_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leasing_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accumulated_depreciation_account_id');
        });
    }
};
