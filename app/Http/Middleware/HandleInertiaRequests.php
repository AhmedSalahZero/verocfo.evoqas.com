<?php

namespace App\Http\Middleware;

// ══════════════════════════════════════════════════════════════════
//  HandleInertiaRequests
//
//  Shares global props with every Vue page via usePage().props.
//
//  v1.6 changes:
//    - Financial Institutions pillar fully updated (was 'banking')
//    - Treasury Settings section added with 'Borrowing Rate Update'
//    - Projects & Contracts pillar added
//    - Annual Plan / Business Plan & Valuation renamed
//
//  v1.8 — Purchase Orders nav item added to the Accounting pillar,
//    inside the existing hasInventory() block (same gate as
//    Inventory / Internal Transfers) — the module was built (PO +
//    PO Lines, full CRUD, force-close with reason) but its sidebar
//    entry was missed at the time. Route: accounting.purchase-
//    orders.index. Confirmed placement with Mahmoud: Accounting
//    pillar, not Projects, despite POs carrying an optional project
//    link for Service-with-Inventory companies.
//
//  v1.9 — Accounting pillar converted from one long flat 'items'
//    list to labeled 'groups' (General Ledger / Receivables &
//    Payables / Fixed Assets / Financial Reports / Inventory &
//    Purchasing / Manufacturing), reusing the exact shape already
//    built for Financial Institutions. Groups collapse/expand in
//    the sidebar (AppLayout.vue) — fixes a long, unordered
//    Accounting section. No routes or gating logic changed.
//
//  v1.10 — Goods Receipt Notes nav item added, same 'Inventory &
//    Purchasing' group and hasInventory() gate as Purchase Orders,
//    placed directly beneath it (GRN is PO's direct successor
//    document — see GoodsReceiptNoteService). Route:
//    accounting.goods-receipt-notes.index.
//
//  v1.11 — Factoring With Recourse / Without Recourse nav items
//    added to 'Treasury Operations & Cash Position', directly
//    before Borrowing Rate Update per Mahmoud. Each has its own
//    fully separate index/create — confirmed with Mahmoud these
//    must never share one list or one screen (different products,
//    different lifecycle — Without Recourse has no Reject). See
//    FactoringTransactionService.
//
//  v1.20 — Income Tax nav item (Audit H19) after FX Revaluation, and
//    Fixed Asset Disposals / Revenue Schedules / Sales & Purchase
//    Returns (Audit H16–H18) — see the items themselves.
//
//  v1.19 — FX Revaluation nav item added to the General Ledger
//    group, after General Ledger (Audit H12, Sept 2026). Route:
//    accounting.fx-revaluations.index.
//
//  v1.18 — Variance Closing nav item added to the Manufacturing
//    group, directly after Job Orders (Sept 2026, per Mahmoud) —
//    Manufacturing Variance Closing Design v1.0, Section 1/Step 3.
//    Route: accounting.variance-closing.index. Found missing during
//    the same build, not a separate late audit.
//
//  v1.17 — EOSB Provision nav item added to the HR pillar, directly
//    after Payroll (Sept 2026, per Mahmoud) — a separate monthly run
//    from Payroll Run itself (confirmed decision, not folded in),
//    auto-calculating base_salary × eosb_rate_percent for every
//    enrolled employee and posting Dr 542 / Cr 241. See
//    EosbProvisionRunService header.
//
//  v1.16 — Government Remittance nav item added to the HR pillar,
//    directly after Advances & Settlements (Sept 2026, per Mahmoud) —
//    tracks remittance of the two Payroll-accrued liabilities
//    (226005 Salaries Tax Payable, 240 Social Insurance Payable) to
//    the Tax Authority / Social Insurance Authority, same accrued-
//    minus-remitted balance pattern as Advances, mirrored for
//    liabilities instead of receivables. See
//    GovernmentRemittanceService header.
//
//  v1.15 — Contract Dashboard nav item added to the 'Projects &
//    Contracts' group, right after Sales Orders (Sept 2026, per
//    Mahmoud) — full port of CashVero's Contract Dashboard,
//    structure and design matched exactly. See
//    ContractDashboardService header for the field mapping from
//    CashVero's "Contract" model onto VeroCFO's Project model.
//
//  v1.14 — Safe Statement nav item added to the 'Statement' group,
//    directly after Bank Statement (Sept 2026, per Mahmoud) — a
//    read-only report reusing LedgerStatementService against a
//    safe's own 131xxx Chart of Accounts row (a safe is a real,
//    postable COA account, not a bank_accounts row — see
//    SafeStatementController header). Placed before the v1.13
//    LG/LC/Withdrawal items, matching the build order Mahmoud gave
//    in routes/web.php's own Statement section comment.
//
//  v1.13 — LG Statement, LC Statement, and Withdrawal Statement nav
//    items added to the 'Statement' group, directly after Bank
//    Statement (Sept 2026, per Mahmoud) — read-only reports over
//    lg_issuances/lg_issuance_history, letter_of_credit_issuances/
//    lc_issuance_history, and facility_withdrawals respectively. See
//    LgStatementService / LcStatementService / WithdrawalStatementService.
//
//  v1.12 — Reverse Factoring nav item added right after Factoring
//    Without Recourse per Mahmoud — a Supplier Bill paid early by a
//    factoring company on the company's behalf (COA doc Section
//    8.3), its own dedicated index/create. See
//    ReverseFactoringTransactionService.
//
//  Audit C5 (Sept 2026): the menu is filtered per user — a regular
//  user only sees links whose route guard ('module:...,view,...')
//  they pass. The company block also carries the subscription expiry
//  so AppLayout can show the read-only banner.
//
//  @layer    Middleware (Inertia)
//  @depends  App\Models\Company, App\Models\User
// ══════════════════════════════════════════════════════════════════

