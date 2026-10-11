<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\ChartOfAccount;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000272_seed_manufacturing_overhead_variance_account
//
//  Section 7 (Applied vs Actual — month-end variance closing). 514
//  Manufacturing Labor Variance was originally seeded by migration
//  000225, before per-pool Overhead variance was a confirmed
//  requirement. This adds its overhead counterpart, 515 Manufacturing
//  Overhead Variance, the same way — per Rule 5.1 (never modify an
//  existing migration), a new migration rather than an edit to
//  000225. ChartOfAccountSeeder.php is updated in the same commit so
//  a fresh install seeds this from day one.
//
//  One account, many lines: every Overhead Pool with a variance this
//  period gets its own labeled line under this ONE account inside the
//  same monthly Payroll Run posting that already closes 514 — see
//  PayrollRunService::postManufacturingSettlement().
//
//  @layer    Migration
//  @depends  chart_of_accounts (51 Cost of Goods Sold parent group)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        $cogs = ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('code', '51')
            ->first()
            ?? throw new \RuntimeException('COA account 51 (Cost of Goods Sold) is not seeded — cannot add its child.');

        ChartOfAccount::updateOrCreate(
            ['code' => '515', 'company_id' => null],
            [
                'name_en' => 'Manufacturing Overhead Variance', 'name_ar' => 'انحراف التكاليف الصناعية غير المباشرة',
                'level' => 3, 'section' => 'income_statement', 'class' => 'expenses',
                'account_type' => 'Cost of Goods Sold', 'normal_balance' => 'debit',
                'parent_id' => $cogs->id, 'is_postable' => true, 'allow_currency' => false,
                'company_id' => null, 'is_system' => true, 'is_active' => true,
            ]
        );
    }

    public function down(): void
    {
        ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('code', '515')
            ->delete();
    }
};
