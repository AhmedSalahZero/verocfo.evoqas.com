<?php

// ══════════════════════════════════════════════════════════════════
//  Migration 000301 — the accounts Audit H19 found missing
//
//  AUDIT H19 (Sept 2026). The Chart of Accounts had no income-tax
//  line (so no "profit after tax"), no deferred tax, no allowance for
//  doubtful debts / expected credit losses, no inventory obsolescence
//  provision and no gain / loss on disposal of fixed assets.
//
//  Adds, as shared system accounts (company_id NULL — visible to every
//  company, same as the rest of the seeded chart), exactly the list in
//  ChartOfAccountSeeder::auditH19Accounts():
//    124 Deferred Tax Asset            215 Deferred Tax Liability
//    149 Allowance for ECL (contra)    150 Inventory Obsolescence (contra)
//    424 Gain on Disposal              545 Loss on Disposal
//    546 Impairment Loss               544 Expected Credit Loss expense
//    517 Inventory Write-down          59 / 591 / 592 Income Tax
//
//  Idempotent: an account that already exists is updated in place,
//  never duplicated. down() removes only accounts nothing has posted to.
//
//  @layer    Migration
//  @depends  chart_of_accounts, ChartOfAccountSeeder
// ══════════════════════════════════════════════════════════════════

use Database\Seeders\ChartOfAccountSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A fresh database with no chart yet is seeded later by the seeder itself.
        if (! DB::table('chart_of_accounts')->whereNull('company_id')->where('code', '5')->exists()) {
            return;
        }

        foreach (ChartOfAccountSeeder::auditH19Accounts() as [$code, $parentCode, $en, $ar, $level, $section, $class, $type, $normal, $postable]) {
            $parentId = DB::table('chart_of_accounts')->whereNull('company_id')->where('code', $parentCode)->value('id');
            if (! $parentId) {
                continue;
            }

            $values = [
                'name_en' => $en, 'name_ar' => $ar, 'level' => $level, 'section' => $section, 'class' => $class,
                'account_type' => $type, 'normal_balance' => $normal, 'parent_id' => $parentId,
                'is_postable' => $postable, 'allow_currency' => false, 'is_system' => true, 'is_active' => true,
                'updated_at' => now(),
            ];

            $existing = DB::table('chart_of_accounts')->whereNull('company_id')->where('code', $code)->value('id');
            if ($existing) {
                DB::table('chart_of_accounts')->where('id', $existing)->update($values);
            } else {
                DB::table('chart_of_accounts')->insert($values + ['code' => $code, 'company_id' => null, 'created_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(ChartOfAccountSeeder::auditH19Accounts()) as [$code]) {
            $id = DB::table('chart_of_accounts')->whereNull('company_id')->where('code', $code)->value('id');
            if ($id && ! DB::table('journal_entry_lines')->where('chart_of_account_id', $id)->exists()) {
                DB::table('chart_of_accounts')->where('id', $id)->delete();
            }
        }
    }
};