use App\Models\Company;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        // ── Resolve the active company ────────────────────────────
        if ($user?->isSuperAdmin()) {
            $companyId = session('impersonate_company_id');
            $company   = $companyId ? Company::find($companyId) : null;
        } else {
            $company = $user?->company;
        }

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $user ? [
                    'id'           => $user->id,
                    'name'         => $user->name,
                    'name_ar'      => $user->name_ar,
                    'email'        => $user->email,
                    'role'         => $user->role,
                    'job_level'    => $user->job_level,
                    'is_active'    => $user->is_active,
                    'company_id'   => $user->company_id,
                    'job_title_en' => $user->job_title_en,
                    'job_title_ar' => $user->job_title_ar,
                    'lang_pref'    => $user->lang_pref,
                    'timezone'     => $user->timezone,
                ] : null,

                'company' => $company ? [
                    'id'                => $company->id,
                    'name_en'           => $company->name_en,
                    'name_ar'           => $company->name_ar,
                    'company_type'      => $company->company_type,
                    'base_currency'     => $company->base_currency,
                    'subscription_tier' => $company->subscription_tier,
                    'is_active'         => $company->is_active,
                    'has_inventory'     => $company->hasInventory(),
                    'is_manufacturing'  => $company->isManufacturing(),
                    'has_mixed_invoices'=> $company->hasMixedInvoices(),
                    // Audit C5 — read-only banner after expiry
                    'subscription_expires_at' => $company->subscription_expires_at?->format('d M Y'),
                    'is_subscription_expired' => $company->isSubscriptionExpired(),
                ] : null,

                'permissions' => $user && $company
                    ? $this->buildPermissions($user, $company)
                    : [],

                'is_impersonating' => $user?->isSuperAdmin()
                    && session()->has('impersonate_company_id'),
            ],

            'navigation' => $user && $company
                ? $this->filterNavigationForUser($this->buildNavigation($company), $user)
                : [],

            'flash' => [
                'success'           => $request->session()->get('success'),
                'error'             => $request->session()->get('error'),
                'warning'           => $request->session()->get('warning'),
                // Customer Invoice "exceeds SO value" warn-and-confirm flow
                // (confirmed with Mahmoud, August 2026) — kept as its own
                // key rather than reusing 'warning' so the Vue page can
                // reliably tell "needs a proceed-anyway confirm" apart from
                // an ordinary informational warning.
                'so_value_exceeded' => $request->session()->get('so_value_exceeded'),
            ],

            // Sidebar/topbar Messages badge. Server-rendered, so it is
            // only ever as fresh as the last Inertia response — the inbox
            // page overrides it live while it is open (useUnreadMessages).
            'unreadMessages' => $user ? Conversation::unreadCountFor($user) : 0,

            'locale' => $user?->lang_pref ?? 'en',
        ];
    }

    // ── Build navigation ──────────────────────────────────────────

    private function buildNavigation(Company $company): array
    {
        $tier     = $company->subscription_tier;
        $allTiers = ['Starter', 'Growth', 'Pro', 'Enterprise'];

        $allPillars = [

            // ── 1. Accounting ─────────────────────────────────────
            //  v1.9 — converted from a single flat 'items' list (had grown
            //  to 9-19 entries depending on company type, in no particular
            //  order) to the same labeled 'groups' shape Financial
            //  Institutions already uses. Groups are collapsible in
            //  AppLayout.vue — each remembers its own expand/collapse
            //  state per pillar. Dashboard stays in an unlabeled,
            //  always-visible group at the top, same pattern as FI's
            //  Dashboard. No routes, keys, or gating logic changed —
            //  purely a re-grouping of the exact same items.
            [
                'key'   => 'accounting',
                'label' => 'Accounting',
                'icon'  => 'ti-book',
                'route' => 'accounting.dashboard',
                'tiers' => $allTiers,
                'groups' => [
                    [
                        'label' => null, // ungrouped — Dashboard sits above the labeled sections
                        'items' => [
                            ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'ti-layout-dashboard', 'route' => 'accounting.dashboard'],
                        ],
                    ],
                    [
                        'label' => 'General Ledger',
                        'items' => [
                            ['key' => 'journal', 'label' => 'Journal Entries', 'icon' => 'ti-clipboard-list', 'route' => 'accounting.journal-entries.index'],
                            // Audit H3 / H5 (Sept 2026) — manual journals and reopen requests awaiting a second person.
                            ['key' => 'approvals', 'label' => 'Approvals', 'icon' => 'ti-checks', 'route' => 'accounting.approvals.index'],
                            ['key' => 'gl',      'label' => 'General Ledger',  'icon' => 'ti-book-2',         'route' => 'accounting.general-ledger.index'],
                            // Audit H12 (Sept 2026) — month-end foreign-currency revaluation (IAS 21).
                            ['key' => 'fx-revaluation', 'label' => 'FX Revaluation', 'icon' => 'ti-arrows-exchange', 'route' => 'accounting.fx-revaluations.index'],
                            // Audit H19 (Sept 2026) — year-end income tax provision and Debit WHT set-off (IAS 12).
                            ['key' => 'income-tax', 'label' => 'Income Tax', 'icon' => 'ti-file-text', 'route' => 'accounting.income-tax.index'],
                        ],
                    ],
                    [
                        // Split from the old single 'Receivables & Payables' group
                        // (August 2026, per Mahmoud) — Customer Debit/Credit Notes
                        // and Supplier Debit/Credit Notes now exist (previously only
                        // Credit Note existed, customer-side only), so one flat list
                        // no longer reads cleanly. Icon convention: ti-trending-up for
                        // whichever note INCREASES this side's balance, ti-trending-down
                        // for whichever DECREASES it — reuses icons already in
                        // AppLayout.vue's ICON_PATHS dictionary, no new SVG needed.
                        'label' => 'Customers Receivables',
                        'items' => [
                            ['key' => 'customer-invoices',     'label' => 'Customer Invoices',      'icon' => 'ti-file-invoice',   'route' => 'accounting.customer-invoices.index'],
                            ['key' => 'customer-debit-notes',  'label' => 'Customer Debit Notes',   'icon' => 'ti-trending-up',    'route' => 'accounting.customer-debit-notes.index'],
                            // Renamed from plain 'Credit Notes' now that the customer
                            // side has a Debit Note sibling right above it.
                            ['key' => 'credit-notes',          'label' => 'Customer Credit Notes',  'icon' => 'ti-trending-down',  'route' => 'accounting.credit-notes.index'],
                            // Audit H18 (Sept 2026) — service contracts invoiced up front, released month by month.
                            ['key' => 'revenue-schedules',     'label' => 'Revenue Schedules',      'icon' => 'ti-report-analytics', 'route' => 'accounting.revenue-schedules.index'],
                            // New (September 2026, per Mahmoud) — currency-tabbed net
                            // balance per customer, drilling into an Invoices Report
                            // and a Customer Statement. Placed right after Customer
                            // Credit Notes since it reads across everything above it
                            // in this group (invoices, debit notes, credit notes).
                            ['key' => 'customer-credits',      'label' => 'Customer Credits',       'icon' => 'ti-wallet',           'route' => 'accounting.customer-credits.index'],
                            ['key' => 'customer-balances',     'label' => 'Customer Balances',      'icon' => 'ti-report-analytics', 'route' => 'accounting.customer-balances.index'],
                        ],
                    ],
                    [
                        'label' => 'Suppliers Payables',
                        'items' => [
                            // Fix (audit 07 July 2026): Supplier Bill and Accruals had a full
                            // backend + Vue frontend already built (Accounting Engine Phase 2)
                            // but no sidebar entry, so neither was reachable in the UI. Placed
                            // here — not inside the hasInventory()-gated 'Inventory & Purchasing'
                            // group below — because both apply to every company type: a Service
                            // company (no GRN) still books Accruals and still receives Supplier
                            // Bills under the 'allow_ad_hoc' grn_policy (see GRN Policy setting).
                            // Ungated in routes/web.php for the same reason — no hasInventory()
                            // check wraps these routes, so the nav shouldn't gate them either.
                            ['key' => 'supplier-bills',        'label' => 'Supplier Bills',         'icon' => 'ti-file-invoice',  'route' => 'accounting.supplier-bills.index'],
                            // New (August 2026) — see SupplierDebitNoteService /
                            // SupplierCreditNoteService: both currently support Fresh
                            // Expense and Accrual-Settlement bill lines only, not Goods
                            // lines yet — a real, acknowledged limitation, not a bug.
                            ['key' => 'supplier-debit-notes',  'label' => 'Supplier Debit Notes',   'icon' => 'ti-trending-down', 'route' => 'accounting.supplier-debit-notes.index'],
                            ['key' => 'supplier-credit-notes', 'label' => 'Supplier Credit Notes',  'icon' => 'ti-trending-up',   'route' => 'accounting.supplier-credit-notes.index'],
                            ['key' => 'accruals',              'label' => 'Accruals',               'icon' => 'ti-file-text',     'route' => 'accounting.accruals.index'],
                            // New (September 2026, per Mahmoud) — payable-side
                            // mirror of Customer Balances. Placed at the very
                            // end of this group (after Accruals), not
                            // interleaved, per Mahmoud's explicit placement.
                            ['key' => 'supplier-balances',     'label' => 'Supplier Balances',      'icon' => 'ti-report-analytics', 'route' => 'accounting.supplier-balances.index'],
                        ],
                    ],
                    [
                        'label' => 'Fixed Assets',
                        'items' => [
                            ['key' => 'fixed-asset-receipts', 'label' => 'Fixed Asset Receipts', 'icon' => 'ti-truck-delivery', 'route' => 'accounting.fixed-asset-receipts.index'],
                            // Renamed from plain 'Fixed Assets' to 'Fixed Asset Register' —
                            // now that Receipts sits right above it in this same group,
                            // the original label was ambiguous between the two.
                            ['key' => 'assets', 'label' => 'Fixed Asset Register', 'icon' => 'ti-building', 'route' => 'accounting.fixed-assets.index'],
                        ],
                    ],
                    // [
                    //     'label' => 'Financial Reports',
                    //     'items' => [
                    //         ['key' => 'pl',            'label' => 'Profit & Loss', 'icon' => 'ti-chart-line',      'route' => 'accounting.pl.index'],
                    //         ['key' => 'balance-sheet', 'label' => 'Balance Sheet', 'icon' => 'ti-scale',           'route' => 'accounting.balance-sheet.index'],
                    //         ['key' => 'cash-flow',     'label' => 'Cash Flow',     'icon' => 'ti-arrows-exchange', 'route' => 'accounting.cash-flow.index'],
                    //     ],
                    // ],
                    ...$company->hasInventory() ? [[
                        'label' => 'Inventory & Purchasing',
                        'items' => [
                            ['key' => 'inventory',         'label' => 'Inventory',           'icon' => 'ti-box',             'route' => 'accounting.inventory.index'],
                            ['key' => 'purchase-orders',   'label' => 'Purchase Orders',     'icon' => 'ti-shopping-cart',   'route' => 'accounting.purchase-orders.index'],
                            ['key' => 'goods-receipt-notes', 'label' => 'Goods Receipt Notes', 'icon' => 'ti-truck-delivery', 'route' => 'accounting.goods-receipt-notes.index'],
                            ['key' => 'purchase-returns', 'label' => 'Purchase Returns', 'icon' => 'ti-truck-return', 'route' => 'accounting.purchase-returns.index'],
                            // See VeroCFO_Landing_Cost_Design_v1_0.md (August 2026) — the record
                            // itself (customs dues, transportation, etc.) is managed here;
                            // attaching one to a receipt happens from the Goods Receipt Note
                            // form right above, at the moment it's posted.
                            ['key' => 'landing-costs', 'label' => 'Landing Costs', 'icon' => 'ti-truck-delivery', 'route' => 'accounting.landing-costs.index'],
                            // Moved here from Receivables & Payables (July 2026, per Mahmoud) —
                            // Delivery Note is inventory-side by nature (it's the outbound
                            // mirror of Goods Receipt Note right above it) and only makes sense
                            // for a company with physical inventory — hasInventory() gating this
                            // whole group is the correct home for it, not the ungated
                            // Receivables & Payables group it started in.
                            ['key' => 'delivery-notes', 'label' => 'Delivery Notes', 'icon' => 'ti-truck-delivery', 'route' => 'accounting.delivery-notes.index'],
                            ['key' => 'sales-returns', 'label' => 'Sales Returns', 'icon' => 'ti-truck-return', 'route' => 'accounting.sales-returns.index'],
                            ['key' => 'transfers',         'label' => 'Internal Transfers',  'icon' => 'ti-arrows-exchange', 'route' => 'accounting.transfers.index'],
                            ['key' => 'stock-adjustments', 'label' => 'Stock Adjustments',   'icon' => 'ti-adjustments',     'route' => 'accounting.stock-adjustments.index'],
                            ['key' => 'inventory-statement', 'label' => 'Inventory Statement', 'icon' => 'ti-report-analytics', 'route' => 'accounting.inventory-statement.index'],
                            ['key' => 'category-value-statement', 'label' => 'Category Value Statement', 'icon' => 'ti-stack', 'route' => 'accounting.category-value-statement.index'],
                            ['key' => 'stock-reservations', 'label' => 'Stock Reservations', 'icon' => 'ti-lock', 'route' => 'accounting.stock-reservations.index'],
                            // Assembly/Kit (VeroCFO_Manufacturing_Flexibility_Design_v1_0.md,
                            // Section 3) — deliberately placed here (hasInventory(), not
                            // isManufacturing()) — a Trading/Service-with-Inventory company
                            // (e.g. a System Integrator) is the primary intended user.
                            ['key' => 'kit-recipes',    'label' => 'Kit Recipes',    'icon' => 'ti-puzzle',   'route' => 'accounting.kit-recipes.index'],
                            ['key' => 'kit-assemblies', 'label' => 'Assemblies',     'icon' => 'ti-stack-2',  'route' => 'accounting.kit-assemblies.index'],
                        ],
                    ]] : [],
                    ...$company->isManufacturing() ? [[
                        'label' => 'Manufacturing',
                        'items' => [
                            ['key' => 'bom',                 'label' => 'Bill of Materials',   'icon' => 'ti-list',           'route' => 'accounting.bom.index'],
                            ['key' => 'overhead-pools',      'label' => 'Overhead Pools',      'icon' => 'ti-stack-2',        'route' => 'accounting.overhead-pools.index'],
                            // Labor Categories (VeroCFO_Manufacturing_Costing_
                            // Redesign_v1_0.md, Section 3) — replaces the single
                            // company-wide blended labor rate.
                            ['key' => 'labor-categories',    'label' => 'Labor Categories',    'icon' => 'ti-users-group',    'route' => 'accounting.labor-categories.index'],
                            // Process & Machine register (VeroCFO_Manufacturing_
                            // Costing_Redesign_v1_0.md, Section 4) — standalone
                            // master data: Process (category of work) and Machine
                            // (the physical Work Center tagged to one Process).
                            ['key' => 'processes',           'label' => 'Processes',               'icon' => 'ti-route',    'route' => 'accounting.processes.index'],
                            ['key' => 'machines',             'label' => 'Machines (Work Centers)', 'icon' => 'ti-engine',   'route' => 'accounting.machines.index'],
                            ['key' => 'standard-rates',      'label' => 'Standard Rates',      'icon' => 'ti-percentage',     'route' => 'accounting.standard-rates.index'],
                            ['key' => 'production',          'label' => 'Production Orders',   'icon' => 'ti-settings-2',     'route' => 'accounting.production.index'],
                            ['key' => 'job-order-templates', 'label' => 'Job Order Templates', 'icon' => 'ti-template',       'route' => 'accounting.job-order-templates.index'],
                            ['key' => 'job-orders',          'label' => 'Job Orders',          'icon' => 'ti-clipboard-list', 'route' => 'accounting.job-orders.index'],
                            // Manufacturing Variance Closing (Manufacturing Variance
                            // Closing Design v1.0, Section 1/Step 3, Sept 2026) —
                            // placed last in this group since it's the month-end
                            // step that comes after everything else above it.
                            ['key' => 'variance-closing',    'label' => 'Variance Closing',    'icon' => 'ti-report-analytics', 'route' => 'accounting.variance-closing.index'],
                        ],
                    ]] : [],
                    [
                        'label' => 'Financial Reports',
                        'items' => [
                            // Trial Balance — real controller (2026-09), replaces
                            // the placeholder that never existed in this group.
                            ['key' => 'trial-balance', 'label' => 'Trial Balance', 'icon' => 'ti-report-analytics', 'route' => 'accounting.trial-balance.index'],
                            ['key' => 'pl',            'label' => 'Profit & Loss', 'icon' => 'ti-chart-line',      'route' => 'accounting.pl.index'],
                            ['key' => 'balance-sheet', 'label' => 'Balance Sheet', 'icon' => 'ti-scale',           'route' => 'accounting.balance-sheet.index'],
                            ['key' => 'cash-flow',     'label' => 'Cash Flow',     'icon' => 'ti-arrows-exchange', 'route' => 'accounting.cash-flow.index'],
                        ],
                    ],
                ],
            ],

            // ── 2. Financial Institutions ─────────────────────────
            //  v1.7 — split into 3 labeled groups per Mahmoud's request
            //    (Accounts & Agreements / Treasury Operations & Cash
            //    Position / Cash Forecast). Uses 'groups' instead of
            //    flat 'items' — see AppLayout.vue sidebar template,
            //    which now supports both shapes (pillars without
            //    'groups' still render as one flat list under the
            //    pillar's own label, unchanged).
            [
                'key'   => 'financial-institutions',
                'label' => 'Financial Institutions',
                'icon'  => 'ti-building-bank',
                'route' => 'fi.dashboard',
                'tiers' => $allTiers,
                'groups' => [
                    [
                        'label' => null, // ungrouped — Dashboard sits above the 3 labeled sections
                        'items' => [
                            ['key' => 'fi-dashboard', 'label' => 'Dashboard', 'icon' => 'ti-layout-dashboard', 'route' => 'fi.dashboard'],
                        ],
                    ],
                    [
                        'label' => 'Accounts & Agreements',
                        'items' => [
                            ['key' => 'banks',         'label' => 'Banks',                'icon' => 'ti-building-bank',   'route' => 'fi.banks.index'],
                            ['key' => 'bank-accounts', 'label' => 'Bank Accounts',        'icon' => 'ti-credit-card',     'route' => 'fi.bank-accounts.index'],
                            ['key' => 'facilities',    'label' => 'Credit Facilities',    'icon' => 'ti-chart-bar',       'route' => 'fi.facilities.index'],
                            ['key' => 'leasing-cos',   'label' => 'Leasing Companies',    'icon' => 'ti-building',        'route' => 'fi.leasing-companies.index'],
                            ['key' => 'leasing',       'label' => 'Leasing Contracts',    'icon' => 'ti-file-text',       'route' => 'fi.leasing-contracts.index'],
                            ['key' => 'factoring-cos', 'label' => 'Factoring Companies',  'icon' => 'ti-briefcase',       'route' => 'fi.factoring-companies.index'],
                            ['key' => 'factoring',     'label' => 'Factoring Agreements', 'icon' => 'ti-file-analytics',  'route' => 'fi.factoring-agreements.index'],
                        ],
                    ],
                    [
                        'label' => 'Treasury Operations & Cash Position',
                        'items' => [
                            ['key' => 'cash-position',  'label' => 'Cash Position',         'icon' => 'ti-wallet',          'route' => 'fi.cash-position.index'],
                            // Moved here from Accounting > Receivables & Payables (July 2026,
                            // per Mahmoud) — both are real treasury/cash-movement records
                            // (a Customer Collection touches a real bank/safe/OD account, a
                            // cheque is tracked through to actual clearing), so they belong
                            // alongside Cash Position rather than sitting in the accounting
                            // documents group. Routes stay under the 'accounting.' prefix —
                            // this is a nav-placement change only, nothing moved on the backend.
                            ['key' => 'customer-payments',  'label' => 'Customer Collection', 'icon' => 'ti-cash', 'route' => 'accounting.customer-payments.index'],
                            // Other Collection (August 2026) — money received from a
                            // Shareholder / Employee / Sister Company / Supplier
                            // (refund) / Other partner. Same placement reasoning as
                            // Customer Collection right above it.
                            ['key' => 'other-collections',  'label' => 'Other Collection',    'icon' => 'ti-cash', 'route' => 'accounting.other-collections.index'],
                            ['key' => 'cheques-receivable', 'label' => 'Cheques Receivable',  'icon' => 'ti-list', 'route' => 'accounting.cheques-receivable.index'],
                            // Supplier Payment (August 2026) — same placement reasoning as
                            // Customer Collection right above it: a real treasury/cash-movement
                            // record, belongs alongside Cash Position. Route stays under the
                            // 'accounting.' prefix, same as Customer Collection.
                            ['key' => 'supplier-payments',  'label' => 'Supplier Payment', 'icon' => 'ti-cash', 'route' => 'accounting.supplier-payments.index'],
                            ['key' => 'cheques-payable',    'label' => 'Cheques Payable',  'icon' => 'ti-list', 'route' => 'accounting.cheques-payable.index'],
                            // Other Payment (August 2026) — money paid to a
                            // Shareholder / Employee / Sister Company / Customer
                            // (refund) / Other partner. Same placement reasoning
                            // as Supplier Payment right above it.
                            ['key' => 'other-payments',     'label' => 'Other Payment',    'icon' => 'ti-cash', 'route' => 'accounting.other-payments.index'],
                            // Cash Expense Payment (August 2026) — a direct expense
                            // payment with no Supplier Bill behind it. Same placement
                            // reasoning as Other Payment right above it: a real
                            // treasury/cash-movement record.
                            ['key' => 'cash-expense-payments', 'label' => 'Cash Expense Payment', 'icon' => 'ti-cash', 'route' => 'accounting.cash-expense-payments.index'],
                            ['key' => 'treasury',       'label' => 'Treasury Operations',   'icon' => 'ti-arrows-exchange', 'route' => 'fi.treasury.index'],
                            // Internal Money Transfer (August 2026) — moves cash between the
                            // company's own accounts (Bank or Safe, either side), with optional
                            // in-transit tracking. See InternalMoneyTransferService.
                            ['key' => 'internal-money-transfers', 'label' => 'Internal Money Transfer', 'icon' => 'ti-transfer', 'route' => 'fi.internal-money-transfers.index'],
                             // Buy/Sell Currency (August 2026) — currency conversion between the
                            // company's own accounts (Bank or Safe, either side); the deal-rate
                            // vs. system-reference-rate spread posts as a real, immediate
                            // realized FX gain/loss (431/571). See BuySellCurrencyService.
                            ['key' => 'buy-sell-currencies', 'label' => 'Buy / Sell Currency', 'icon' => 'ti-arrows-exchange', 'route' => 'fi.buy-sell-currencies.index'],
                            ['key' => 'exchange-rates', 'label' => 'Exchange Rates', 'icon' => 'ti-percentage', 'route' => 'settings.currencies.index'],
                            ['key' => 'lg-issuance',    'label' => 'Letter of Guarantee Issuance', 'icon' => 'ti-shield-check', 'route' => 'fi.lg-issuance.index'],
                            // Letter of Credit Issuance (August 2026) — ported from CashVero's LC
                            // Issuance + Internal Settlement cycle, real GL posting throughout. See
                            // LetterOfCreditIssuanceService class header. Placed directly after LG
                            // Issuance per Mahmoud — same group, same pattern, same reasoning (a
                            // real treasury/cash-movement record tied to one specific instrument).
                            ['key' => 'lc-issuance',    'label' => 'Letter of Credit Issuance', 'icon' => 'ti-credit-card', 'route' => 'fi.lc-issuance.index'],
                            // LC Settlements (August 2026) — standalone cross-LC view, per
                            // Mahmoud's request, separate from each LC's own History table.
                            ['key' => 'lc-settlements', 'label' => 'LC Settlements', 'icon' => 'ti-arrows-exchange', 'route' => 'fi.lc-settlements.index'],
                            // Factoring With/Without Recourse (August 2026) — Direct
                            // Factoring disbursement transactions against a Factoring
                            // Agreement. Each has its own fully separate index/create —
                            // confirmed with Mahmoud these must never share one list or
                            // one screen (different products, different lifecycle —
                            // Without Recourse has no Reject). Both nav items point at
                            // their own index page; each index has its own "New" button.
                            // See FactoringTransactionService.
                            ['key' => 'factoring-with-recourse',    'label' => 'Factoring With Recourse',    'icon' => 'ti-cash', 'route' => 'fi.factoring-with-recourse.index'],
                            ['key' => 'factoring-without-recourse', 'label' => 'Factoring Without Recourse', 'icon' => 'ti-cash', 'route' => 'fi.factoring-without-recourse.index'],
                            // Reverse Factoring (August 2026) — a Supplier Bill paid early
                            // by a factoring company on the company's behalf (COA doc
                            // Section 8.3). Its own dedicated index/create, placed directly
                            // after Factoring Without Recourse per Mahmoud. See
                            // ReverseFactoringTransactionService.
                            ['key' => 'reverse-factoring', 'label' => 'Reverse Factoring', 'icon' => 'ti-cash', 'route' => 'fi.reverse-factoring.index'],
                            ['key' => 'borrowing-rate', 'label' => 'Borrowing Rate Update', 'icon' => 'ti-trending-up',     'route' => 'fi.borrowing-rate.index'],
                        ],
                    ],
                    [
                        'label' => 'Cash Forecast',
                        'items' => [
                            ['key' => 'cash-forecast', 'label' => 'Cash Forecast', 'icon' => 'ti-chart-line', 'route' => 'fi.cash-forecast.index'],
                        ],
                    ],
                    // Statement (September 2026) — treasury-facing reports,
                    // separate from formal accounting reports elsewhere.
                    // Built one at a time; only add an item here once its
                    // report is actually built (Bank Statement first).
                    [
                        'label' => 'Statement',
                        'items' => [
                            ['key' => 'statement-bank',       'label' => 'Bank Statement',       'icon' => 'ti-report-money',   'route' => 'fi.statements.bank.index'],
                            ['key' => 'bank-reconciliation',  'label' => 'Bank Reconciliation',  'icon' => 'ti-checklist',      'route' => 'fi.bank-reconciliation.index'],
                            ['key' => 'statement-safe',       'label' => 'Safe Statement',       'icon' => 'ti-cash',           'route' => 'fi.statements.safe.index'],
                            ['key' => 'statement-lg',         'label' => 'LG / Cash Cover Statement', 'icon' => 'ti-shield-check', 'route' => 'fi.statements.lg.index'],
                            ['key' => 'statement-lc',         'label' => 'LC Statement',         'icon' => 'ti-credit-card',    'route' => 'fi.statements.lc.index'],
                            ['key' => 'statement-withdrawal', 'label' => 'Withdrawal Statement', 'icon' => 'ti-arrows-exchange','route' => 'fi.statements.withdrawal.index'],
                        ],
                    ],
                ],
            ],

            // ── 3. Projects & Contracts ───────────────────────────
            [
                'key'   => 'projects',
                'label' => 'Projects & Contracts',
                'icon'  => 'ti-briefcase',
                'route' => 'projects.index',
                'tiers' => $allTiers,
                'items' => [
                    ['key' => 'contract-dashboard','label' => 'Contract Dashboard',   'icon' => 'ti-chart-dots',   'route' => 'projects.dashboard'],
                    ['key' => 'projects',          'label' => 'Projects & Contracts', 'icon' => 'ti-briefcase',    'route' => 'projects.index'],
                    ['key' => 'sales-orders',      'label' => 'Sales Orders',         'icon' => 'ti-file-invoice', 'route' => 'projects.sales-orders.index'],
                    ['key' => 'sales-orders-dashboard', 'label' => 'Sales Orders Dashboard', 'icon' => 'ti-chart-bar', 'route' => 'projects.sales-orders.dashboard'],
                    ['key' => 'project-inventory', 'label' => 'Project Inventory',    'icon' => 'ti-box',          'route' => 'projects.inventory.index'],
                    ['key' => 'project-pl',        'label' => 'Project P&L',          'icon' => 'ti-chart-pie',    'route' => 'projects.pl.index'],
                ],
            ],

            // ── 4. HR ─────────────────────────────────────────────
            [
                'key'   => 'hr',
                'label' => 'HR',
                'icon'  => 'ti-users',
                'route' => 'hr.dashboard',
                'tiers' => $allTiers,
                'items' => [
                    ['key' => 'dashboard',      'label' => 'Dashboard',              'icon' => 'ti-layout-dashboard', 'route' => 'hr.dashboard'],
                    ['key' => 'employees',      'label' => 'Employees',              'icon' => 'ti-user',             'route' => 'hr.employees.index'],
                    ['key' => 'payroll',        'label' => 'Payroll',                'icon' => 'ti-cash',             'route' => 'hr.payroll.index'],
                    ['key' => 'eosb-provision', 'label' => 'EOSB Provision',         'icon' => 'ti-piggy-bank',       'route' => 'hr.eosb-provision.index'],
                    ['key' => 'deduction-reasons', 'label' => 'Deduction Reasons',   'icon' => 'ti-adjustments',      'route' => 'hr.deduction-reasons.index'],
                    ['key' => 'advances',       'label' => 'Advances & Settlements', 'icon' => 'ti-arrows-exchange',  'route' => 'hr.advances.index'],
                    ['key' => 'government-remittance', 'label' => 'Government Remittance', 'icon' => 'ti-building-bank', 'route' => 'hr.government-remittance.index'],
                    ['key' => 'leave',          'label' => 'Leave Management',       'icon' => 'ti-calendar',         'route' => 'hr.leave.index'],
                    ['key' => 'expense-claims', 'label' => 'Expense Claims',         'icon' => 'ti-receipt',          'route' => 'hr.expense-claims.index'],
                ],
            ],

            // ── 5. Analytics ──────────────────────────────────────
            [
                'key'   => 'analytics',
                'label' => 'Analytics',
                'icon'  => 'ti-chart-bar',
                'route' => 'analytics.dashboard',
                'tiers' => ['Growth', 'Pro', 'Enterprise'],
                'items' => [
                    ['key' => 'dashboard',     'label' => 'CFO Dashboard',      'icon' => 'ti-layout-dashboard', 'route' => 'analytics.dashboard'],
                    // Sales Analysis (Sept 2026) — split into two nav items now
                    // that there are two real screens: a KPI/trend overview
                    // (Dashboard) and the 8-report-type engine (Reports),
                    // ported from CashVero's CFO Tools. See
                    // SalesAnalysisService header for the data-source note.
                    ['key' => 'sales',         'label' => 'Sales Dashboard',    'icon' => 'ti-trending-up',      'route' => 'analytics.sales.index'],
                    ['key' => 'sales-reports', 'label' => 'Sales Reports',      'icon' => 'ti-report-analytics', 'route' => 'analytics.sales.reports'],
                    // Expense Analysis (Sept 2026) — same Dashboard/Reports
                    // split as Sales Analysis. Breakeven uses the existing
                    // ExpenseAnalysisService::breakeven() calculation.
                    ['key' => 'expenses',         'label' => 'Expense Dashboard', 'icon' => 'ti-trending-down',    'route' => 'analytics.expenses.index'],
                    ['key' => 'expenses-reports', 'label' => 'Expense Reports',   'icon' => 'ti-report-analytics', 'route' => 'analytics.expenses.reports'],
                    ['key' => 'expenses-breakeven', 'label' => 'Breakeven',     'icon' => 'ti-scale',            'route' => 'analytics.expenses.breakeven'],
                    ['key' => 'profitability', 'label' => 'Profitability',      'icon' => 'ti-chart-pie',        'route' => 'analytics.profitability.index'],
                    ['key' => 'ratios',        'label' => 'Financial Ratios',   'icon' => 'ti-math',             'route' => 'analytics.ratios.index'],
                ],
            ],

            // ── 6. Annual Plan ────────────────────────────────────
            [
                'key'   => 'planning',
                'label' => 'Annual Plan',
                'icon'  => 'ti-target',
                'route' => 'planning.dashboard',
                'tiers' => ['Pro', 'Enterprise'],
                'items' => [
                    ['key' => 'dashboard',   'label' => 'Dashboard',          'icon' => 'ti-layout-dashboard', 'route' => 'planning.dashboard'],
                    ['key' => 'annual-plan', 'label' => 'Annual Plan',        'icon' => 'ti-calendar',         'route' => 'planning.annual-plan.index'],
                    ['key' => 'bva',         'label' => 'Budget vs Actual',   'icon' => 'ti-chart-bar',        'route' => 'planning.bva.index'],
                    ['key' => 'scenarios',   'label' => 'Scenario Modeling',  'icon' => 'ti-arrows-split-2',   'route' => 'planning.scenarios.index'],
                ],
            ],

            // ── 7. Business Plan & Valuation ──────────────────────
            [
                'key'   => 'valuation',
                'label' => 'Business Plan & Valuation',
                'icon'  => 'ti-diamond',
                'route' => 'valuation.dashboard',
                'tiers' => ['Enterprise'],
                'items' => [
                    ['key' => 'dashboard',     'label' => 'Dashboard',           'icon' => 'ti-layout-dashboard', 'route' => 'valuation.dashboard'],
                    ['key' => 'business-plan', 'label' => 'Business Plan & Valuation','icon' => 'ti-file-analytics',   'route' => 'valuation.business-plan.index'],
                    ['key' => 'dcf',           'label' => 'DCF Valuation',       'icon' => 'ti-chart-line',       'route' => 'valuation.dcf.index'],
                    ['key' => 'multiples',     'label' => 'Multiples',           'icon' => 'ti-math',             'route' => 'valuation.multiples.index'],
                    ['key' => 'scenarios',     'label' => 'Scenario Valuation',  'icon' => 'ti-arrows-split-2',   'route' => 'valuation.scenarios.index'],
                    ['key' => 'report',        'label' => 'Investor Report',     'icon' => 'ti-file-analytics',   'route' => 'valuation.report.index'],
                ],
            ],
        ];

        return array_values(
            array_filter($allPillars, fn ($p) => in_array($tier, $p['tiers'], true))
        );
    }

    // ── Build permission map ──────────────────────────────────────

    private function buildPermissions($user, Company $company): array
    {
        // Load the permission rows ONCE — canOnModule/canOnFeature use
        // the loaded relation instead of one query per check.
        $user->loadMissing('modulePermissions');

        $permissions = [];
        $actions     = ['view', 'create', 'edit', 'post', 'reverse', 'approve']; // post/approve: Audit H3

        if (!class_exists(\App\Models\UserModulePermission::class)) {
            return [];
        }

        foreach (\App\Models\UserModulePermission::FEATURES as $module => $features) {
            $permissions["{$module}.view"] = $user->canOnModule('view', $module);
            foreach ($features as $featureDef) {
                $feature = $featureDef['key'];
                foreach ($actions as $action) {
                    $permissions["{$module}.{$feature}.{$action}"] =
                        $user->canOnFeature($action, $module, $feature);
                }
            }
        }

        return $permissions;
    }

    // ── Hide menu links the user may not open (Audit C5) ──────────
    // Every screen route now carries 'module:<module>,view[,<feature>]'.
    // For a regular user, each menu item's route is checked against
    // that guard; items they cannot open are removed, empty groups are
    // removed, and a pillar with nothing left is removed. The pillar's
    // own landing link falls back to its first remaining item.
    // Super Admin and Company Admin see the full (tier-filtered) menu.
    // The server still refuses the screen itself — this only keeps the
    // menu honest.

    private function filterNavigationForUser(array $pillars, $user): array
    {
        if ($user->isSuperAdmin() || $user->isCompanyAdmin()) {
            return $pillars;
        }

        $user->loadMissing('modulePermissions');
        $out = [];

        foreach ($pillars as $pillar) {
            $first = null;

            if (isset($pillar['items'])) {
                $pillar['items'] = $this->allowedItems($pillar['items'], $user, $first);
            }

            if (isset($pillar['groups'])) {
                $groups = [];
                foreach ($pillar['groups'] as $group) {
                    $group['items'] = $this->allowedItems($group['items'] ?? [], $user, $first);
                    if ($group['items']) {
                        $groups[] = $group;
                    }
                }
                $pillar['groups'] = $groups;
            }

            if ($first === null) {
                continue; // nothing in this pillar the user may open
            }

            if (isset($pillar['route']) && ! $this->userMayOpen($pillar['route'], $user)) {
                $pillar['route'] = $first;
            }

            $out[] = $pillar;
        }

        return $out;
    }

    private function allowedItems(array $items, $user, ?string &$first): array
    {
        $kept = [];

        foreach ($items as $item) {
            if (! empty($item['route']) && ! $this->userMayOpen($item['route'], $user)) {
                continue;
            }
            if ($first === null && ! empty($item['route'])) {
                $first = $item['route'];
            }
            $kept[] = $item;
        }

        return array_values($kept);
    }

    private function userMayOpen(string $routeName, $user): bool
    {
        $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName($routeName);

        if (! $route) {
            return true; // unknown route — leave the item as it was
        }

        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw) && str_starts_with($mw, 'module:')) {
                $parts   = explode(',', substr($mw, 7));
                $module  = $parts[0] ?? '';
                $action  = $parts[1] ?? 'view';
                $feature = $parts[2] ?? '';

                return $feature !== ''
                    ? $user->canOnFeature($action, $module, $feature)
                    : $user->canOnModule($action, $module);
            }
            if ($mw === 'admin') {
                return false; // regular users never pass the admin lock
            }
        }

        return true;
    }
}