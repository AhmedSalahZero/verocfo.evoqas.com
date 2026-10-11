<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\ChartOfAccount;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000232_seed_custody_advances_account
//
//  Advances & Settlements (2026-09). Employee money movements were
//  all falling into one account (147) regardless of purpose —
//  confirmed with Mahmoud that Custody, Advance from Salary, and Per
//  Diem for Travel are three different things with different
//  settlement behavior. This migration adds the one genuinely new
//  account Custody needs:
//
//    148   Custody Advances to Employees (عهدة موظفين)
//
//  147 already exists (Advance from Salary — unchanged). Per Diem
//  for Travel deliberately gets NO new account here — it's an
//  outright expense, and the user picks any existing expense account
//  from the Chart of Accounts at the point of payment (same
//  "no default — user's own judgment" treatment as
//  OtherPayment::PARTNER_TYPE_OTHER already uses).
//
//  Same reasoning as 000225 — shared system-wide account
//  (company_id = NULL), one insert reaches every company
//  immediately. ChartOfAccountSeeder.php updated in the same commit
//  so a fresh company install seeds this from day one.
//
//  @layer    Migration
//  @depends  chart_of_accounts (13 Current Assets parent group)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        $currentAssets = ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('code', '13')
            ->first()
            ?? throw new \RuntimeException('COA account 13 (Current Assets) is not seeded — cannot add 148 under it.');

        ChartOfAccount::updateOrCreate(
            ['code' => '148', 'company_id' => null],
            [
                'name_en' => 'Custody Advances to Employees', 'name_ar' => 'عهدة موظفين',
                'level' => 3, 'section' => 'balance_sheet', 'class' => 'assets',
                'account_type' => 'Current Assets', 'normal_balance' => 'debit',
                'parent_id' => $currentAssets->id, 'is_postable' => true, 'allow_currency' => false,
                'company_id' => null, 'is_system' => true, 'is_active' => true,
            ]
        );
    }

    public function down(): void
    {
        ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('code', '148')
            ->delete();
    }
};
