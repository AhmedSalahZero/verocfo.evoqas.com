<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  SeedDemoSystemIntegratorCompany
//
//  Creates one complete, realistic System Integrator company — "Nova
//  Systems Integration Co." — built around Projects/Contracts as the
//  primary revenue vehicle (as requested), not standalone Sales
//  Orders: five real Projects, each with its own auto-generated
//  contract code, a down payment collected at kickoff, a Sales Order
//  MIXING physical hardware (delivered, WAC-costed, stock-tracked)
//  and pure service lines (installation, project management,
//  support — no delivery needed) on the same invoice — the exact
//  "Service with Inventory" behavior this company type exists for.
//
//  Also demonstrates a banking facility neither of the other two
//  demos touch: a Contract Assignment Overdraft, where one of the
//  Projects itself is pledged to the bank as collateral — a real,
//  project-specific financing mechanism, not just a generic credit
//  line.
//
//  Everything else (partners, treasury, safe funding order,
//  insufficient-funds guard, expenses — including a project-tagged
//  cash expense allocation — accruals, prepaid insurance, fixed
//  assets, shareholder funding, employee custody) reuses the same
//  patterns already proven correct across the Trading and
//  Manufacturing demos.
//
//  USAGE:
//    php artisan demo:seed-system-integrator-company
//
//  Safe to re-run: always creates a NEW company. Every transaction
//  block is wrapped in try/catch so one failure never stops the
//  whole run.
//
//  Audit H8 (Sept 2026): refuses to run when APP_ENV=production
//  (RefusesInProduction trait).
//
//  @layer    Console Command
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Currency;
use App\Models\CustomerInvoice;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\InventoryLocation;
use App\Models\Partner;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Project;
use App\Models\SupplierBill;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\Accounting\AccrualService;
use App\Services\Accounting\CashExpensePaymentService;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\CustomerPaymentService;
use App\Services\Accounting\DeliveryNoteService;
use App\Services\Accounting\FixedAssetService;
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
use App\Services\FinancialInstitutions\ContractAssignmentOdService;
use App\Services\FinancialInstitutions\InternalMoneyTransferService;
use App\Services\PartnerService;
use App\Services\ProductCategoryService;
use App\Services\ProductService;
use App\Services\Projects\ProjectService;
use App\Services\Projects\SalesOrderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SeedDemoSystemIntegratorCompany extends Command
{
    use \App\Console\Concerns\RefusesInProduction; // Audit H8

    protected $signature = 'demo:seed-system-integrator-company';

    protected $description = 'Creates a demo System Integrator company (Growth tier), built around Projects/Contracts, with mixed hardware+service billing, a project pledged as OD collateral, and 8 months of posted transactions (Jan–Aug 2026) for testing.';

    // ── Services ─────────────────────────────────────────────────
    private CompanyService $companyService;
    private ProductCategoryService $categoryService;
    private ProductService $productService;
    private PartnerService $partnerService;
    private ChartOfAccountService $coaService;
    private BankService $bankService;
    private CleanOverdraftService $odService;
    private ContractAssignmentOdService $contractOdService;
    private InternalMoneyTransferService $imtService;
    private ProjectService $projectService;
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
    private FixedAssetService $fixedAssetService;

    // ── Runtime state ────────────────────────────────────────────
    private User $actor;
    private Company $company;
    private int $companyId;
    private int $egpId;
    private int $net30Id;
    private int $masterLocationId;
    private int $safeAccountId;

    /** @var array<int, array{model: Product, cost: float, price: float}> */
    private array $hardware = [];
    /** @var array<int, array{model: Product, price: float}> */
    private array $services = [];
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
    /** @var array<string, int> */
    private array $expenseAccounts = [];
    /** @var Project[] */
    private array $projects = [];

    /** @var string[] */
    private array $failures = [];
    private int $successCount = 0;

    /** @var SupplierBill[] */
    private array $openSupplierBills = [];
    /** @var CustomerInvoice[] */
    private array $openCustomerInvoices = [];

    public function handle(
        CompanyService $companyService,
        ProductCategoryService $categoryService,
        ProductService $productService,
        PartnerService $partnerService,
        ChartOfAccountService $coaService,
        BankService $bankService,
        CleanOverdraftService $odService,
        ContractAssignmentOdService $contractOdService,
        InternalMoneyTransferService $imtService,
        ProjectService $projectService,
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
        FixedAssetService $fixedAssetService,
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
        $this->contractOdService = $contractOdService;
        $this->imtService      = $imtService;
        $this->projectService  = $projectService;
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
        $this->fixedAssetService = $fixedAssetService;

        $this->actor = User::where('role', User::ROLE_SUPER_ADMIN)->first();
        if (! $this->actor) {
            $this->error('No Super Admin user found — run SuperAdminSeeder first.');
            return self::FAILURE;
        }

        $this->egpId  = (int) Currency::withoutGlobalScopes()->whereNull('company_id')->where('code', 'EGP')->value('id');
        $this->net30Id = (int) PaymentTerm::withoutGlobalScopes()->whereNull('company_id')->where('code', 'NET30')->value('id');
        if (! $this->egpId || ! $this->net30Id) {
            $this->error('EGP currency or NET30 payment term not found — run "php artisan db:seed" first.');
            return self::FAILURE;
        }

        $this->info('── Step 1/7 — Creating the company ──────────────────');
        $this->createCompany();

        $this->info('── Step 2/7 — Hardware & service catalogue ───────────');
        $this->createCatalogue();

        $this->info('── Step 3/7 — Partners ───────────────────────────────');
        $this->createPartners();

        $this->info('── Step 4/7 — Treasury (banks, safe, OD facilities) ─');
        $this->createTreasury();

        $this->info('── Step 5/7 — Custom expense accounts ────────────────');
        $this->createExpenseAccounts();

        $this->info('── Step 6/7 — Fixed assets ───────────────────────────');
        $this->buyFixedAssets();

        $this->info('── Step 7/7 — Projects, contracts & 8 months of transactions ─');
        $this->runTransactions();

        $this->newLine();
        $this->info("Done. {$this->successCount} documents posted successfully.");
        if ($this->failures) {
            $this->warn(count($this->failures).' step(s) failed — see below. Everything else was posted fine.');
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
        $name = 'Nova Systems Integration Co.';
        if (Company::withoutGlobalScopes()->where('name_en', $name)->exists()) {
            $name .= ' ('.now()->format('H:i:s').')';
        }

        $this->company = $this->companyService->create([
            'name_en'                 => $name,
            'name_ar'                 => 'شركة نوفا لتكامل النظم',
            'company_type'            => ['Service with Inventory'],
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
    //  STEP 2 — Catalogue: hardware (stocked) + services (not)
    // ══════════════════════════════════════════════════════════════

    private function createCatalogue(): void
    {
        $unitId = (int) UnitOfMeasure::withoutGlobalScopes()->whereNull('company_id')->where('code', 'UNIT')->value('id');

        $hwCategory = $this->categoryService->create($this->companyId, ['name_en' => 'Network & IT Hardware'], $this->actor);
        $svcCategory = $this->categoryService->create($this->companyId, ['name_en' => 'Professional Services'], $this->actor);

        $hardwareNames = [
            'Enterprise 48-Port Network Switch',
            'Rack-Mount Server Unit',
            'Fiber Optic Cable Reel (300m)',
            'UPS Backup Power Unit',
            'Enterprise Wireless Access Point',
        ];
        foreach ($hardwareNames as $i => $name) {
            $product = $this->productService->create($this->companyId, [
                'use_auto_code'   => true,
                'name_en'         => $name,
                'category_id'     => $hwCategory->id,
                'usage_types'     => [Product::TYPE_SALEABLE],
                // Service-with-Inventory companies have TWO valid
                // natures (tradable/service) so this can't auto-resolve
                // — must be explicit, same reasoning as the
                // Manufacturing demo's finished goods.
                'nature'          => Product::NATURE_TRADABLE,
                'primary_uom_id'  => $unitId,
                'reorder_level'   => 10,
                'reorder_qty'     => 50,
            ], $this->actor);

            $cost = round(3500 + $i * 4200, 2);
            $price = round($cost * 1.5, 2);
            $this->hardware[] = ['model' => $product, 'cost' => $cost, 'price' => $price];
        }

        $serviceNames = [
            ['Installation & Configuration Labor', 1200],
            ['Project Management Services', 1500],
            ['System Testing & Commissioning', 900],
            ['Annual Support & Maintenance', 2000],
        ];
        foreach ($serviceNames as [$name, $price]) {
            $product = $this->productService->create($this->companyId, [
                'use_auto_code'   => true,
                'name_en'         => $name,
                'category_id'     => $svcCategory->id,
                'usage_types'     => [Product::TYPE_SALEABLE],
                'nature'          => Product::NATURE_SERVICE,
                'primary_uom_id'  => $unitId,
            ], $this->actor);

            $this->services[] = ['model' => $product, 'price' => (float) $price];
        }

        $this->line('  '.count($this->hardware).' hardware products, '.count($this->services).' service items created.');
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 3 — Partners
    // ══════════════════════════════════════════════════════════════

    private function createPartners(): void
    {
        $customerNames = [
            'National Bank Data Center Project', 'Cairo General Hospital Group', 'Alexandria Port Authority',
            'Nile University Campus IT', 'Red Sea Resorts Holding',
        ];
        foreach ($customerNames as $name) {
            $this->customers[] = $this->partnerService->create($this->companyId, [
                'name_en'             => $name,
                'types'                => ['customer'],
                'credit_limit'         => 800000,
                'collection_term_id'   => $this->net30Id,
                'country'              => 'Egypt',
            ], $this->actor);
        }

        $supplierNames = [
            'Cisco Regional Distributor Egypt', 'Dell Enterprise Hardware Partner', 'Fiber Networks Supply Co.',
            'Power Systems & UPS Trading', 'Ahmed Fathy & Co. — Audit & Legal Services',
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
            ['Youssef Tarek', 'Project Manager'],
            ['Dina Mahmoud', 'Network Engineer'],
            ['Omar Sherif', 'Systems Integration Engineer'],
            ['Salma Nabil', 'Finance & Contracts Officer'],
        ];
        foreach ($employeeNames as [$name, $title]) {
            $this->employees[] = $this->partnerService->create($this->companyId, [
                'name_en'     => $name,
                'types'        => ['employee'],
                'job_title'    => $title,
                'department'   => 'Operations',
            ], $this->actor);
        }

        $shareholderData = [['Tarek Nova', 60], ['Nova Ventures Group', 40]];
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
    //  STEP 4 — Treasury: Banks, Safe, Clean OD ×2, Contract Assignment OD
    // ══════════════════════════════════════════════════════════════

    private function createTreasury(): void
    {
        $banksParentId = $this->coaByCode('132');
        $cashParentId  = $this->coaByCode('131');
        $od223001Id    = $this->coaByCode('223001'); // Clean Overdraft
        $od223003Id    = $this->coaByCode('223003'); // OD Against Contract Assignment

        $safeAccount = $this->coaService->create($this->companyId, [
            'parent_id'      => $cashParentId,
            'use_auto_code'  => true,
            'name_en'        => 'Main Safe',
        ], $this->actor);
        $this->safeAccountId = $safeAccount->id;

        $bankDefs = ['Commercial International Bank (CIB)', 'National Bank of Egypt (NBE)'];

        foreach ($bankDefs as $bankName) {
            $bank = $this->bankService->createBank($this->companyId, ['name_en' => $bankName, 'is_active' => true], $this->actor);

            $coaLeaf = $this->coaService->create($this->companyId, [
                'parent_id'      => $banksParentId,
                'use_auto_code'  => true,
                'name_en'        => "{$bankName} — Current Account EGP",
            ], $this->actor);

            $account = $this->bankService->createBankAccount($this->companyId, $bank, [
                'account_name'    => "{$bankName} — Current Account",
                'account_number'  => '300'.random_int(1000000, 9999999),
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
                'facility_limit'        => 2500000,
                'outstanding_balance'   => 0,
                'balance_date'          => '2026-01-01',
                'borrowing_rate'        => 18,
                'bank_margin_rate'      => 3,
                'min_interest_rate'     => 1,
                'hdb_rate'              => 0.5,
                'admin_fee_rate'        => 0.25,
                'settled_max_days'      => 90,
                'follows_corridor_rate' => false,
                'purpose'               => 'Working capital',
            ], $this->actor);
            $facility->update(['bank_account_id' => $account->id]);

            $this->facilities[] = $facility;

            $this->line("  Bank: {$bankName} — current account + Clean Overdraft facility (limit 2,500,000 EGP)");
        }

        // ── Contract Assignment OD — a project itself as collateral ──
        // Built at bank0 (CIB). The actual contract gets pledged later
        // in runTransactions(), once at least one Project exists and
        // is far enough into its timeline to be a believable pledge.
        try {
            $facility = $this->contractOdService->create($this->companyId, $this->banks[0]['bank'], [
                'bank_id'               => $this->banks[0]['bank']->id,
                'start_date'            => '2026-01-01',
                'review_date'           => '2026-06-30',
                'account_number'        => 'CAOD-'.random_int(100000, 999999),
                'currency_id'           => $this->egpId,
                'coa_account_id'        => $od223003Id,
                'facility_limit'        => 1500000,
                'outstanding_balance'   => 0,
                'balance_date'          => '2026-01-01',
                'borrowing_rate'        => 17,
                'bank_margin_rate'      => 2.5,
                'min_interest_rate'     => 1,
                'hdb_rate'              => 0.5,
                'admin_fee_rate'        => 0.25,
                'settled_max_days'      => 90,
                'max_lending_limit_per_contract' => 800000,
                'follows_corridor_rate' => false,
                'purpose'               => 'Financing against pledged customer contracts',
            ], $this->actor);
            $facility->update(['bank_account_id' => $this->banks[0]['account']->id]);
            $this->facilities[] = $facility; // index 2 — the Contract Assignment OD
            $this->successCount++;
            $this->line('  Contract Assignment Overdraft facility created (limit 1,500,000 EGP, max 800,000 EGP per contract).');
        } catch (\Throwable $e) {
            $this->recordFailure('Contract Assignment OD facility creation', $e);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 5 — Custom expense accounts
    // ══════════════════════════════════════════════════════════════

    private function createExpenseAccounts(): void
    {
        $gaParentId  = $this->coaByCode('54');
        $salesParentId = $this->coaByCode('52');
        $mktParentId = $this->coaByCode('53');

        $leaves = [
            ['Office Rent',                       $gaParentId],
            ['Utilities — Electricity & Water',   $gaParentId],
            ['Telecom & Internet',                $gaParentId],
            ['Site Visit & Travel Expense',        $gaParentId],
            ['Professional Fees — Audit & Legal',  $gaParentId],
            ['Insurance Expense',                  $gaParentId],
            ['Sales Commissions',                  $salesParentId],
            ['Business Development & Marketing',   $mktParentId],
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
    //  STEP 6 — Fixed assets (light — a vehicle + test lab equipment)
    // ══════════════════════════════════════════════════════════════

    /** @var array<int, array{asset: \App\Models\FixedAsset, from: Carbon}> */
    private array $fixedAssets = [];

    private function buyFixedAssets(): void
    {
        $assetDefs = [
            ['name' => 'Company Service Vehicle', 'account_code' => '111004', 'cost' => 480000, 'life_months' => 72, 'residual' => 30000],
            ['name' => 'Network Testing Lab Equipment', 'account_code' => '111006', 'cost' => 140000, 'life_months' => 48, 'residual' => 10000],
        ];

        $supplier = $this->suppliers[1];

        foreach ($assetDefs as $def) {
            try {
                $fixedAssetAccount = ChartOfAccount::withoutGlobalScopes()
                    ->whereNull('company_id')->where('code', $def['account_code'])->first();

                $assetType = $this->productService->createFixedAssetType($this->companyId, [
                    'name_en'                    => $def['name'],
                    'fixed_asset_account_id'      => $fixedAssetAccount->id,
                    'default_useful_life_months'  => $def['life_months'],
                    'default_residual_value'      => $def['residual'],
                ], $this->actor);

                $po = $this->poService->create($this->companyId, [
                    'partner_id'      => $supplier->id,
                    'subject'         => 'Fixed asset purchase — '.$def['name'],
                    'currency_id'     => $this->egpId,
                    'exchange_rate'   => 1,
                    'issuance_date'   => '2026-01-05',
                    'start_date'      => '2026-01-05',
                    'end_date'        => '2026-02-04',
                    'payment_term_id' => $this->net30Id,
                    'lines' => [[
                        'product_id'   => $assetType->id,
                        'description'  => $def['name'],
                        'quantity'     => 1,
                        'uom_id'       => $assetType->primary_uom_id,
                        'unit_price'   => $def['cost'],
                    ]],
                ], $this->actor);
                $this->poService->activate($po, $this->actor);

                $grn = $this->grnService->create($this->companyId, [
                    'purchase_order_id'      => $po->id,
                    'partner_id'              => $supplier->id,
                    'inventory_location_id'   => $this->masterLocationId,
                    'received_date'           => '2026-01-10',
                    'lines' => [[
                        'purchase_order_line_id' => $po->lines[0]->id,
                        'product_id'               => $assetType->id,
                        'quantity'                  => 1,
                        'uom_id'                    => $assetType->primary_uom_id,
                        'unit_cost'                 => $def['cost'],
                    ]],
                ], $this->actor);
                $grn = $this->grnService->post($grn, $this->actor);

                $bill = $this->sbService->create($this->companyId, [
                    'bill_type'               => 'goods',
                    'partner_id'               => $supplier->id,
                    'purchase_order_id'        => $po->id,
                    'supplier_invoice_number'  => 'FA-'.strtoupper(substr(md5($def['name']), 0, 8)),
                    'supplier_invoice_date'    => '2026-01-10',
                    'bill_date'                => '2026-01-10',
                    'currency_id'              => $this->egpId,
                    'payment_term_id'          => $this->net30Id,
                    'lines' => [[
                        'goods_receipt_note_line_id' => $grn->lines[0]->id,
                        'product_id'                   => $assetType->id,
                        'description'                  => $def['name'],
                        'quantity'                      => 1,
                        'unit_price'                    => $def['cost'],
                        'vat_rate'                       => 14,
                    ]],
                ], $this->actor);
                $bill = $this->sbService->post($bill, $this->actor);
                $this->openSupplierBills[] = $bill;

                $asset = \App\Models\FixedAsset::where('company_id', $this->companyId)
                    ->where('source_type', 'goods_receipt_note_line')
                    ->where('source_id', $grn->lines[0]->id)
                    ->first();
                if ($asset) {
                    $this->fixedAssetService->markInService($asset, $this->actor);
                    $this->fixedAssets[] = ['asset' => $asset, 'from' => Carbon::parse($asset->depreciation_start_date)];
                }

                $this->successCount += 4;
                $this->line("  Fixed asset purchased & put in service: {$def['name']} (".number_format($def['cost']).' EGP)');
            } catch (\Throwable $e) {
                $this->recordFailure('Fixed asset purchase — '.$def['name'], $e);
            }
        }
    }

    private function postMonthlyDepreciation(Carbon $monthStart): void
    {
        foreach ($this->fixedAssets as $fa) {
            if ($monthStart->lt($fa['from'])) {
                continue;
            }
            try {
                $this->fixedAssetService->postMonthlyDepreciation(
                    $fa['asset'], $this->actor, \Illuminate\Support\Carbon::instance($monthStart)
                );
                $this->successCount++;
            } catch (\Throwable $e) {
                $this->recordFailure('Depreciation '.$monthStart->format('M Y').' — '.$fa['asset']->name, $e);
            }
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 7 — Projects, contracts & 8 months of transactions
    // ══════════════════════════════════════════════════════════════

    private function runTransactions(): void
    {
        $this->seedInitialHardwareStock();

        for ($m = 0; $m < 8; $m++) {
            $monthStart = Carbon::create(2026, 1, 1)->addMonths($m);
            $this->line('');
            $this->info('  Month '.($m + 1).' — '.$monthStart->format('F Y'));

            $this->monthlyPurchaseCycle($monthStart, $m);

            // One new Project/Contract kicks off each of the first 5
            // months — the headline feature of this demo.
            if ($m < 5) {
                $this->kickOffProject($monthStart, $m);
            }
            if ($m === 2) {
                $this->pledgeProjectAsCollateral();
            }

            $this->monthlyTreasuryMovements($monthStart, $m);
            $this->monthlyExpenses($monthStart, $m);
            $this->postMonthlyDepreciation($monthStart);
            $this->monthlyAccrualsAndPrepaid($monthStart, $m);
            $this->settleAgingPayables($monthStart);
            $this->settleAgingReceivables($monthStart);
        }
    }

    // ── Initial hardware stock (2026-01-01) ──────────────────────

    private function seedInitialHardwareStock(): void
    {
        try {
            $chunks = array_chunk($this->hardware, 2);
            foreach ($chunks as $i => $chunk) {
                $supplier = $this->suppliers[$i % 4];

                $lines = [];
                foreach ($chunk as $hw) {
                    $lines[] = [
                        'product_id'   => $hw['model']->id,
                        'description'  => $hw['model']->name_en,
                        'quantity'     => 90,
                        'uom_id'       => $hw['model']->primary_uom_id,
                        'unit_price'   => $hw['cost'],
                    ];
                }

                $po = $this->poService->create($this->companyId, [
                    'partner_id'      => $supplier->id,
                    'subject'         => 'Opening hardware stock',
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
                        'description'                  => $line->product?->name_en ?? 'Hardware',
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
                    'supplier_invoice_number'  => 'OPEN-HW-'.($i + 1),
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

            $this->line('  Opening hardware stock posted — 90 units of every hardware item, dated 2026-01-01.');
        } catch (\Throwable $e) {
            $this->recordFailure('Initial hardware stock', $e);
        }
    }

    // ── Purchase cycle: restock hardware monthly ─────────────────

    private function monthlyPurchaseCycle(Carbon $monthStart, int $m): void
    {
        try {
            $supplier = $this->suppliers[$m % 4];
            $issuance = (clone $monthStart)->addDays(3);

            $lines = [];
            $picked = $this->pickHardware(2, $m * 10);
            foreach ($picked as $hw) {
                $qty = random_int(15, 35);
                $lines[] = [
                    'product_id'   => $hw['model']->id,
                    'description'  => $hw['model']->name_en,
                    'quantity'     => $qty,
                    'uom_id'       => $hw['model']->primary_uom_id,
                    'unit_price'   => $hw['cost'],
                ];
            }

            $po = $this->poService->create($this->companyId, [
                'partner_id'      => $supplier->id,
                'subject'         => 'Hardware restock',
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

            $billLines = [];
            foreach ($grn->lines as $line) {
                $billLines[] = [
                    'goods_receipt_note_line_id' => $line->id,
                    'product_id'                   => $line->product_id,
                    'description'                  => $line->product?->name_en ?? 'Hardware',
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
                'supplier_invoice_number'  => 'INV-'.strtoupper(substr(md5($po->id), 0, 8)),
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
            $this->recordFailure('Purchase cycle month '.($m + 1), $e);
        }
    }

    // ── Kick off one Project/Contract: create → down payment →
    //    mixed hardware+service SO → Delivery (hardware only) →
    //    Invoice (everything) ─────────────────────────────────────

    private function kickOffProject(Carbon $monthStart, int $m): void
    {
        try {
            $customer = $this->customers[$m % 5];
            $startDate = (clone $monthStart)->addDays(2);
            $endDate = (clone $startDate)->addMonths(6);

            $hwLines = $this->pickHardware(2, $m * 5);
            $svcLines = $this->pickServices(2, $m * 3);

            $contractValue = 0.0;
            foreach ($hwLines as $hw) {
                $contractValue += $hw['price'] * 12;
            }
            foreach ($svcLines as $svc) {
                $contractValue += $svc['price'];
            }

            $project = $this->projectService->create($this->companyId, [
                'name_en'        => 'IT Infrastructure Contract — '.$customer->name_en,
                'partner_id'     => $customer->id,
                'contract_value' => round($contractValue, 2),
                'currency_id'    => $this->egpId,
                'exchange_rate'  => 1,
                'start_date'     => $startDate->toDateString(),
                'end_date'       => $endDate->toDateString(),
            ], $this->actor);
            $this->projects[] = $project;
            $this->successCount++;
            $this->line("    Project created: {$project->contract_code} — {$project->name_en} (contract value ".number_format($contractValue).' EGP)');

            // ── Down payment — 25% of contract value, collected at
            //    kickoff, tagged to this project. Left unapplied
            //    against any specific invoice (a real advance, exactly
            //    how a down payment behaves) — not settled here.
            $bank = $this->banks[$m % 2]['account'];
            $downPayment = round($contractValue * 0.25, 2);
            $this->cpService->record($this->companyId, [
                'partner_id'        => $customer->id,
                'collection_type'    => 'down_payment',
                'payment_date'       => $startDate->toDateString(),
                'money_type'         => 'bank_deposit',
                'receive_currency_id' => $this->egpId,
                'amount'              => $downPayment,
                'destination_type'             => 'bank_account',
                'destination_bank_account_id'  => $bank->id,
                'down_payment_project_id'      => $project->id,
            ], $this->actor);
            $this->successCount++;
            $this->line('    Down payment collected: '.number_format($downPayment).' EGP (25% of contract value).');

            // ── Sales Order — mixes hardware (delivered, stock-tracked)
            //    and service lines (billed directly, no delivery) on
            //    the same contract, tagged with project_id.
            $lines = [];
            foreach ($hwLines as $hw) {
                $lines[] = [
                    'product_id'          => $hw['model']->id,
                    'description'         => $hw['model']->name_en,
                    'quantity'             => 12,
                    'uom_id'               => $hw['model']->primary_uom_id,
                    'unit_price'           => $hw['price'],
                    'revenue_account_id'  => $hw['model']->revenue_account_id,
                ];
            }
            foreach ($svcLines as $svc) {
                $lines[] = [
                    'product_id'          => $svc['model']->id,
                    'description'         => $svc['model']->name_en,
                    'quantity'             => 1,
                    'uom_id'               => $svc['model']->primary_uom_id,
                    'unit_price'           => $svc['price'],
                    'revenue_account_id'  => $svc['model']->revenue_account_id,
                ];
            }

            $soDate = (clone $startDate)->addDays(3);
            $so = $this->soService->create($this->companyId, [
                'project_id'      => $project->id,
                'partner_id'      => $customer->id,
                'subject'         => 'Phase 1 delivery — '.$project->name_en,
                'currency_id'     => $this->egpId,
                'exchange_rate'   => 1,
                'issuance_date'   => $soDate->toDateString(),
                'start_date'      => $soDate->toDateString(),
                'end_date'        => $endDate->toDateString(),
                'payment_term_id' => $this->net30Id,
                'lines'           => $lines,
            ], $this->actor);
            $this->soService->activate($so, $this->actor);
            $this->successCount++;

            // Delivery Note — hardware (stocked) lines ONLY. Service
            // lines never need delivery (has_stock = false).
            $deliveryDate = (clone $soDate)->addDays(4);
            $dnLines = [];
            foreach ($so->lines as $line) {
                if ($line->product && $line->product->has_stock) {
                    $dnLines[] = [
                        'sales_order_line_id' => $line->id,
                        'location_id'          => $this->masterLocationId,
                        'quantity'              => $line->quantity,
                        'uom_id'                => $line->uom_id,
                    ];
                }
            }
            if (! empty($dnLines)) {
                $dn = $this->dnService->create($this->companyId, [
                    'sales_order_id'  => $so->id,
                    'partner_id'       => $customer->id,
                    'delivery_date'    => $deliveryDate->toDateString(),
                    'lines'            => $dnLines,
                ], $this->actor);
                $this->dnService->post($dn, $this->actor);
                $this->successCount++;
            }

            // Invoice — ALL lines, hardware (now delivered) and
            // service (billed directly) together on one contract
            // invoice.
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
            $this->openCustomerInvoices[] = $invoice;
            $this->successCount++;

            $this->line("    SO/Delivery/Invoice posted — {$project->contract_code} ({$invoice->invoice_code})");
        } catch (\Throwable $e) {
            $this->recordFailure('Project kickoff month '.($m + 1), $e);
        }
    }

    // ── Pledge one active Project as collateral for the Contract
    //    Assignment OD facility — the headline banking feature ────

    private function pledgeProjectAsCollateral(): void
    {
        if (empty($this->projects) || count($this->facilities) < 3) {
            return; // Contract Assignment OD failed to create earlier — nothing to pledge to
        }

        try {
            $facility = $this->facilities[2]; // Contract Assignment OD
            $project = $this->projects[0];    // first project, longest remaining runway

            $this->contractOdService->addAssignment($this->companyId, $facility, [
                'partner_id'            => $project->partner_id,
                'collateral_type'       => 'project',
                'collateral_project_id' => $project->id,
                'assignment_date'       => '2026-03-15',
                'lending_pct'           => 70,
            ], $this->actor);
            $this->successCount++;
            $this->line("    Project {$project->contract_code} pledged as collateral for the Contract Assignment Overdraft (70% lending).");
        } catch (\Throwable $e) {
            $this->recordFailure('Pledge project as collateral', $e);
        }
    }

    // ── Recurring cash expenses (one project-allocated) ──────────

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
                    'subtotal'             => 20000,
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
                    'subtotal'             => random_int(3500, 6500),
                ]],
            ], $this->actor);
            $this->successCount++;

            // Site visit travel — allocated to a project (reporting-
            // only tag; doesn't need to sum to the full payment).
            if (! empty($this->projects)) {
                $project = $this->projects[$m % count($this->projects)];
                $this->cashExpService->record($this->companyId, [
                    'payee_description' => 'Site visit travel — '.$project->contract_code,
                    'payment_date'       => (clone $monthStart)->addDays(14)->toDateString(),
                    'money_type'         => 'cash_payment',
                    'currency_id'        => $this->egpId,
                    'safe_account_id'    => $this->safeAccountId,
                    'lines' => [[
                        'description'         => 'Site visit — '.$project->name_en,
                        'expense_account_id'  => $this->expenseAccounts['Site Visit & Travel Expense'],
                        'subtotal'             => random_int(1500, 3500),
                    ]],
                    'allocations' => [[
                        'line_index'         => 0,
                        'project_id'          => $project->id,
                        'allocated_amount'    => 1500,
                    ]],
                ], $this->actor);
                $this->successCount++;
            }

            $this->line('    Monthly rent/utilities/site-visit posted.');
        } catch (\Throwable $e) {
            $this->recordFailure('Monthly expenses month '.($m + 1), $e);
        }
    }

    // ── Accruals + one prepaid bill ──────────────────────────────

    private function monthlyAccrualsAndPrepaid(Carbon $monthStart, int $m): void
    {
        if ($m === 0) {
            try {
                $supplier = $this->suppliers[4];
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
                        'description'          => 'Annual professional indemnity & office insurance — 2026',
                        'unit_price'            => 30000,
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

        if (in_array($m, [1, 3, 5], true)) {
            try {
                $supplier = $this->suppliers[4];
                $this->accrualService->create($this->companyId, [
                    'partner_id'          => $supplier->id,
                    'chart_of_account_id' => $this->expenseAccounts['Professional Fees — Audit & Legal'],
                    'description'          => 'Accrued audit & legal fees — '.$monthStart->format('F Y'),
                    'accrual_date'         => (clone $monthStart)->endOfMonth()->toDateString(),
                    'amount'               => random_int(9000, 16000),
                ], $this->actor);
                $this->successCount++;
                $this->line('    Accrued professional fees recorded.');
            } catch (\Throwable $e) {
                $this->recordFailure('Accrual month '.($m + 1), $e);
            }
        }
    }

    // ── Treasury: shareholder funding, safe top-up, employee
    //    custody, OD draws/repayments ──────────────────────────────

    private function monthlyTreasuryMovements(Carbon $monthStart, int $m): void
    {
        $bank0 = $this->banks[0]['account'];
        $bank1 = $this->banks[1]['account'];
        $facility0 = $this->facilities[0];

        if ($m === 0) {
            try {
                foreach ([$bank0, $bank1] as $bank) {
                    $this->ocService->record($this->companyId, [
                        'partner_id'        => $this->shareholders[0]->id,
                        'partner_type'       => 'shareholder',
                        'collection_date'    => '2026-01-02',
                        'money_type'         => 'bank_deposit',
                        'currency_id'        => $this->egpId,
                        // Sized for real project contracts: opening
                        // hardware stock alone runs to ~5.36M EGP
                        // across 5 products × 90 units, before any
                        // monthly restocking or fixed assets — a
                        // token injection would immediately trip the
                        // insufficient-funds guard, same lesson
                        // learned the hard way on the Trading demo.
                        'amount'             => 5000000,
                        'destination_type'             => 'bank_account',
                        'destination_bank_account_id'  => $bank->id,
                        'credit_account_id' => $this->coaByCode('311'),
                    ], $this->actor);
                    $this->successCount++;
                }
                $this->line('    Shareholder capital injection recorded (5,000,000 EGP into each bank — 10,000,000 total).');
            } catch (\Throwable $e) {
                $this->recordFailure('Shareholder injection', $e);
            }
        }

        try {
            $this->imtService->record($this->companyId, [
                'from_leg_type'        => 'bank',
                'from_bank_account_id'  => $bank0->id,
                'to_leg_type'            => 'safe',
                'to_safe_account_id'     => $this->safeAccountId,
                'currency_id'            => $this->egpId,
                'amount'                 => 12000,
                'transfer_date'          => (clone $monthStart)->addDays(1)->toDateString(),
                'comment'                => 'Monthly safe top-up',
            ], $this->actor);
            $this->successCount++;
        } catch (\Throwable $e) {
            $this->recordFailure('Safe top-up month '.($m + 1), $e);
        }

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
                    'amount'             => 2200,
                    'safe_account_id'    => $this->safeAccountId,
                    'debit_account_id'   => $this->coaByCode('148'),
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
                    'amount'             => 2200,
                    'safe_account_id'    => $this->safeAccountId,
                    'credit_account_id' => $this->coaByCode('148'),
                ], $this->actor);
                $this->successCount++;
            } catch (\Throwable $e) {
                $this->recordFailure('Employee custody return month '.($m + 1), $e);
            }
        }

        if ($m === 2 && ! empty($this->openSupplierBills)) {
            try {
                $bill = end($this->openSupplierBills);
                $amount = min(80000, (float) $bill->amount_outstanding);
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
                    $this->line('    Drew on Clean Overdraft to pay a hardware supplier (80,000 EGP).');
                }
            } catch (\Throwable $e) {
                $this->recordFailure('OD draw month '.($m + 1), $e);
            }
        }

        if ($m === 5) {
            try {
                $this->imtService->record($this->companyId, [
                    'from_leg_type'        => 'bank',
                    'from_bank_account_id'  => $bank0->id,
                    'to_leg_type'            => 'facility',
                    'to_credit_facility_id'  => $facility0->id,
                    'currency_id'            => $this->egpId,
                    'amount'                 => 30000,
                    'transfer_date'          => (clone $monthStart)->addDays(10)->toDateString(),
                    'comment'                => 'Partial repayment of Clean Overdraft draw',
                ], $this->actor);
                $this->successCount++;
                $this->line('    Partial OD repayment via internal transfer (30,000 EGP).');
            } catch (\Throwable $e) {
                $this->recordFailure('OD repayment month '.($m + 1), $e);
            }
        }

        if ($m === 6) {
            try {
                $this->opService->record($this->companyId, [
                    'partner_id'        => $this->shareholders[0]->id,
                    'partner_type'       => 'shareholder',
                    'payment_date'       => (clone $monthStart)->addDays(20)->toDateString(),
                    'money_type'         => 'outgoing_transfer',
                    'currency_id'        => $this->egpId,
                    'amount'             => 50000,
                    'source_type'                => 'bank_account',
                    'source_bank_account_id'     => $bank0->id,
                    'debit_account_id'   => $this->coaByCode('314'),
                ], $this->actor);
                $this->successCount++;
                $this->line('    Shareholder dividend payout recorded (50,000 EGP).');
            } catch (\Throwable $e) {
                $this->recordFailure('Shareholder dividend month '.($m + 1), $e);
            }
        }
    }

    // ── Pay down AP / collect AR older than ~25/20 days ──────────

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
    private function pickHardware(int $count, int $seed): array
    {
        $total = count($this->hardware);
        $picked = [];
        for ($i = 0; $i < $count; $i++) {
            $picked[] = $this->hardware[($seed + $i * 2) % $total];
        }
        return $picked;
    }

    /** @return array<int, array{model: Product, price: float}> */
    private function pickServices(int $count, int $seed): array
    {
        $total = count($this->services);
        $picked = [];
        $used = [];
        for ($i = 0; $i < $count; $i++) {
            $idx = ($seed + $i) % $total;
            if (in_array($idx, $used, true)) {
                $idx = ($idx + 1) % $total;
            }
            $used[] = $idx;
            $picked[] = $this->services[$idx];
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