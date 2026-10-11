<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\ChartOfAccount;

// ══════════════════════════════════════════════════════════════════
//  2026_01_01_000225_seed_payroll_clearing_accounts
//
//  Payroll GL Posting foundation (2026-09). Adds every new account
//  the Payroll module needs, agreed with Mahmoud step by step:
//    514            Manufacturing Labor Variance (COGS)
//    521/522        Salaries / Employee Reimbursements — Sales & Distribution
//    531/532        Salaries / Employee Reimbursements — Marketing
//    541/542/543    Salaries / EOSB Expense / Employee Reimbursements — G&A
//    239            Salaries Payable
//    226005         Salaries Tax Payable
//    240            Social Insurance Payable
//    241            End of Service Benefits Provision
//    147            Advances to Employees
//
//  Same reasoning as 000223/000224 — all COA rows are shared
//  system-wide (company_id = NULL), so a single insert reaches every
//  company immediately. ChartOfAccountSeeder.php is updated in the
//  same commit so a fresh install seeds these from day one.
//
//  @layer    Migration
//  @depends  chart_of_accounts (13 Current Assets, 22 Current
//            Liabilities, 226 Taxes Payable, 51 COGS, 52 Sales & Dist.,
//            53 Marketing, 54 G&A parent groups)
// ══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    public function up(): void
    {
        $parent = fn (string $code) => ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('code', $code)
            ->first()
            ?? throw new \RuntimeException("COA account {$code} is not seeded — cannot add its children.");

        $currentAssets = $parent('13');
        $currentLiab   = $parent('22');
        $taxes         = $parent('226');
        $cogs          = $parent('51');
        $sales         = $parent('52');
        $marketing     = $parent('53');
        $gAndA         = $parent('54');

        $rows = [
            // code, name_en, name_ar, section, class, account_type, normal_balance, parent, postable
            ['514',    'Manufacturing Labor Variance',                             'انحراف تكلفة العمالة الصناعية',                 'income_statement', 'expenses',    'Cost of Goods Sold',                  'debit',  $cogs,         true],
            ['521',    'Salaries — Sales & Distribution',                          'رواتب — المبيعات والتوزيع',                       'income_statement', 'expenses',    'Sales Expenses',                      'debit',  $sales,        true],
            ['522',    'Employee Reimbursements — Sales & Distribution',           'تعويضات موظفين — المبيعات والتوزيع',              'income_statement', 'expenses',    'Sales Expenses',                      'debit',  $sales,        true],
            ['531',    'Salaries — Marketing',                                     'رواتب — التسويق',                                 'income_statement', 'expenses',    'Marketing Expenses',                  'debit',  $marketing,    true],
            ['532',    'Employee Reimbursements — Marketing',                      'تعويضات موظفين — التسويق',                        'income_statement', 'expenses',    'Marketing Expenses',                  'debit',  $marketing,    true],
            ['541',    'Salaries — General & Administrative',                      'رواتب — عمومية وإدارية',                          'income_statement', 'expenses',    'General & Administrative Expenses',   'debit',  $gAndA,        true],
            ['542',    'End of Service Benefits Expense',                          'مصروف مكافأة نهاية الخدمة',                       'income_statement', 'expenses',    'General & Administrative Expenses',   'debit',  $gAndA,        true],
            ['543',    'Employee Reimbursements — General & Administrative',       'تعويضات موظفين — عمومية وإدارية',                 'income_statement', 'expenses',    'General & Administrative Expenses',   'debit',  $gAndA,        true],
            ['239',    'Salaries Payable',                                         'رواتب مستحقة الدفع',                              'balance_sheet',    'liabilities', 'Current Liabilities',                 'credit', $currentLiab,  true],
            ['226005', 'Salaries Tax Payable',                                     'ضريبة كسب العمل المستحقة',                        'balance_sheet',    'liabilities', 'Current Liabilities',                 'credit', $taxes,        true],
            ['240',    'Social Insurance Payable',                                 'تأمينات اجتماعية مستحقة',                         'balance_sheet',    'liabilities', 'Current Liabilities',                 'credit', $currentLiab,  true],
            ['241',    'End of Service Benefits Provision',                        'مخصص مكافأة نهاية الخدمة',                        'balance_sheet',    'liabilities', 'Current Liabilities',                 'credit', $currentLiab,  true],
            ['147',    'Advances to Employees',                                    'دفعات مقدمة لموظفين',                             'balance_sheet',    'assets',      'Current Assets',                      'debit',  $currentAssets, true],
        ];

        foreach ($rows as [$code, $en, $ar, $section, $class, $type, $normal, $parentAccount, $postable]) {
            ChartOfAccount::updateOrCreate(
                ['code' => $code, 'company_id' => null],
                [
                    'name_en' => $en, 'name_ar' => $ar,
                    'level' => strlen($code) > 3 ? 4 : 3, 'section' => $section, 'class' => $class,
                    'account_type' => $type, 'normal_balance' => $normal,
                    'parent_id' => $parentAccount->id, 'is_postable' => $postable, 'allow_currency' => false,
                    'company_id' => null, 'is_system' => true, 'is_active' => true,
                ]
            );
        }
    }

    public function down(): void
    {
        ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->whereIn('code', ['514', '521', '522', '531', '532', '541', '542', '543', '239', '226005', '240', '241', '147'])
            ->delete();
    }
};
