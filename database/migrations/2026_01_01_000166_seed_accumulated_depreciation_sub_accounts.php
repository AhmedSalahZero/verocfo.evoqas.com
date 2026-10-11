<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\ChartOfAccount;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000166_seed_accumulated_depreciation_sub_accounts
//
//  Fixed Assets module — Phase 1. Confirmed with Mahmoud: individual
//  assets are tracked in a subsidiary Fixed Asset Register, NOT as
//  one Chart of Accounts row each (unlike the Leasing module's
//  per-contract 114/115 sub-accounts — that pattern doesn't scale to
//  the hundreds of individual assets a real company will register).
//  Instead: ONE shared GL account per asset CATEGORY, matching the
//  111001-111006 categories already seeded for Tangible Fixed Assets
//  — the Asset Register's total per category always reconciles to
//  that category's GL balance.
//
//  113 (Accumulated Depreciation) already exists as a non-postable
//  Level 3 parent (ChartOfAccountSeeder) but has never had Level 4
//  children — nothing could post to it directly. This migration adds
//  the missing six, one per 111xxx category, same naming, same
//  is_system/company_id=NULL sharing (all COA rows are shared system-
//  wide per the seeder's own header — never per-company).
//
//  Credit normal balance (contra-asset), same as their 111xxx
//  counterparts are debit — displayed as negative under Fixed Assets
//  on the Balance Sheet, netting against gross cost to show NBV.
//
//  Data-only migration (mirrors the BackfillFunctionalCurrencies
//  command's reasoning, but done here as a migration since these six
//  rows are shared system-wide, not per-company — a single insert
//  reaches every company immediately). ChartOfAccountSeeder.php is
//  also updated in the same commit so a fresh install seeds these
//  from day one.
//
//  @layer    Migration
//  @depends  chart_of_accounts (000006-ish COA table), 111xxx (seeder)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        $parent = ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('code', '113')
            ->first();

        // INF-002 fix (8 Sep 2026) — this migration is a BACKFILL for
        // installs that already existed before it was written; a
        // fresh install seeds these same six accounts directly via
        // ChartOfAccountSeeder (confirmed present there). Migrations
        // always run before seeders, so on a brand-new database
        // account 113 legitimately doesn't exist yet at this exact
        // point — that is expected, not an error. Throwing here
        // aborted 113 of the remaining 281 migrations on a clean
        // install. Returning early leaves the seeder to do its job
        // moments later, matching what this file's own header already
        // said was the intended design.
        if (! $parent) {
            return;
        }

        foreach ([
            ['113001', 'Accum. Depreciation — Land',                  'مجمع إهلاك — أراضي'],
            ['113002', 'Accum. Depreciation — Buildings',             'مجمع إهلاك — مباني'],
            ['113003', 'Accum. Depreciation — Machinery & Equipment', 'مجمع إهلاك — آلات ومعدات'],
            ['113004', 'Accum. Depreciation — Vehicles',              'مجمع إهلاك — سيارات ووسائل نقل'],
            ['113005', 'Accum. Depreciation — Office Furniture',      'مجمع إهلاك — أثاث ومفروشات'],
            ['113006', 'Accum. Depreciation — Computer Equipment',    'مجمع إهلاك — أجهزة وتقنية معلومات'],
        ] as [$code, $en, $ar]) {
            ChartOfAccount::updateOrCreate(
                ['code' => $code, 'company_id' => null],
                [
                    'name_en' => $en, 'name_ar' => $ar,
                    'level' => 4, 'section' => 'balance_sheet', 'class' => 'assets',
                    'account_type' => 'Accumulated Depreciation', 'normal_balance' => 'credit',
                    'parent_id' => $parent->id, 'is_postable' => true, 'allow_currency' => false,
                    'company_id' => null, 'is_system' => true, 'is_active' => true,
                ]
            );
        }
    }

    public function down(): void
    {
        ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->whereIn('code', ['113001', '113002', '113003', '113004', '113005', '113006'])
            ->delete();
    }
};
