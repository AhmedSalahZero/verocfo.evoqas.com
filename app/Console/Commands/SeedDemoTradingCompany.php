<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  SeedDemoTradingCompany
//
//  Creates one complete, realistic Trading company — "Nile Star
//  Trading Co." — with 5 product categories (4 products each),
//  a full partner book (customers / suppliers / employees /
//  shareholders), two banks with current accounts + Clean Overdraft
//  facilities, a cash safe, and eight months (Jan–Aug 2026) of real
//  transactions run through the ACTUAL application services — every
//  document is created and posted exactly the way the UI would do
//  it, so the journal entries, stock ledger, AR/AP aging and every
//  financial report are all populated with real, self-consistent
//  data for testing.
//
//  WHY A CONSOLE COMMAND: this is a one-time data-generation tool,
//  not a feature of the app itself — it belongs in Console/Commands,
//  not behind a route. It calls the same Services the controllers
//  call, in the same create → activate/post sequence, so nothing
//  here bypasses the app's real business rules (WAC costing, GRNI
//  clearing, VAT/WHT, journal posting) — it just drives them
//  programmatically instead of through clicks.
//
//  USAGE:
//    php artisan demo:seed-trading-company
//
//  Safe to re-run: it always creates a NEW company (named with a
//  timestamp suffix if "Nile Star Trading Co." already exists), so
//  re-running never corrupts a previous run — just gives you a
//  fresh one. Each transaction block is wrapped in try/catch so one
//  failure never stops the whole run; failures are collected and
//  printed in a summary at the end.
//
//  Audit H8 (Sept 2026): refuses to run when APP_ENV=production
//  (RefusesInProduction trait).
//
//  @layer    Console Command
//  @depends  CompanyService, ProductCategoryService, ProductService,
//            PartnerService, ChartOfAccountService,
//            FinancialInstitutions\BankService,
//            FinancialInstitutions\CleanOverdraftService,
//            FinancialInstitutions\InternalMoneyTransferService,
//            Projects\SalesOrderService, Accounting\DeliveryNoteService,
//            Accounting\CustomerInvoiceService, Accounting\CustomerPaymentService,
//            Accounting\PurchaseOrderService, Accounting\GoodsReceiptNoteService,
//            Accounting\SupplierBillService, Accounting\SupplierPaymentService,
//            Accounting\AccrualService, Accounting\CashExpensePaymentService,
//            Accounting\OtherCollectionService, Accounting\OtherPaymentService
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Currency;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\InventoryLocation;
use App\Models\Partner;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SalesOrder;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Models\CustomerInvoice;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\Accounting\AccrualService;
use App\Services\Accounting\CashExpensePaymentService;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\CustomerPaymentService;
use App\Services\Accounting\DeliveryNoteService;
use App\Services\Accounting\GoodsReceiptNoteService;
use App\Services\Accounting\OtherCollectionService;
use App\Services\Accounting\OtherPaymentService;
use App\Services\Accounting\PurchaseOrderService;
use App\Services\Accounting\SupplierBillService;
use App\Services\Accounting\SupplierPaymentService;
use App\Services\ChartOfAccountService;
use App\Services\CompanyService;
use App\Services\FinancialInstitutions\BankService;
use App\Services\FinancialInstitutions\CleanOverdraftService;
use App\Services\FinancialInstitutions\InternalMoneyTransferService;
use App\Services\PartnerService;
use App\Services\ProductCategoryService;
use App\Services\ProductService;
use App\Services\Projects\SalesOrderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SeedDemoTradingCompany extends Command
{
    use \App\Console\Concerns\RefusesInProduction; // Audit H8

    protected $signature = 'demo:seed-trading-company';

    protected $description = 'Creates a demo Trading company (Growth tier) with 8 months of real, posted transactions (Jan–Aug 2026) for testing.';

    // ── Services (resolved in handle()) ─────────────────────────
    private CompanyService $companyService;
    private ProductCategoryService $categoryService;
    private ProductService $productService;
    private PartnerService $partnerService;
    private ChartOfAccountService $coaService;
    private BankService $bankService;
    private CleanOverdraftService $odService;
    private InternalMoneyTransferService $imtService;
    private SalesOrderService $soService;
    private DeliveryNoteService $dnService;
    private CustomerInvoiceService $ciService;
    private CustomerPaymentService $cpService;
    private PurchaseOrderService $poService;
    private GoodsReceiptNoteService $grnService;
    private SupplierBillService $sbService;
    private SupplierPaymentService $spService;
    private AccrualService $accrualService;
    private CashExpensePaymentService $cashExpService;
    private OtherCollectionService $ocService;
    private OtherPaymentService $opService;

    // ── Runtime state ────────────────────────────────────────────
    private User $actor;
    private Company $company;
    private int $companyId;
    private int $egpId;
    private int $net30Id;
    private int $masterLocationId;
    private int $safeAccountId;

    /** @var array<int, array{model: Product, cost: float, price: float}> */
    private array $products = [];
    /** @var Partner[] */
    private array $customers = [];
    /** @var Partner[] */
    private array $suppliers = [];
    /** @var Partner[] */
    private array $employees = [];
    /** @var Partner[] */
    private array $shareholders = [];
    /** @var array<int, array{bank: Bank, account: BankAccount}> */
    private array $banks = [];
    /** @var CreditFacility[] */
    private array $facilities = [];
    /** @var array<string, int> expense account name => chart_of_accounts id */
    private array $expenseAccounts = [];

    /** @var string[] running log of failures, printed at the end */
    private array $failures = [];
    private int $successCount = 0;

    public function handle(
        CompanyService $companyService,
        ProductCategoryService $categoryService,
        ProductService $productService,
        PartnerService $partnerService,
        ChartOfAccountService $coaService,
        BankService $bankService,
        CleanOverdraftService $odService,
        InternalMoneyTransferService $imtService,
        SalesOrderService $soService,
        DeliveryNoteService $dnService,
        CustomerInvoiceService $ciService,
        CustomerPaymentService $cpService,
        PurchaseOrderService $poService,
        GoodsReceiptNoteService $grnService,
        SupplierBillService $sbService,
        SupplierPaymentService $spService,
        AccrualService $accrualService,
        CashExpensePaymentService $cashExpService,
        OtherCollectionService $ocService,
        OtherPaymentService $opService,
    ): int {
        // Audit H8 — never on the live server
        if ($this->refuseInProduction()) {
            return 1;
        }

        $this->companyService  = $companyService;
        $this->categoryService = $categoryService;
        $this->productService  = $productService;
        $this->partnerService  = $partnerService;
        $this->coaService      = $coaService;
        $this->bankService     = $bankService;
        $this->odService       = $odService;
        $this->imtService      = $imtService;
        $this->soService       = $soService;
        $this->dnService       = $dnService;
        $this->ciService       = $ciService;
        $this->cpService       = $cpService;
        $this->poService       = $poService;
        $this->grnService      = $grnService;
        $this->sbService       = $sbService;
        $this->spService       = $spService;
        $this->accrualService  = $accrualService;
        $this->cashExpService  = $cashExpService;
        $this->ocService       = $ocService;
        $this->opService       = $opService;

        $this->actor = User::where('role', User::ROLE_SUPER_ADMIN)->first();
        if (! $this->actor) {
            $this->error('No Super Admin user found — run SuperAdminSeeder first (php artisan db:seed --class=SuperAdminSeeder).');
            return self::FAILURE;
        }

        $this->egpId  = (int) Currency::withoutGlobalScopes()->whereNull('company_id')->where('code', 'EGP')->value('id');
        $this->net30Id = (int) PaymentTerm::withoutGlobalScopes()->whereNull('company_id')->where('code', 'NET30')->value('id');
        if (! $this->egpId || ! $this->net30Id) {
            $this->error('EGP currency or NET30 payment term not found — run "php artisan db:seed" first to load system data.');
            return self::FAILURE;
        }

        app()->instance('currentCompanyId', null); // placeholder, set after company exists

        $this->info('── Step 1/6 — Creating the company ──────────────────');
        $this->createCompany();

        $this->info('── Step 2/6 — Product categories & products ─────────');
        $this->createCatalogue();

        $this->info('── Step 3/6 — Partners (customers/suppliers/employees/shareholders) ─');
        $this->createPartners();

        $this->info('── Step 4/6 — Treasury (banks, safe, OD facilities) ─');
        $this->createTreasury();

        $this->info('── Step 5/6 — Custom G&A/Sales/Marketing expense accounts ─');
        $this->createExpenseAccounts();

        $this->info('── Step 6/6 — 8 months of transactions (Jan–Aug 2026) ─');
        $this->runTransactions();

        $this->newLine();
        $this->info("Done. {$this->successCount} documents posted successfully.");
        if ($this->failures) {
            $this->warn(count($this->failures).' step(s) failed — see below. Everything else was posted fine; re-run individual fixes later if needed.');
            foreach ($this->failures as $f) {
                $this->line(' - '.$f);
            }
        }
        $this->info("Company: {$this->company->name_en} (id={$this->companyId})");

        // SEED-001 fix (2026-09) — a run with failed steps exits
        // NON-ZERO. It used to print the failures and then return
        // SUCCESS, so CI, a deploy script, or anything reading the
        // exit code saw a clean run while business operations inside
        // it had thrown and been swallowed. The data is still kept —
        // partial demo data is useful and this is a generator, not a
        // test — but the run no longer claims to have worked.
        if ($this->failures) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 1 — Company
    // ══════════════════════════════════════════════════════════════

    private function createCompany(): void
    {
        $name = 'Nile Star Trading Co.';
        if (Company::withoutGlobalScopes()->where('name_en', $name)->exists()) {
            $name .= ' ('.now()->format('H:i:s').')';
        }

        $this->company = $this->companyService->create([
            'name_en'                 => $name,
            'name_ar'                 => 'شركة نجمة النيل التجارية',
            'company_type'            => ['Trading'],
            'grn_policy'               => 'po_required',
            'base_currency'            => 'EGP',
            'fiscal_year_start_month'  => 1,
            'opening_balance_date'     => '2026-01-01',
            'subscription_expires_at'  => '2035-12-31',
            'country'                  => 'Egypt',
            'subscription_tier'        => 'Growth',
            'is_active'                => true,
        ], $this->actor);

        $this->companyId = $this->company->id;
        app()->instance('currentCompanyId', $this->companyId);

        $master = InventoryLocation::where('company_id', $this->companyId)
            ->where('type', InventoryLocation::TYPE_MASTER)->first();
        $this->masterLocationId = $master->id;

        $this->line("  Company created: {$this->company->name_en} (id={$this->companyId})");
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 2 — Product Categories & Products
    // ══════════════════════════════════════════════════════════════

    private function createCatalogue(): void
    {
        $unitId = (int) UnitOfMeasure::withoutGlobalScopes()->whereNull('company_id')->where('code', 'UNIT')->value('id');

        $catalogue = [
            'Electronics'          => ['43-inch LED TV', 'Bluetooth Speaker', 'Wireless Router', 'Laptop Stand'],
            'Home Appliances'      => ['Electric Kettle', 'Microwave Oven', 'Blender', 'Air Fryer'],
            'Office Supplies'      => ['A4 Paper Ream (Box of 5)', 'Ergonomic Office Chair', 'Desk Organizer Set', 'Whiteboard 90x60'],
            'Furniture'            => ['3-Seater Fabric Sofa', '6-Piece Dining Set', 'Bookshelf 5-Tier', 'Queen Bed Frame'],
            'Packaging Materials'  => ['Corrugated Boxes (Bundle of 25)', 'Bubble Wrap Roll 50m', 'Packing Tape (Case of 36)', 'Stretch Film Roll'],
        ];

        $catIndex = 0;
        foreach ($catalogue as $catName => $productNames) {
            $category = $this->categoryService->create($this->companyId, [
                'name_en' => $catName,
            ], $this->actor);
            $this->line("  Category: {$catName}");

            $prodIndex = 0;
            foreach ($productNames as $prodName) {
                $product = $this->productService->create($this->companyId, [
                    'use_auto_code'   => true,
                    'name_en'         => $prodName,
                    'category_id'     => $category->id,
                    'usage_types'     => [Product::TYPE_SALEABLE],
                    'primary_uom_id'  => $unitId,
                    'reorder_level'   => 20,
                    'reorder_qty'     => 100,
                ], $this->actor);

                // Deterministic pseudo-random cost/price so the run is
                // repeatable — cost grows a little category-to-category,
                // price carries a realistic ~40% trading margin.
                $seed = $catIndex * 4 + $prodIndex;
                $cost = round(180 + $seed * 137.5, 2);
                $price = round($cost * 1.42, 2);

                $this->products[] = ['model' => $product, 'cost' => $cost, 'price' => $price];
                $prodIndex++;
            }
            $catIndex++;
        }

        $this->line('  '.count($this->products).' products created across 5 categories.');
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 3 — Partners
    // ══════════════════════════════════════════════════════════════

    private function createPartners(): void
    {
        $customerNames = [
            'Nile Retail Group', 'Delta Supermarkets Chain', 'Alexandria Wholesale Trading',
            'Cairo Mall Distributors', 'Upper Egypt Commercial Co.',
        ];
        foreach ($customerNames as $name) {
            $this->customers[] = $this->partnerService->create($this->companyId, [
                'name_en'             => $name,
                'types'                => ['customer'],
                'credit_limit'         => 300000,
                'collection_term_id'   => $this->net30Id,
                'country'              => 'Egypt',
            ], $this->actor);
        }

        $supplierNames = [
            'Global Import Export Ltd.', 'East Mediterranean Trading', 'Suez Logistics & Supply',
            'Nasr City Wholesale Suppliers', 'Ahmed Fathy & Co. — Audit & Legal Services',
        ];
        foreach ($supplierNames as $name) {
            $this->suppliers[] = $this->partnerService->create($this->companyId, [
                'name_en'          => $name,
                'types'             => ['supplier'],
                'payment_term_id'   => $this->net30Id,
                'country'           => 'Egypt',
            ], $this->actor);
        }

        $employeeNames = [
            ['Mona Youssef', 'Finance Manager'],
            ['Khaled Ibrahim', 'Sales Representative'],
            ['Sara Adel', 'Warehouse Supervisor'],
            ['Tamer Hassan', 'Accountant'],
        ];
        foreach ($employeeNames as [$name, $title]) {
            $this->employees[] = $this->partnerService->create($this->companyId, [
                'name_en'     => $name,
                'types'        => ['employee'],
                'job_title'    => $title,
                'department'   => 'Operations',
            ], $this->actor);
        }

        $shareholderData = [
            ['Mahmoud Youssef', 70],
            ['Youssef Family Holding', 30],
        ];
        foreach ($shareholderData as [$name, $pct]) {
            $this->shareholders[] = $this->partnerService->create($this->companyId, [
                'name_en'     => $name,
                'types'        => ['shareholder'],
                'equity_pct'   => $pct,
            ], $this->actor);
        }

        $this->line('  '.count($this->customers).' customers, '.count($this->suppliers).' suppliers, '
            .count($this->employees).' employees, '.count($this->shareholders).' shareholders created.');
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 4 — Treasury: Banks, Bank Accounts, Safe, OD Facilities
    // ══════════════════════════════════════════════════════════════

    private function createTreasury(): void
    {
        $banksParentId = $this->coaByCode('132'); // Banks (group, not postable)
        $cashParentId  = $this->coaByCode('131'); // Cash (group, not postable)
        $od223001Id    = $this->coaByCode('223001'); // Clean Overdraft (shared postable leaf)

        // ── Safe (cash on hand) ─────────────────────────────────
        $safeAccount = $this->coaService->create($this->companyId, [
            'parent_id'      => $cashParentId,
            'use_auto_code'  => true,
            'name_en'        => 'Main Safe',
            'name_ar'        => 'الخزينة الرئيسية',
        ], $this->actor);
        $this->safeAccountId = $safeAccount->id;
        $this->line('  Safe account created: Main Safe');

        // ── Banks + current accounts + Clean OD facilities ──────
        $bankDefs = [
            'Commercial International Bank (CIB)',
            'National Bank of Egypt (NBE)',
        ];

        foreach ($bankDefs as $bankName) {
            $bank = $this->bankService->createBank($this->companyId, [
                'name_en' => $bankName,
                'is_active' => true,
            ], $this->actor);

            $coaLeaf = $this->coaService->create($this->companyId, [
                'parent_id'      => $banksParentId,
                'use_auto_code'  => true,
                'name_en'        => "{$bankName} — Current Account EGP",
            ], $this->actor);

            $account = $this->bankService->createBankAccount($this->companyId, $bank, [
                'account_name'    => "{$bankName} — Current Account",
                'account_number'  => '100'.random_int(1000000, 9999999),
                'account_type'    => 'current',
                'currency_id'     => $this->egpId,
                'coa_account_id'  => $coaLeaf->id,
                'is_active'       => true,
            ], $this->actor);

            $this->banks[] = ['bank' => $bank, 'account' => $account];

            $facility = $this->odService->create($this->companyId, [
                'bank_id'               => $bank->id,
                'start_date'            => '2026-01-01',
                'review_date'           => '2027-01-01',
                'account_number'        => 'OD-'.random_int(100000, 999999),
                'currency_id'           => $this->egpId,
                'coa_account_id'        => $od223001Id,
                'facility_limit'        => 2000000,
                'outstanding_balance'   => 0,
                'balance_date'          => '2026-01-01',
                'borrowing_rate'        => 18,
                'bank_margin_rate'      => 3,
                'min_interest_rate'     => 1,
                'hdb_rate'              => 0.5,
                'admin_fee_rate'        => 0.25,
                'settled_max_days'      => 90,
                'follows_corridor_rate' => false,
                'purpose'               => 'Working capital line',
            ], $this->actor);

            // CleanOverdraftService::create() never links a bank_account_id
            // (confirmed in CashStatusDashboardService's own header comment
            // — every OD facility posts straight to its coa_account_id and
            // nothing in the app's create/update flow sets this column).
            // But InternalMoneyTransferService and every payment service
            // that lets a facility act as a money-movement leg (source_type
            // / destination_type = credit_facility) requires exactly this
            // link to exist. It's a genuine gap in the app, not just this
            // script — worth a real fix later. For now, link it directly
            // (the column is fillable) so facility draws/repayments work.
            $facility->update(['bank_account_id' => $account->id]);

            $this->facilities[] = $facility;

            $this->line("  Bank: {$bankName} — current account + Clean Overdraft facility (limit 2,000,000 EGP)");
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 5 — Custom expense leaf accounts (G&A / Sales / Marketing)
    // ══════════════════════════════════════════════════════════════

    private function createExpenseAccounts(): void
    {
        $gaParentId  = $this->coaByCode('54'); // General & Administrative Expenses
        $salesParentId = $this->coaByCode('52'); // Sales & Distribution Expenses
        $mktParentId = $this->coaByCode('53'); // Marketing Expenses

        $leaves = [
            ['Office Rent',                       $gaParentId],
            ['Utilities — Electricity & Water',   $gaParentId],
            ['Telecom & Internet',                $gaParentId],
            ['Office Supplies Expense',            $gaParentId],
            ['Professional Fees — Audit & Legal',  $gaParentId],
            ['Insurance Expense',                  $gaParentId],
            ['Delivery & Freight Out',             $salesParentId],
            ['Sales Commissions',                  $salesParentId],
            ['Advertising & Promotions',           $mktParentId],
        ];

        foreach ($leaves as [$name, $parentId]) {
            $account = $this->coaService->create($this->companyId, [
                'parent_id'     => $parentId,
                'use_auto_code' => true,
                'name_en'       => $name,
            ], $this->actor);
            $this->expenseAccounts[$name] = $account->id;
        }

        $this->line('  '.count($this->expenseAccounts).' expense accounts created.');
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 6 — Eight months of transactions
    // ══════════════════════════════════════════════════════════════

    /** @var SupplierBill[] open AP, oldest first */
    private array $openSupplierBills = [];
    /** @var CustomerInvoice[] open AR, oldest first */
    private array $openCustomerInvoices = [];

    private function runTransactions(): void
    {
        $this->seedInitialStock();

        for ($m = 0; $m < 8; $m++) {
            $monthStart = Carbon::create(2026, 1, 1)->addMonths($m);
            $this->line('');
            $this->info('  Month '.($m + 1).' — '.$monthStart->format('F Y'));

            $this->monthlyPurchaseCycle($monthStart, $m);
            $this->monthlySalesCycle($monthStart, $m);
            $this->monthlyTreasuryMovements($monthStart, $m);
            $this->monthlyExpenses($monthStart, $m);
            $this->monthlyAccrualsAndPrepaid($monthStart, $m);
            $this->settleAgingPayables($monthStart);
            $this->settleAgingReceivables($monthStart);
        }
    }

    // ── Initial stock build-up (2026-01-01) ──────────────────────
    //
    // Sales start on day 2 of the run, but purchases only trickle
    // in a handful of products a month — without this, the first
    // few months' Sales Orders would try to deliver products that
    // haven't been received yet and every one of those Delivery
    // Notes would fail (StockLedgerService::issue() correctly
    // refuses to ship stock that isn't there). This one-time intake
    // — split across the 4 goods suppliers, ~100 units of every one
    // of the 20 products — guarantees every product already has
    // stock on hand before the very first Sales Order is created.

    private function seedInitialStock(): void
    {
        try {
            $chunks = array_chunk($this->products, (int) ceil(count($this->products) / 4));

            foreach ($chunks as $i => $chunk) {
                $supplier = $this->suppliers[$i % 4];

                $lines = [];
                foreach ($chunk as $p) {
                    $lines[] = [
                        'product_id'   => $p['model']->id,
                        'description'  => $p['model']->name_en,
                        'quantity'     => 175,
                        'uom_id'       => $p['model']->primary_uom_id,
                        'unit_price'   => $p['cost'],
                    ];
                }

                $po = $this->poService->create($this->companyId, [
                    'partner_id'      => $supplier->id,
                    'subject'         => 'Opening stock build-up',
                    'currency_id'     => $this->egpId,
                    'exchange_rate'   => 1,
                    'issuance_date'   => '2026-01-01',
                    'start_date'      => '2026-01-01',
                    'end_date'        => '2026-01-31',
                    'payment_term_id' => $this->net30Id,
                    'lines'           => $lines,
                ], $this->actor);
                $this->poService->activate($po, $this->actor);

                $grnLines = [];
                foreach ($po->lines as $line) {
                    $grnLines[] = [
                        'purchase_order_line_id' => $line->id,
                        'product_id'              => $line->product_id,
                        'quantity'                 => $line->quantity,
                        'uom_id'                   => $line->uom_id,
                        'unit_cost'                => $line->unit_price,
                    ];
                }
                $grn = $this->grnService->create($this->companyId, [
                    'purchase_order_id'      => $po->id,
                    'partner_id'              => $supplier->id,
                    'inventory_location_id'   => $this->masterLocationId,
                    'received_date'           => '2026-01-01',
                    'lines'                   => $grnLines,
                ], $this->actor);
                $grn = $this->grnService->post($grn, $this->actor);

                $billLines = [];
                foreach ($grn->lines as $line) {
                    $billLines[] = [
                        'goods_receipt_note_line_id' => $line->id,
                        'product_id'                   => $line->product_id,
                        'description'                  => $line->product?->name_en ?? 'Goods',
                        'quantity'                      => $line->quantity,
                        'uom_id'                        => $line->uom_id,
                        'unit_price'                    => $line->unit_cost,
                        'vat_rate'                       => 14,
                        'is_deductible'                  => true,
                        'withholding_tax_rate'           => 1,
                    ];
                }
                $bill = $this->sbService->create($this->companyId, [
                    'bill_type'               => 'goods',
                    'partner_id'               => $supplier->id,
                    'purchase_order_id'        => $po->id,
                    'supplier_invoice_number'  => 'OPEN-STOCK-'.($i + 1),
                    'supplier_invoice_date'    => '2026-01-01',
                    'bill_date'                => '2026-01-01',
                    'currency_id'              => $this->egpId,
                    'payment_term_id'          => $this->net30Id,
                    'lines'                    => $billLines,
                ], $this->actor);
                $bill = $this->sbService->post($bill, $this->actor);
                $this->openSupplierBills[] = $bill;
                $this->successCount += 3;
            }

            $this->line('  Initial stock build-up posted — 175 units of every product, dated 2026-01-01.');
        } catch (\Throwable $e) {
            $this->recordFailure('Initial stock build-up', $e);
        }
    }

    // ── Purchase cycle: PO → GRN → Supplier Bill ────────────────

    private function monthlyPurchaseCycle(Carbon $monthStart, int $m): void
    {
        for ($i = 0; $i < 2; $i++) {
            try {
                $supplier = $this->suppliers[($m * 2 + $i) % 4]; // rotate the 4 goods suppliers, skip the audit/legal one
                $issuance = (clone $monthStart)->addDays(2 + $i * 5);

                $lines = [];
                $picked = $this->pickProducts(3, $m * 10 + $i);
                foreach ($picked as $p) {
                    $qty = random_int(20, 80);
                    $lines[] = [
                        'product_id'   => $p['model']->id,
                        'description'  => $p['model']->name_en,
                        'quantity'     => $qty,
                        'uom_id'       => $p['model']->primary_uom_id,
                        'unit_price'   => $p['cost'],
                    ];
                }

                $po = $this->poService->create($this->companyId, [
                    'partner_id'      => $supplier->id,
                    'subject'         => 'Stock replenishment',
                    'currency_id'     => $this->egpId,
                    'exchange_rate'   => 1,
                    'issuance_date'   => $issuance->toDateString(),
                    'start_date'      => $issuance->toDateString(),
                    'end_date'        => (clone $issuance)->addDays(30)->toDateString(),
                    'payment_term_id' => $this->net30Id,
                    'lines'           => $lines,
                ], $this->actor);
                $this->poService->activate($po, $this->actor);
                $this->successCount++;

                // ── GRN — receive in full, a few days later ──────
                $receivedDate = (clone $issuance)->addDays(5);
                $grnLines = [];
                foreach ($po->lines as $line) {
                    $grnLines[] = [
                        'purchase_order_line_id' => $line->id,
                        'product_id'              => $line->product_id,
                        'quantity'                 => $line->quantity,
                        'uom_id'                   => $line->uom_id,
                        'unit_cost'                => $line->unit_price,
                    ];
                }
                $grn = $this->grnService->create($this->companyId, [
                    'purchase_order_id'      => $po->id,
                    'partner_id'              => $supplier->id,
                    'inventory_location_id'   => $this->masterLocationId,
                    'received_date'           => $receivedDate->toDateString(),
                    'lines'                   => $grnLines,
                ], $this->actor);
                $grn = $this->grnService->post($grn, $this->actor);
                $this->successCount++;

                // ── Supplier Bill — three-way match against the GRN ──
                $billLines = [];
                foreach ($grn->lines as $line) {
                    $billLines[] = [
                        'goods_receipt_note_line_id' => $line->id,
                        'product_id'                   => $line->product_id,
                        'description'                  => $line->product?->name_en ?? 'Goods',
                        'quantity'                      => $line->quantity,
                        'uom_id'                        => $line->uom_id,
                        'unit_price'                    => $line->unit_cost,
                        'vat_rate'                       => 14,
                        'is_deductible'                  => true,
                        'withholding_tax_rate'           => 1,
                    ];
                }
                $bill = $this->sbService->create($this->companyId, [
                    'bill_type'               => 'goods',
                    'partner_id'               => $supplier->id,
                    'purchase_order_id'        => $po->id,
                    'supplier_invoice_number'  => 'INV-'.strtoupper(substr(md5($po->id.$i), 0, 8)),
                    'supplier_invoice_date'    => $receivedDate->toDateString(),
                    'bill_date'                => $receivedDate->toDateString(),
                    'currency_id'              => $this->egpId,
                    'payment_term_id'          => $this->net30Id,
                    'lines'                    => $billLines,
                ], $this->actor);
                $bill = $this->sbService->post($bill, $this->actor);
                $this->successCount++;

                $this->openSupplierBills[] = $bill;
                $this->line("    PO/GRN/Bill posted — {$supplier->name_en} ({$bill->bill_code})");
            } catch (\Throwable $e) {
                $this->recordFailure('Purchase cycle month '.($m + 1)." #{$i}", $e);
            }
        }
    }

    // ── Sales cycle: SO → Delivery Note → Customer Invoice ──────

    private function monthlySalesCycle(Carbon $monthStart, int $m): void
    {
        for ($i = 0; $i < 3; $i++) {
            try {
                $customer = $this->customers[($m * 3 + $i) % 5];
                $issuance = (clone $monthStart)->addDays(1 + $i * 4);

                $lines = [];
                $picked = $this->pickProducts(random_int(2, 4), $m * 20 + $i);
                foreach ($picked as $p) {
                    $qty = random_int(5, 30);
                    $lines[] = [
                        'product_id'          => $p['model']->id,
                        'description'         => $p['model']->name_en,
                        'quantity'             => $qty,
                        'uom_id'               => $p['model']->primary_uom_id,
                        'unit_price'           => $p['price'],
                        // SalesOrderService does NOT auto-fill this from the
                        // product — it only ever uses what's passed here. The
                        // UI fills it client-side when a product is picked;
                        // we have to do the same thing explicitly.
                        'revenue_account_id'  => $p['model']->revenue_account_id,
                    ];
                }

                $so = $this->soService->create($this->companyId, [
                    'partner_id'      => $customer->id,
                    'subject'         => 'Sales order',
                    'currency_id'     => $this->egpId,
                    'exchange_rate'   => 1,
                    'issuance_date'   => $issuance->toDateString(),
                    'start_date'      => $issuance->toDateString(),
                    'end_date'        => (clone $issuance)->addDays(30)->toDateString(),
                    'payment_term_id' => $this->net30Id,
                    'lines'           => $lines,
                ], $this->actor);
                $this->soService->activate($so, $this->actor);
                $this->successCount++;

                // ── Delivery Note — full delivery a couple of days later ──
                $deliveryDate = (clone $issuance)->addDays(3);
                $dnLines = [];
                foreach ($so->lines as $line) {
                    $dnLines[] = [
                        'sales_order_line_id' => $line->id,
                        'location_id'          => $this->masterLocationId,
                        'quantity'              => $line->quantity,
                        'uom_id'                => $line->uom_id,
                    ];
                }
                $dn = $this->dnService->create($this->companyId, [
                    'sales_order_id'  => $so->id,
                    'partner_id'       => $customer->id,
                    'delivery_date'    => $deliveryDate->toDateString(),
                    'lines'            => $dnLines,
                ], $this->actor);
                $this->dnService->post($dn, $this->actor);
                $this->successCount++;

                // ── Customer Invoice — same day as delivery ──────
                $ciLines = [];
                foreach ($so->lines as $line) {
                    $ciLines[] = [
                        'sales_order_line_id' => $line->id,
                        'product_id'            => $line->product_id,
                        'description'           => $line->description,
                        'quantity'               => $line->quantity,
                        'uom_id'                 => $line->uom_id,
                        'unit_price'             => $line->unit_price,
                        'vat_rate'                => 14,
                    ];
                }
                $invoice = $this->ciService->create($this->companyId, [
                    'sales_order_id'  => $so->id,
                    'partner_id'       => $customer->id,
                    'invoice_date'     => $deliveryDate->toDateString(),
                    'currency_id'      => $this->egpId,
                    'payment_term_id'  => $this->net30Id,
                    'lines'            => $ciLines,
                ], $this->actor);
                $invoice = $this->ciService->post($invoice, $this->actor);
                $this->successCount++;

                $this->openCustomerInvoices[] = $invoice;
                $this->line("    SO/Delivery/Invoice posted — {$customer->name_en} ({$invoice->invoice_code})");
            } catch (\Throwable $e) {
                $this->recordFailure('Sales cycle month '.($m + 1)." #{$i}", $e);
            }
        }
    }

    // ── Recurring cash expenses ──────────────────────────────────

    private function monthlyExpenses(Carbon $monthStart, int $m): void
    {
        try {
            $bank = $this->banks[0]['account'];
            $this->cashExpService->record($this->companyId, [
                'payee_description' => 'Landlord — monthly office rent',
                'payment_date'       => (clone $monthStart)->addDays(1)->toDateString(),
                'money_type'         => 'outgoing_transfer',
                'currency_id'        => $this->egpId,
                'source_type'        => 'bank_account',
                'source_bank_account_id' => $bank->id,
                'lines' => [[
                    'description'         => 'Office rent — '.$monthStart->format('F Y'),
                    'expense_account_id'  => $this->expenseAccounts['Office Rent'],
                    'subtotal'             => 15000,
                ]],
            ], $this->actor);
            $this->successCount++;

            $this->cashExpService->record($this->companyId, [
                'payee_description' => 'Utilities provider',
                'payment_date'       => (clone $monthStart)->addDays(6)->toDateString(),
                'money_type'         => 'cash_payment',
                'currency_id'        => $this->egpId,
                'safe_account_id'    => $this->safeAccountId,
                'lines' => [[
                    'description'         => 'Electricity & water — '.$monthStart->format('F Y'),
                    'expense_account_id'  => $this->expenseAccounts['Utilities — Electricity & Water'],
                    'subtotal'             => random_int(3000, 6000),
                ]],
            ], $this->actor);
            $this->successCount++;

            $this->cashExpService->record($this->companyId, [
                'payee_description' => 'Telecom provider',
                'payment_date'       => (clone $monthStart)->addDays(10)->toDateString(),
                'money_type'         => 'outgoing_transfer',
                'currency_id'        => $this->egpId,
                'source_type'        => 'bank_account',
                'source_bank_account_id' => $bank->id,
                'lines' => [[
                    'description'         => 'Internet & phone lines — '.$monthStart->format('F Y'),
                    'expense_account_id'  => $this->expenseAccounts['Telecom & Internet'],
                    'subtotal'             => 1800,
                ]],
            ], $this->actor);
            $this->successCount++;

            $this->line('    Monthly rent/utilities/telecom posted.');
        } catch (\Throwable $e) {
            $this->recordFailure('Monthly expenses month '.($m + 1), $e);
        }
    }

    // ── Accruals + one Prepaid bill ──────────────────────────────

    private function monthlyAccrualsAndPrepaid(Carbon $monthStart, int $m): void
    {
        // One prepaid expense bill, January only — annual insurance.
        if ($m === 0) {
            try {
                $supplier = $this->suppliers[3];
                $bill = $this->sbService->create($this->companyId, [
                    'bill_type'      => 'prepaid',
                    'partner_id'      => $supplier->id,
                    'supplier_invoice_number' => 'INS-2026-001',
                    'supplier_invoice_date'   => '2026-01-05',
                    'bill_date'                => '2026-01-05',
                    'currency_id'              => $this->egpId,
                    'payment_term_id'          => $this->net30Id,
                    'lines' => [[
                        'chart_of_account_id' => $this->expenseAccounts['Insurance Expense'],
                        'description'          => 'Annual comprehensive insurance policy — 2026',
                        'unit_price'            => 24000,
                        'quantity'               => 1,
                        'amortization_months'   => 12,
                    ]],
                ], $this->actor);
                $bill = $this->sbService->post($bill, $this->actor);
                $this->openSupplierBills[] = $bill;
                $this->successCount++;
                $this->line('    Prepaid insurance bill posted (amortizes over 12 months).');
            } catch (\Throwable $e) {
                $this->recordFailure('Prepaid insurance bill', $e);
            }
        }

        // Accrued professional fees every other month (audit/legal
        // invoice not received yet) — left open to show as an
        // accrued liability.
        if (in_array($m, [1, 3, 5], true)) {
            try {
                $supplier = $this->suppliers[4]; // Ahmed Fathy & Co.
                $this->accrualService->create($this->companyId, [
                    'partner_id'          => $supplier->id,
                    'chart_of_account_id' => $this->expenseAccounts['Professional Fees — Audit & Legal'],
                    'description'          => 'Accrued audit & legal fees — '.$monthStart->format('F Y'),
                    'accrual_date'         => (clone $monthStart)->endOfMonth()->toDateString(),
                    'amount'               => random_int(8000, 15000),
                ], $this->actor);
                $this->successCount++;
                $this->line('    Accrued professional fees recorded.');
            } catch (\Throwable $e) {
                $this->recordFailure('Accrual month '.($m + 1), $e);
            }
        }
    }

    // ── Treasury movements: shareholder funding, employee custody,
    //    an OD draw, and a partial OD repayment via internal transfer ──

    private function monthlyTreasuryMovements(Carbon $monthStart, int $m): void
    {
        $bank0 = $this->banks[0]['account'];
        $bank1 = $this->banks[1]['account'];
        $facility0 = $this->facilities[0];

        // Shareholder capital injection — January only, to fund
        // operations at the start. Must run BEFORE the safe top-up
        // below: in month 1 there's no money anywhere yet, so if the
        // top-up (which draws from bank0) ran first, it would itself
        // fail for lack of funds — exactly what happened before this
        // reorder.
        if ($m === 0) {
            try {
                $this->ocService->record($this->companyId, [
                    'partner_id'        => $this->shareholders[0]->id,
                    'partner_type'       => 'shareholder',
                    'collection_date'    => '2026-01-02',
                    'money_type'         => 'bank_deposit',
                    'currency_id'        => $this->egpId,
                    'amount'             => 4000000,
                    'destination_type'             => 'bank_account',
                    'destination_bank_account_id'  => $bank0->id,
                    'credit_account_id' => $this->coaByCode('311'), // 311 Paid Up Capital (equity) — Audit L-10
                ], $this->actor);
                $this->successCount++;

                // Split across BOTH banks — bill/invoice settlements
                // later pick a bank at random (array_rand), so if the
                // whole injection landed in only one account, the
                // other would still sit at zero and start failing the
                // new insufficient-funds guard the moment it's picked.
                $this->ocService->record($this->companyId, [
                    'partner_id'        => $this->shareholders[0]->id,
                    'partner_type'       => 'shareholder',
                    'collection_date'    => '2026-01-02',
                    'money_type'         => 'bank_deposit',
                    'currency_id'        => $this->egpId,
                    'amount'             => 4000000,
                    'destination_type'             => 'bank_account',
                    'destination_bank_account_id'  => $bank1->id,
                    'credit_account_id' => $this->coaByCode('311'),
                ], $this->actor);
                $this->successCount++;

                $this->line('    Shareholder capital injection recorded (4,000,000 EGP into each bank — 8,000,000 total).');
            } catch (\Throwable $e) {
                $this->recordFailure('Shareholder injection', $e);
            }
        }

        // Fund the cash safe — nothing else in this script ever puts
        // money INTO it (only cash payments/expenses draw it down),
        // so without this every single cash payment fails the new
        // insufficient-funds guard from day one. A modest monthly
        // top-up comfortably covers the ~3,000–5,500 EGP/month of
        // actual cash spending seen in a typical run.
        try {
            $this->imtService->record($this->companyId, [
                'from_leg_type'        => 'bank',
                'from_bank_account_id'  => $bank0->id,
                'to_leg_type'            => 'safe',
                'to_safe_account_id'     => $this->safeAccountId,
                'currency_id'            => $this->egpId,
                'amount'                 => 10000,
                'transfer_date'          => (clone $monthStart)->addDays(1)->toDateString(),
                'comment'                => 'Monthly safe top-up',
            ], $this->actor);
            $this->successCount++;
        } catch (\Throwable $e) {
            $this->recordFailure('Safe top-up month '.($m + 1), $e);
        }

        // Employee custody advance out, then returned (custody
        // cycle) — alternating months.
        if ($m % 2 === 0) {
            $employee = $this->employees[$m / 2 % 4];
            try {
                $this->opService->record($this->companyId, [
                    'partner_id'        => $employee->id,
                    'partner_type'       => 'employee',
                    'employee_advance_type' => 'custody',
                    'payment_date'       => (clone $monthStart)->addDays(3)->toDateString(),
                    'money_type'         => 'cash_payment',
                    'currency_id'        => $this->egpId,
                    'amount'             => 2000,
                    'safe_account_id'    => $this->safeAccountId,
                    'debit_account_id'   => $this->coaByCode('148'), // Custody Advances to Employees
                ], $this->actor);
                $this->successCount++;
            } catch (\Throwable $e) {
                $this->recordFailure('Employee custody advance month '.($m + 1), $e);
            }
        } else {
            $employee = $this->employees[($m - 1) / 2 % 4];
            try {
                $this->ocService->record($this->companyId, [
                    'partner_id'        => $employee->id,
                    'partner_type'       => 'employee',
                    'employee_advance_type' => 'custody',
                    'collection_date'    => (clone $monthStart)->addDays(20)->toDateString(),
                    'money_type'         => 'cash_in_safe',
                    'currency_id'        => $this->egpId,
                    'amount'             => 2000,
                    'safe_account_id'    => $this->safeAccountId,
                    'credit_account_id' => $this->coaByCode('148'),
                ], $this->actor);
                $this->successCount++;
            } catch (\Throwable $e) {
                $this->recordFailure('Employee custody return month '.($m + 1), $e);
            }
        }

        // Draw on the Clean Overdraft facility to pay a supplier
        // (month 3), then partially repay it via an internal
        // transfer from the bank current account (month 6) — shows
        // the facility actually being used.
        if ($m === 2 && ! empty($this->openSupplierBills)) {
            try {
                $bill = end($this->openSupplierBills);
                $amount = min(50000, (float) $bill->amount_outstanding);
                if ($amount > 0) {
                    $this->spService->record($this->companyId, [
                        'partner_id'    => $bill->partner_id,
                        'payment_type'   => 'settlement',
                        'payment_date'   => (clone $monthStart)->addDays(15)->toDateString(),
                        'money_type'     => 'outgoing_transfer',
                        'pay_currency_id' => $this->egpId,
                        'amount'          => $amount,
                        'source_type'              => 'credit_facility',
                        'source_credit_facility_id' => $facility0->id,
                        'settlements' => [[
                            'supplier_bill_id'   => $bill->id,
                            'settlement_amount'   => $amount,
                        ]],
                    ], $this->actor);
                    $this->successCount++;
                    $this->line('    Drew on Clean Overdraft facility to pay a supplier (50,000 EGP).');
                }
            } catch (\Throwable $e) {
                $this->recordFailure('OD draw month '.($m + 1), $e);
            }
        }

        if ($m === 5) {
            try {
                $this->imtService->record($this->companyId, [
                    'from_leg_type'        => 'bank',
                    'from_bank_account_id'  => $bank1->id,
                    'to_leg_type'            => 'facility',
                    'to_credit_facility_id'  => $facility0->id,
                    'currency_id'            => $this->egpId,
                    'amount'                 => 25000,
                    'transfer_date'          => (clone $monthStart)->addDays(10)->toDateString(),
                    'comment'                => 'Partial repayment of Clean Overdraft draw',
                ], $this->actor);
                $this->successCount++;
                $this->line('    Partial OD repayment via internal transfer (25,000 EGP).');
            } catch (\Throwable $e) {
                $this->recordFailure('OD repayment transfer month '.($m + 1), $e);
            }
        }

        // Shareholder dividend payout — month 7 (August).
        if ($m === 6) {
            try {
                $this->opService->record($this->companyId, [
                    'partner_id'        => $this->shareholders[0]->id,
                    'partner_type'       => 'shareholder',
                    'payment_date'       => (clone $monthStart)->addDays(20)->toDateString(),
                    'money_type'         => 'outgoing_transfer',
                    'currency_id'        => $this->egpId,
                    'amount'             => 40000,
                    'source_type'                => 'bank_account',
                    'source_bank_account_id'     => $bank0->id,
                    'debit_account_id'   => $this->coaByCode('314'),
                ], $this->actor);
                $this->successCount++;
                $this->line('    Shareholder dividend payout recorded (40,000 EGP).');
            } catch (\Throwable $e) {
                $this->recordFailure('Shareholder dividend month '.($m + 1), $e);
            }
        }
    }

    // ── Pay down AP / collect AR older than ~30 days ─────────────

    private function settleAgingPayables(Carbon $monthStart): void
    {
        $cutoff = (clone $monthStart)->subDays(25);
        $stillOpen = [];

        foreach ($this->openSupplierBills as $bill) {
            $bill->refresh();
            $outstanding = (float) $bill->amount_outstanding;
            if ($outstanding <= 0) {
                continue;
            }
            if ($bill->bill_date->lte($cutoff)) {
                try {
                    $bank = $this->banks[array_rand($this->banks)]['account'];
                    $this->spService->record($this->companyId, [
                        'partner_id'    => $bill->partner_id,
                        'payment_type'   => 'settlement',
                        'payment_date'   => (clone $monthStart)->addDays(2)->toDateString(),
                        'money_type'     => 'outgoing_transfer',
                        'pay_currency_id' => $this->egpId,
                        'amount'          => $outstanding,
                        'source_type'            => 'bank_account',
                        'source_bank_account_id' => $bank->id,
                        'settlements' => [[
                            'supplier_bill_id'   => $bill->id,
                            'settlement_amount'   => $outstanding,
                        ]],
                    ], $this->actor);
                    $this->successCount++;
                } catch (\Throwable $e) {
                    $this->recordFailure('Supplier payment for bill '.$bill->bill_code, $e);
                    $stillOpen[] = $bill;
                }
            } else {
                $stillOpen[] = $bill;
            }
        }

        $this->openSupplierBills = $stillOpen;
    }

    private function settleAgingReceivables(Carbon $monthStart): void
    {
        $cutoff = (clone $monthStart)->subDays(20);
        $stillOpen = [];

        foreach ($this->openCustomerInvoices as $invoice) {
            $invoice->refresh();
            $outstanding = (float) $invoice->amount_outstanding;
            if ($outstanding <= 0) {
                continue;
            }
            if ($invoice->invoice_date->lte($cutoff)) {
                try {
                    $bank = $this->banks[array_rand($this->banks)]['account'];
                    $this->cpService->record($this->companyId, [
                        'partner_id'        => $invoice->partner_id,
                        'collection_type'    => 'settlement',
                        'payment_date'       => (clone $monthStart)->addDays(3)->toDateString(),
                        'money_type'         => 'bank_deposit',
                        'receive_currency_id' => $this->egpId,
                        'amount'              => $outstanding,
                        'destination_type'             => 'bank_account',
                        'destination_bank_account_id'  => $bank->id,
                        'settlements' => [[
                            'customer_invoice_id' => $invoice->id,
                            'settlement_amount'    => $outstanding,
                        ]],
                    ], $this->actor);
                    $this->successCount++;
                } catch (\Throwable $e) {
                    $this->recordFailure('Customer payment for invoice '.$invoice->invoice_code, $e);
                    $stillOpen[] = $invoice;
                }
            } else {
                $stillOpen[] = $invoice;
            }
        }

        $this->openCustomerInvoices = $stillOpen;
    }

    // ══════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════

    private function coaByCode(string $code): int
    {
        return (int) ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('code', $code)
            ->value('id');
    }

    /** @return array<int, array{model: Product, cost: float, price: float}> */
    private function pickProducts(int $count, int $seed): array
    {
        $total = count($this->products);
        $picked = [];
        for ($i = 0; $i < $count; $i++) {
            $picked[] = $this->products[($seed + $i * 3) % $total];
        }
        return $picked;
    }

    private function recordFailure(string $context, \Throwable $e): void
    {
        $message = "{$context}: {$e->getMessage()}";
        $this->failures[] = $message;
        $this->warn('    ⚠ '.$message);
    }
}