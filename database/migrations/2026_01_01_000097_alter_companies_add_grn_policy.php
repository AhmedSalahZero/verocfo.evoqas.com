<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000097_alter_companies_add_grn_policy
//
//  Adds the Goods Receipt Note policy — a per-company preference
//  set by Super Admin at company creation (confirmed by Mahmoud):
//
//    'po_required'   — every GRN line must trace back to a Purchase
//                       Order line. No ad-hoc receiving allowed.
//    'allow_ad_hoc'  — GRNs may optionally be created with no parent
//                       Purchase Order at all (direct/ad-hoc receipt).
//
//  Default is 'po_required' — the stricter, audit-friendlier option
//  — so existing/not-yet-configured companies don't silently gain a
//  new capability on migration.
//
//  @layer    Migration
//  @depends  companies
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('grn_policy', ['po_required', 'allow_ad_hoc'])
                ->default('po_required')
                ->after('company_type');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('grn_policy');
        });
    }
};
