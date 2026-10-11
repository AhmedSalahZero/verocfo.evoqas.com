<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  UserModulePermission Model
//
//  Feature-level permission matrix — one row per user per feature.
//  Replaces the old module-level permission system.
//
//  Permission flags:
//    can_view    → read / list records
//    can_create  → create new records
//    can_edit    → modify draft / open records
//    can_reverse → void / cancel / reverse posted records
//                  (no hard deletes in Vero CFO)
//    can_post    → post a document to the ledger / record a payment
//                  (Audit H3, Sept 2026 — was the same as can_edit)
//    can_approve → approve SOMEONE ELSE's request: manual journal,
//                  expense claim, reopening a posted invoice / bill
//                  (Audit H3 — nobody approves their own request)
//
//  Module keys:
//    accounting | banking | hr | analytics | planning | valuation
//
//  Feature keys per module — see FEATURES constant below.
//
//  Company Admin bypass:
//    Company Admin and Super Admin bypass this table entirely.
//    This table is only consulted for role = 'user'.
//
//  Usage:
//    $user->canOnFeature('view', 'accounting', 'customer_invoices')
//
//  @layer    Model
//  @table    user_module_permissions
//  @depends  App\Models\User, App\Models\Company
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToCompany;

class UserModulePermission extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'user_id',
        'module',
        'feature',
        'can_view',
        'can_create',
        'can_edit',
        'can_reverse',
        'can_post',    // Audit H3
        'can_approve', // Audit H3
        'granted_by',
    ];

    protected $casts = [
        'can_view'    => 'boolean',
        'can_create'  => 'boolean',
        'can_edit'    => 'boolean',
        'can_reverse' => 'boolean',
        'can_post'    => 'boolean',
        'can_approve' => 'boolean',
    ];

    // ── Module Constants ──────────────────────────────────────────

    const MODULE_ACCOUNTING = 'accounting';
    const MODULE_BANKING    = 'banking';
    const MODULE_HR         = 'hr';
    const MODULE_ANALYTICS  = 'analytics';
    const MODULE_PLANNING   = 'planning';
    const MODULE_VALUATION  = 'valuation';

    const ALL_MODULES = [
        self::MODULE_ACCOUNTING,
        self::MODULE_BANKING,
        self::MODULE_HR,
        self::MODULE_ANALYTICS,
        self::MODULE_PLANNING,
        self::MODULE_VALUATION,
    ];

    // ── Feature Map ───────────────────────────────────────────────
    // Keys used for permission storage and middleware checks.
    // Grouped by module then by tab/section within the module.
    //
    // Flag availability:
    //   view_only  → only can_view is available (reports, analytics)
    //   no_reverse → can_view + can_create + can_edit (settings, master data)
    //   full       → all four flags
    // ─────────────────────────────────────────────────────────────

    const FEATURES = [

        // ── Accounting ────────────────────────────────────────────
        'accounting' => [

            // Sales Cycle
            ['key' => 'sales_contracts',    'label' => 'Contracts',            'tab' => 'Sales Cycle',        'flags' => 'full'],
            ['key' => 'sales_orders',        'label' => 'Sales Orders',         'tab' => 'Sales Cycle',        'flags' => 'full'],
            ['key' => 'customer_invoices',   'label' => 'Customer Invoices',    'tab' => 'Sales Cycle',        'flags' => 'full'],
            ['key' => 'customer_receipts',   'label' => 'Customer Receipts',    'tab' => 'Sales Cycle',        'flags' => 'full'],
            ['key' => 'accounts_receivable', 'label' => 'Accounts Receivable',  'tab' => 'Sales Cycle',        'flags' => 'view_only'],
            // Other Collection (August 2026) — money received from a
            // Shareholder / Employee / Sister Company / Supplier
            // (refund) / Other partner.
            ['key' => 'other_collections',   'label' => 'Other Collection',     'tab' => 'Sales Cycle',        'flags' => 'full'],

            // Purchase Cycle
            ['key' => 'purchase_orders',     'label' => 'Purchase Orders',      'tab' => 'Purchase Cycle',     'flags' => 'full'],
            ['key' => 'goods_receipt',        'label' => 'Goods Receipt',        'tab' => 'Purchase Cycle',     'flags' => 'full'],
            ['key' => 'supplier_bills',       'label' => 'Supplier Bills',       'tab' => 'Purchase Cycle',     'flags' => 'full'],
            ['key' => 'supplier_payments',    'label' => 'Supplier Payments',    'tab' => 'Purchase Cycle',     'flags' => 'full'],
            ['key' => 'accounts_payable',     'label' => 'Accounts Payable',     'tab' => 'Purchase Cycle',     'flags' => 'view_only'],
            // Other Payment (August 2026) — money paid to a Shareholder /
            // Employee / Sister Company / Customer (refund) / Other partner.
            ['key' => 'other_payments',       'label' => 'Other Payment',        'tab' => 'Purchase Cycle',     'flags' => 'full'],

            // Inventory
            ['key' => 'inventory_items',      'label' => 'Inventory Items',      'tab' => 'Inventory',          'flags' => 'no_reverse'],
            ['key' => 'inventory_movement',   'label' => 'Inventory Movement',   'tab' => 'Inventory',          'flags' => 'full'],
            ['key' => 'internal_transfer',    'label' => 'Internal Transfer',    'tab' => 'Inventory',          'flags' => 'full'],
            ['key' => 'inventory_adjustment', 'label' => 'Inventory Adjustment', 'tab' => 'Inventory',          'flags' => 'full'],
            ['key' => 'stock_ledger',          'label' => 'Stock Ledger',         'tab' => 'Inventory',          'flags' => 'view_only'],

            // Expenses
            ['key' => 'cash_expenses',         'label' => 'Cash Expenses',        'tab' => 'Expenses',           'flags' => 'full'],
            ['key' => 'employee_expenses',     'label' => 'Employee Expenses',    'tab' => 'Expenses',           'flags' => 'full'],
            ['key' => 'shareholder_transactions','label'=>'Shareholder Transactions','tab'=> 'Expenses',          'flags' => 'full'],

            // General Accounting
            ['key' => 'journal_entries',       'label' => 'Journal Entries',      'tab' => 'General',            'flags' => 'full'],
            ['key' => 'general_ledger',         'label' => 'General Ledger',       'tab' => 'General',            'flags' => 'view_only'],
            ['key' => 'fixed_assets',           'label' => 'Fixed Assets',         'tab' => 'General',            'flags' => 'full'],

            // Manufacturing (shown only for Manufacturing/Mixed companies)
            ['key' => 'bill_of_materials',     'label' => 'Bill of Materials',    'tab' => 'Manufacturing',      'flags' => 'no_reverse'],
            ['key' => 'production_orders',     'label' => 'Production Orders',    'tab' => 'Manufacturing',      'flags' => 'full'],

            // Financial Reports
            ['key' => 'trial_balance',          'label' => 'Trial Balance',        'tab' => 'Financial Reports',  'flags' => 'view_only'],
            ['key' => 'profit_loss',            'label' => 'Profit & Loss',        'tab' => 'Financial Reports',  'flags' => 'view_only'],
            ['key' => 'balance_sheet',          'label' => 'Balance Sheet',        'tab' => 'Financial Reports',  'flags' => 'view_only'],
            ['key' => 'cash_flow',              'label' => 'Cash Flow Statement',  'tab' => 'Financial Reports',  'flags' => 'view_only'],

            // Accounting Settings
            ['key' => 'acc_chart_of_accounts',  'label' => 'Chart of Accounts',   'tab' => 'Acc. Settings',      'flags' => 'no_reverse'],
            ['key' => 'acc_cost_centers',        'label' => 'Cost Centers',         'tab' => 'Acc. Settings',      'flags' => 'no_reverse'],
            ['key' => 'acc_fiscal_periods',      'label' => 'Fiscal Periods',       'tab' => 'Acc. Settings',      'flags' => 'no_reverse'],
            ['key' => 'acc_currencies',          'label' => 'Currencies',           'tab' => 'Acc. Settings',      'flags' => 'no_reverse'],
            // Audit M4 (Sept 2026) — dedicated right for the Opening
            // Balances screen. create = save draft rows, edit = delete
            // rows and POST (one-time, irreversible) to the ledger.
            ['key' => 'opening_balances',        'label' => 'Opening Balances',     'tab' => 'Acc. Settings',      'flags' => 'no_reverse'],
        ],

        // ── Banking ───────────────────────────────────────────────
        'banking' => [
            ['key' => 'credit_facilities',     'label' => 'Credit Facilities',    'tab' => 'Facilities',         'flags' => 'full'],
            ['key' => 'cash_position',          'label' => 'Cash Position',        'tab' => 'Cash Management',    'flags' => 'view_only'],
            ['key' => 'cash_forecast',          'label' => 'Cash Forecast',        'tab' => 'Cash Management',    'flags' => 'no_reverse'],
            ['key' => 'treasury_operations',    'label' => 'Treasury Operations',  'tab' => 'Cash Management',    'flags' => 'full'],
            ['key' => 'banks_lenders',          'label' => 'Banks & Lenders',      'tab' => 'Master Data',        'flags' => 'no_reverse'],
        ],

        // ── HR ────────────────────────────────────────────────────
        'hr' => [
            ['key' => 'employees',             'label' => 'Employees',            'tab' => 'Master Data',        'flags' => 'no_reverse'],
            ['key' => 'payroll',                'label' => 'Payroll',              'tab' => 'Payroll',            'flags' => 'full'],
            ['key' => 'advances_settlements',   'label' => 'Advances & Settlements','tab'=> 'Payroll',            'flags' => 'full'],
            ['key' => 'leave_management',       'label' => 'Leave Management',     'tab' => 'Attendance',         'flags' => 'no_reverse'],
            ['key' => 'expense_claims',         'label' => 'Expense Claims',       'tab' => 'Attendance',         'flags' => 'full'],
        ],

        // ── Analytics (all view only) ─────────────────────────────
        'analytics' => [
            ['key' => 'cfo_dashboard',          'label' => 'CFO Dashboard',        'tab' => 'Dashboards',         'flags' => 'view_only'],
            ['key' => 'sales_analysis',          'label' => 'Sales Analysis',       'tab' => 'Analysis',           'flags' => 'view_only'],
            ['key' => 'expense_analysis',        'label' => 'Expense Analysis',     'tab' => 'Analysis',           'flags' => 'view_only'],
            ['key' => 'profitability',           'label' => 'Profitability',        'tab' => 'Analysis',           'flags' => 'view_only'],
            ['key' => 'financial_ratios',        'label' => 'Financial Ratios',     'tab' => 'Analysis',           'flags' => 'view_only'],
        ],

        // ── Planning ──────────────────────────────────────────────
        'planning' => [
            ['key' => 'annual_plan',             'label' => 'Annual Plan',          'tab' => 'Planning',           'flags' => 'full'],
            ['key' => 'budget_vs_actual',        'label' => 'Budget vs Actual',     'tab' => 'Planning',           'flags' => 'view_only'],
            ['key' => 'scenario_modeling',       'label' => 'Scenario Modeling',    'tab' => 'Planning',           'flags' => 'full'],
        ],

        // ── Valuation ─────────────────────────────────────────────
        // 'business_plan' relocated here from 'planning' (Sept 2026,
        // Decision 1, confirmed with Mahmoud): Business Plan &
        // Valuation is one Enterprise-only product — you cannot build
        // a Business Plan without also getting Valuation, and a Pro-
        // tier company (which has 'planning' but not 'valuation') was
        // never meant to see Business Plan on its own. It previously
        // sat under 'planning' as a placeholder from before this
        // product split was scoped; moved, not duplicated.
        'valuation' => [
            ['key' => 'business_plan',           'label' => 'Business Plan',        'tab' => 'Valuation',          'flags' => 'full'],
            ['key' => 'dcf_valuation',           'label' => 'DCF Valuation',        'tab' => 'Valuation',          'flags' => 'full'],
            ['key' => 'multiples',               'label' => 'Multiples',            'tab' => 'Valuation',          'flags' => 'full'],
            ['key' => 'scenario_valuation',      'label' => 'Scenario Valuation',   'tab' => 'Valuation',          'flags' => 'full'],
            ['key' => 'investor_report',         'label' => 'Investor Report',      'tab' => 'Reports',            'flags' => 'view_only'],
        ],
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
