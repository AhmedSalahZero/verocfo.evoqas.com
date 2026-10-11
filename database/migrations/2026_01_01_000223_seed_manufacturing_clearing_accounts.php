<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\ChartOfAccount;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000223_seed_manufacturing_clearing_accounts
//
//  Manufacturing GL Posting fix (2026-09) — adds 237 Direct Labor
//  Clearing and 238 Manufacturing Overhead Absorbed. Confirmed with
//  Mahmoud: both are liability CLEARING accounts, same family as 228
//  Accrued Expenses / 233 GRNI — credited when ProductionOrderService/
//  JobOrderService::recordLaborCost()/recordOverheadCost() debit 135002
//  WIP, settled later by real Wages / manufacturing-expense postings
//  (accrual, then actual) — not built here. Deliberately ONE account
//  each — the electricity/maintenance/spare-parts breakdown comes
//  from OverheadPool (company-defined, unbounded per company), never
//  from COA sub-accounts.
//
//  Data-only migration, same reasoning as 000166 (Accumulated
//  Depreciation sub-accounts): all COA rows are shared system-wide
//  (company_id = NULL, per the seeder's own header) — a single insert
//  reaches every company immediately. ChartOfAccountSeeder.php is
//  updated in the same commit so a fresh install seeds these from
//  day one.
//
//  @layer    Migration
//  @depends  chart_of_accounts (22 Current Liabilities parent group)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        $parent = ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('code', '22')
            ->first();

        if (! $parent) {
            throw new \RuntimeException('COA account 22 (Current Liabilities) is not seeded — cannot add its children.');
        }

        foreach ([
            ['237', 'Direct Labor Clearing',              'مصروفات أجور مباشرة معلقة'],
            ['238', 'Manufacturing Overhead Absorbed',     'أعباء صناعية غير مباشرة محملة'],
        ] as [$code, $en, $ar]) {
            ChartOfAccount::updateOrCreate(
                ['code' => $code, 'company_id' => null],
                [
                    'name_en' => $en, 'name_ar' => $ar,
                    'level' => 3, 'section' => 'balance_sheet', 'class' => 'liabilities',
                    'account_type' => 'Current Liabilities', 'normal_balance' => 'credit',
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
            ->whereIn('code', ['237', '238'])
            ->delete();
    }
};
