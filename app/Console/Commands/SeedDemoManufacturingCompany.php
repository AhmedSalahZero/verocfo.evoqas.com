<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  SeedDemoManufacturingCompany
//
//  Creates one complete, realistic Manufacturing company — "Delta
//  Industries Co." — with a real Bill of Materials driving actual
//  production (raw materials → WIP → finished goods, standard
//  costing, overhead allocation), fixed assets purchased and
//  depreciated, and heavier use of banking facilities than the
//  Trading demo: two Clean Overdraft facilities drawn on repeatedly
//  through the year, plus a real Letter of Credit financing a raw
//  material import — on top of the same full partner book, treasury,
//  expenses, accruals, and prepaid/other-collection/other-payment
//  activity the Trading demo already exercises.
//
//  Everything here is created and posted through the ACTUAL
//  application Services, in the same create → activate/start/post
//  sequence the UI itself would use — nothing here bypasses real
//  business rules (WAC costing, standard-cost variance-free WIP,
//  insufficient-funds/facility-room guards, VAT/WHT, depreciation).
//
//  USAGE:
//    php artisan demo:seed-manufacturing-company
//
//  Safe to re-run: always creates a NEW company (timestamp-suffixed
//  name if "Delta Industries Co." already exists). Every transaction
//  block is wrapped in try/catch so one failure never stops the
//  whole run — failures are collected and printed in a summary at
//  the end, same pattern as the Trading demo.
//
//  Audit H8 (Sept 2026): refuses to run when APP_ENV=production
//  (RefusesInProduction trait).
//
//  @layer    Console Command
// ══════════════════════════════════════════════════════════════════

use App\Models\BillOfMaterial;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Currency;
use App\Models\CustomerInvoice;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FiscalYear;
use App\Models\InventoryLocation;
use App\Models\OverheadPool;
use App\Models\Process;
use App\Models\Machine;
use App\Models\LaborCategory;
use App\Models\Partner;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\SupplierBill;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\Accounting\AccrualService;
use App\Services\Accounting\BillOfMaterialService;
use App\Services\Accounting\CashExpensePaymentService;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\CustomerPaymentService;
use App\Services\Accounting\LaborCategoryService;
use App\Services\Accounting\MachineService;
use App\Services\Accounting\ProcessService;
use App\Services\Accounting\DeliveryNoteService;
use App\Services\Accounting\FixedAssetService;
use App\Services\Accounting\GoodsReceiptNoteService;
use App\Services\Accounting\OtherCollectionService;
use App\Services\Accounting\OtherPaymentService;
use App\Services\Accounting\OverheadPoolService;
use App\Services\Accounting\ProductionOrderService;
use App\Services\Accounting\PurchaseOrderService;
use App\Services\Accounting\StandardRateService;
use App\Services\Accounting\SupplierBillService;
use App\Services\Accounting\SupplierPaymentService;
use App\Services\ChartOfAccountService;
use App\Services\CompanyService;
use App\Services\FinancialInstitutions\BankService;
use App\Services\FinancialInstitutions\CleanOverdraftService;
use App\Services\FinancialInstitutions\InternalMoneyTransferService;
use App\Services\FinancialInstitutions\LetterOfCreditIssuanceService;
use App\Services\PartnerService;
use App\Services\ProductCategoryService;
use App\Services\ProductService;
use App\Services\Projects\SalesOrderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SeedDemoManufacturingCompany extends Command
{
    use \App\Console\Concerns\RefusesInProduction; // Audit H8

    protected $signature = 'demo:seed-manufacturing-company';

    protected $description = 'Creates a demo Manufacturing company (Growth tier) with a real BOM-driven production cycle, fixed assets, an LC import, and 8 months of posted transactions (Jan–Aug 2026) for testing.';

    // ── Services ─────────────────────────────────────────────────
    private CompanyService $companyService;
    private ProductCategoryService $categoryService;
    private ProductService $productService;
    private PartnerService $partnerService;
    private ChartOfAccountService $coaService;
    private BankService $bankService;
    private CleanOverdraftService $odService;
    private LetterOfCreditIssuanceService $lcService;
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
    private BillOfMaterialService $bomService;
    private OverheadPoolService $overheadPoolService;
    private StandardRateService $standardRateService;
    private ProcessService $processService;
    private MachineService $machineService;
    private LaborCategoryService $laborCategoryService;
    private ProductionOrderService $productionOrderService;
    private FixedAssetService $fixedAssetService;

    // ── Runtime state ────────────────────────────────────────────
    private User $actor;
    private Company $company;
    private int $companyId;
    private int $egpId;
    private int $net30Id;
    private int $masterLocationId;
    private int $safeAccountId;
    private int $fiscalYearId;

    /** @var array<int, array{model: Product, cost: float}> raw materials */
    private array $rawMaterials = [];
    /** @var array<int, array{model: Product, price: float, bom: BillOfMaterial}> finished goods */
    private array $finishedGoods = [];
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
    private ?OverheadPool $overheadPool = null;
    // Routing master data (Section 4/5) — see V2 seeder's identical
    // property for the full explanation; same one-of-each-is-enough
    // reasoning applies here.
    private ?Process $demoProcess = null;
    private ?Machine $demoMachine = null;
    private ?LaborCategory $demoLaborCategory = null;

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
        LetterOfCreditIssuanceService $lcService,
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
        BillOfMaterialService $bomService,
        OverheadPoolService $overheadPoolService,
        StandardRateService $standardRateService,
        ProcessService $processService,
        MachineService $machineService,
        LaborCategoryService $laborCategoryService,
        ProductionOrderService $productionOrderService,
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
        $this->lcService       = $lcService;
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
        $this->bomService            = $bomService;
        $this->overheadPoolService   = $overheadPoolService;
        $this->standardRateService   = $standardRateService;
        $this->processService        = $processService;
        $this->machineService        = $machineService;
        $this->laborCategoryService  = $laborCategoryService;
        $this->productionOrderService = $productionOrderService;
        $this->fixedAssetService     = $fixedAssetService;

        $this->actor = User::where('role', User::ROLE_SUPER_ADMIN)->first();
        if (! $this->actor) {
            $this->error('No Super Admin user found — run SuperAdminSeeder first (php artisan db:seed --class=SuperAdminSeeder).');
            return self::FAILURE;
        }

        $this->egpId  = (int) Currency::withoutGlobalScopes()->whereNull('company_id')->where('code', 'EGP')->value('id');
        $this->net30Id = (int) PaymentTerm::withoutGlobalScopes()->whereNull('company_id')->where('code', 'NET30')->value('id');
        if (! $this->egpId || ! $this->net30Id) {
            $this->error('EGP currency or NET30 payment term not found — run "php artisan db:seed" first.');
            return self::FAILURE;
        }

        $this->info('── Step 1/8 — Creating the company ──────────────────');
        $this->createCompany();

        $this->info('── Step 2/8 — Raw materials & finished goods catalogue ─');
        $this->createCatalogue();

        $this->info('── Step 3/8 — Overhead pool, Routing master data & standard costing rates ─');
        $this->createStandardCosting();

        $this->info('── Step 4/8 — Bill of Materials ──────────────────────');
        $this->createBillsOfMaterial();

        $this->info('── Step 5/8 — Partners ───────────────────────────────');
        $this->createPartners();

        $this->info('── Step 6/8 — Treasury (banks, safe, OD facilities, LC) ─');
        $this->createTreasury();

        $this->info('── Step 7/8 — Custom expense accounts ────────────────');
        $this->createExpenseAccounts();

        $this->info('── Step 8/8 — Fixed assets + 8 months of transactions ─');
        $this->buyFixedAssets();
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
        $name = 'Delta Industries Co.';
        if (Company::withoutGlobalScopes()->where('name_en', $name)->exists()) {
            $name .= ' ('.now()->format('H:i:s').')';
        }

        $this->company = $this->companyService->create([
            'name_en'                 => $name,
            'name_ar'                 => 'شركة دلتا الصناعية',
            'company_type'            => ['Manufacturing'],
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

        $fy = FiscalYear::where('company_id', $this->companyId)->first();
        $this->fiscalYearId = $fy->id;

        $this->line("  Company created: {$this->company->name_en} (id={$this->companyId})");
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 2 — Raw materials & finished goods
    // ══════════════════════════════════════════════════════════════

    private function createCatalogue(): void
    {
        $unitId = (int) UnitOfMeasure::withoutGlobalScopes()->whereNull('company_id')->where('code', 'UNIT')->value('id');

        $rawCategory = $this->categoryService->create($this->companyId, ['name_en' => 'Raw Materials'], $this->actor);
        $finishedCategory = $this->categoryService->create($this->companyId, ['name_en' => 'Finished Products'], $this->actor);

        $rawMaterialNames = [
            'Steel Sheet 2mm',
            'Aluminum Rod',
            'Electronic Circuit Board',
            'Plastic Resin Pellets',
            'Copper Wire Coil',
            'Industrial Fastener Set',
        ];
        foreach ($rawMaterialNames as $i => $name) {
            $product = $this->productService->create($this->companyId, [
                'use_auto_code'   => true,
                'name_en'         => $name,
                'category_id'     => $rawCategory->id,
                'usage_types'     => [Product::TYPE_RAW_MATERIAL],
                'primary_uom_id'  => $unitId,
                'reorder_level'   => 50,
                'reorder_qty'     => 300,
            ], $this->actor);

            $cost = round(60 + $i * 45.5, 2);
            $this->rawMaterials[] = ['model' => $product, 'cost' => $cost];
        }

        $finishedGoodNames = [
            'Industrial Cooling Fan',
            'Control Panel Unit',
            'Metal Bracket Assembly',
            'Electric Motor Housing',
        ];
        foreach ($finishedGoodNames as $name) {
            $product = $this->productService->create($this->companyId, [
                'use_auto_code'   => true,
                'name_en'         => $name,
                'category_id'     => $finishedCategory->id,
                'usage_types'     => [Product::TYPE_SALEABLE],
                // Manufacturing companies have 3 valid natures (tradable/
                // manufactured/service) so — unlike the Trading demo —
                // this can't auto-resolve; it must be explicit.
                'nature'          => Product::NATURE_MANUFACTURED,
                'primary_uom_id'  => $unitId,
                'reorder_level'   => 10,
                'reorder_qty'     => 40,
            ], $this->actor);

            $this->finishedGoods[] = ['model' => $product, 'price' => 0.0, 'bom' => null];
        }

        $this->line('  '.count($this->rawMaterials).' raw materials, '.count($this->finishedGoods).' finished goods created.');
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 4 — Bill of Materials (one per finished good)
    // ══════════════════════════════════════════════════════════════

    private function createBillsOfMaterial(): void
    {
        // Each finished good consumes 3 raw materials at a fixed
        // quantity-per-unit — output_quantity = 1, so every BOM line's
        // quantity_per_batch is already "per one finished unit",
        // keeping the consumeMaterial() math a plain multiplication
        // later (qty_per_unit × units_produced).
        $recipes = [
            0 => [[0, 2], [1, 1], [4, 3]], // Cooling Fan: 2 steel, 1 aluminum, 3 copper wire
            1 => [[2, 1], [3, 2], [5, 4]], // Control Panel: 1 PCB, 2 plastic resin, 4 fasteners
            2 => [[0, 3], [5, 6]],         // Metal Bracket: 3 steel, 6 fasteners
            3 => [[1, 2], [4, 2], [5, 3]], // Electric Motor Housing: 2 aluminum, 2 copper wire, 3 fasteners
        ];

        $totalCostByFg = [];

        foreach ($this->finishedGoods as $fgIndex => &$fg) {
            $lines = [];
            $materialCost = 0.0;
            foreach ($recipes[$fgIndex] as [$rawIndex, $qtyPerUnit]) {
                $raw = $this->rawMaterials[$rawIndex];
                $lines[] = [
                    'component_product_id' => $raw['model']->id,
                    'quantity_per_batch'    => $qtyPerUnit,
                    'uom_id'                 => $raw['model']->primary_uom_id,
                ];
                $materialCost += $raw['cost'] * $qtyPerUnit;
            }

            // One Routing Operation per BOM (Section 5) — same shared
            // Process/Machine/Labor Category created in
            // createStandardCosting() (now run first, Step 3).
            // Preserves the same 0.75 labor hr / 0.5 machine hr the
            // old flat fields used to carry for every demo BOM here.
            $operations = [[
                'process_id'               => $this->demoProcess->id,
                'default_machine_id'       => $this->demoMachine->id,
                'labor_category_id'        => $this->demoLaborCategory->id,
                'standard_labor_hours'     => 0.75,
                'standard_machine_hours'   => 0.5,
            ]];

            $bom = $this->bomService->create($this->companyId, [
                'product_id'      => $fg['model']->id,
                'output_quantity' => 1,
                'effective_date'  => '2026-01-01',
                'lines'           => $lines,
                'operations'      => $operations,
            ], $this->actor);

            // Bug fix: create() always starts a BOM as Draft (a
            // deliberate safety feature — see BillOfMaterialService
            // header). Production Orders only ever resolve to an
            // Active version (resolveEffective()), so a demo BOM that
            // was created but never activated is invisible to every
            // Production Order the rest of this script tries to run.
            $this->bomService->activate($this->companyId, $bom, $this->actor);

            $fg['bom'] = $bom;
            // Rough sell price: material cost + labor/overhead
            // allowance + a trading margin, so invoices look realistic.
            $fg['price'] = round(($materialCost + 60) * 1.55, 2);
            $totalCostByFg[$fgIndex] = $materialCost;
        }
        unset($fg);

        $this->line('  '.count($this->finishedGoods).' Bills of Material created.');
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 3 — Overhead pool, Routing master data (Process/Machine/
    //  Labor Category — VeroCFO_Manufacturing_Costing_Redesign_v1_0.md,
    //  Section 4/5), and standard labor/overhead rates. Moved ahead of
    //  Bill of Materials (previously Step 3, now Step 4) — a BOM's
    //  Routing Operations need an existing Machine already linked to
    //  the Overhead Pool created here.
    // ══════════════════════════════════════════════════════════════

    private function createStandardCosting(): void
    {
        $this->overheadPool = $this->overheadPoolService->create($this->companyId, [
            'name'            => 'Factory Overhead',
            'allocation_base' => 'machine_hours',
        ], $this->actor);

        $this->demoProcess = $this->processService->create($this->companyId, [
            'name' => 'General Machining & Assembly',
        ], $this->actor);

        $this->demoMachine = $this->machineService->create($this->companyId, [
            'name'               => 'Main Production Line',
            'process_id'         => $this->demoProcess->id,
            'status'             => 'active',
            'overhead_pool_ids'  => [$this->overheadPool->id],
        ], $this->actor);

        $this->demoLaborCategory = $this->laborCategoryService->create($this->companyId, [
            'name' => 'Machine Operator',
        ], $this->actor);

        // Annual rates — EGP per labor hour / per machine hour.
        $this->standardRateService->setLaborCategoryRate($this->companyId, $this->fiscalYearId, $this->demoLaborCategory->id, '45.00', $this->actor, 'Machine Operator — 2026');
        $this->standardRateService->setOverheadPoolRate($this->companyId, $this->fiscalYearId, $this->overheadPool->id, '30.00', $this->actor, 'Factory overhead — 2026');

        $this->line('  Overhead pool, Process, Machine & Labor Category created; labor rate 45.00 EGP/hr, overhead rate 30.00 EGP/machine-hr.');
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 5 — Partners
    // ══════════════════════════════════════════════════════════════

    private function createPartners(): void
    {
        $customerNames = [
            'Cairo Industrial Supplies', 'Delta Engineering Works', 'Alexandria Manufacturing Group',
            'Nile Valley Contractors', 'Upper Egypt Construction Co.',
        ];
        foreach ($customerNames as $name) {
            $this->customers[] = $this->partnerService->create($this->companyId, [
                'name_en'             => $name,
                'types'                => ['customer'],
                'credit_limit'         => 400000,
                'collection_term_id'   => $this->net30Id,
                'country'              => 'Egypt',
            ], $this->actor);
        }

        $supplierNames = [
            'Egyptian Steel & Metals Co.', 'Nile Plastics & Polymers', 'Cairo Electronics Components',
            'Suez Import Trading — Raw Materials', 'Ahmed Fathy & Co. — Audit & Legal Services',
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
            ['Hassan Ali', 'Production Manager'],
            ['Nourhan Samir', 'Finance Manager'],
            ['Mostafa Kamal', 'Quality Control Supervisor'],
            ['Rania Adel', 'Procurement Officer'],
        ];
        foreach ($employeeNames as [$name, $title]) {
            $this->employees[] = $this->partnerService->create($this->companyId, [
                'name_en'     => $name,
                'types'        => ['employee'],
                'job_title'    => $title,
                'department'   => 'Operations',
            ], $this->actor);
        }

        $shareholderData = [['Karim Adel', 65], ['Delta Holding Group', 35]];
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
    //  STEP 6 — Treasury: Banks, Safe, OD facilities, LC
    // ══════════════════════════════════════════════════════════════

    private function createTreasury(): void
    {
        $banksParentId = $this->coaByCode('132');
        $cashParentId  = $this->coaByCode('131');
        $od223001Id    = $this->coaByCode('223001');

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
                'account_number'  => '200'.random_int(1000000, 9999999),
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
                // A manufacturing company leans harder on its OD lines
                // than the Trading demo — bigger limit, used more often
                // across the year (see monthlyTreasuryMovements()).
                'facility_limit'        => 3000000,
                'outstanding_balance'   => 0,
                'balance_date'          => '2026-01-01',
                'borrowing_rate'        => 18,
                'bank_margin_rate'      => 3,
                'min_interest_rate'     => 1,
                'hdb_rate'              => 0.5,
                'admin_fee_rate'        => 0.25,
                'settled_max_days'      => 90,
                'follows_corridor_rate' => false,
                'purpose'               => 'Working capital / raw material financing',
            ], $this->actor);
            $facility->update(['bank_account_id' => $account->id]);

            $this->facilities[] = $facility;

            $this->line("  Bank: {$bankName} — current account + Clean Overdraft facility (limit 3,000,000 EGP)");
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 7 — Custom expense accounts
    // ══════════════════════════════════════════════════════════════

    private function createExpenseAccounts(): void
    {
        $gaParentId  = $this->coaByCode('54');
        $salesParentId = $this->coaByCode('52');
        $mktParentId = $this->coaByCode('53');

        $leaves = [
            ['Factory Rent',                       $gaParentId],
            ['Utilities — Electricity & Water',    $gaParentId],
            ['Telecom & Internet',                 $gaParentId],
            ['Factory Supplies Expense',            $gaParentId],
            ['Professional Fees — Audit & Legal',   $gaParentId],
            ['Insurance Expense',                   $gaParentId],
            ['Outbound Freight',                    $salesParentId],
            ['Sales Commissions',                   $salesParentId],
            ['Trade Show & Marketing',              $mktParentId],
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
    //  STEP 8a — Fixed assets (bought once, in January)
    // ══════════════════════════════════════════════════════════════

    /** @var array<int, array{asset: \App\Models\FixedAsset, from: Carbon}> */
    private array $fixedAssets = [];

    private function buyFixedAssets(): void
    {
        $assetDefs = [
            ['name' => 'CNC Production Machine',   'account_code' => '111003', 'cost' => 850000, 'life_months' => 96, 'residual' => 50000],
            ['name' => 'Factory Delivery Truck',   'account_code' => '111004', 'cost' => 620000, 'life_months' => 72, 'residual' => 40000],
            ['name' => 'Office & QC Computers',    'account_code' => '111006', 'cost' => 95000,  'life_months' => 36, 'residual' => 5000],
        ];

        $supplier = $this->suppliers[3]; // Suez Import Trading — also our general capex vendor here

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
                $grn = $this->grnService->post($grn, $this->actor); // auto-creates the FixedAsset register row

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
                continue; // depreciation hasn't started for this asset yet
            }
            try {
                // FixedAssetService::postMonthlyDepreciation() expects
                // Laravel's own Illuminate\Support\Carbon, not the
                // plain Carbon\Carbon this whole command uses — the
                // two are related (Support\Carbon extends Carbon\Carbon)
                // but not interchangeable for a strict type-hint in the
                // other direction, so it has to be converted here.
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
    //  STEP 8b — Eight months of transactions
    // ══════════════════════════════════════════════════════════════

    private function runTransactions(): void
    {
        $this->seedInitialRawMaterialStock();
        $this->runInitialProductionBatch();
        $this->issueImportLetterOfCredit();

        for ($m = 0; $m < 8; $m++) {
            $monthStart = Carbon::create(2026, 1, 1)->addMonths($m);
            $this->line('');
            $this->info('  Month '.($m + 1).' — '.$monthStart->format('F Y'));

            $this->monthlyPurchaseCycle($monthStart, $m);
            $this->monthlyProductionCycle($monthStart, $m);
            $this->monthlySalesCycle($monthStart, $m);
            $this->monthlyTreasuryMovements($monthStart, $m);
            $this->monthlyExpenses($monthStart, $m);
            $this->postMonthlyDepreciation($monthStart);
            $this->monthlyAccrualsAndPrepaid($monthStart, $m);
            $this->settleAgingPayables($monthStart);
            $this->settleAgingReceivables($monthStart);
        }
    }

    // ── Initial raw material stock (2026-01-01) — enough for the
    //    first production batch and several months of restocking ──

    private function seedInitialRawMaterialStock(): void
    {
        try {
            $chunks = array_chunk($this->rawMaterials, 3);
            foreach ($chunks as $i => $chunk) {
                $supplier = $this->suppliers[$i % 2];

                $lines = [];
                foreach ($chunk as $rm) {
                    $lines[] = [
                        'product_id'   => $rm['model']->id,
                        'description'  => $rm['model']->name_en,
                        'quantity'     => 2000,
                        'uom_id'       => $rm['model']->primary_uom_id,
                        'unit_price'   => $rm['cost'],
                    ];
                }

                $po = $this->poService->create($this->companyId, [
                    'partner_id'      => $supplier->id,
                    'subject'         => 'Opening raw material stock',
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
                        'description'                  => $line->product?->name_en ?? 'Raw material',
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
                    'supplier_invoice_number'  => 'OPEN-RM-'.($i + 1),
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

            $this->line('  Opening raw material stock posted — 2,000 units of every raw material, dated 2026-01-01.');
        } catch (\Throwable $e) {
            $this->recordFailure('Initial raw material stock', $e);
        }
    }

    // ── One production batch of every finished good, before any
    //    sales happen — same reasoning as the Trading demo's opening
    //    stock: sales must never run ahead of what's actually made.

    private function runInitialProductionBatch(): void
    {
        foreach ($this->finishedGoods as $fg) {
            $this->produce($fg, 60, '2026-01-05', 'Opening production batch');
        }
    }

    /**
     * Runs one Production Order end to end: create → start → consume
     * every BOM component → labor → overhead → complete. Quantities
     * consumed are computed straight from the BOM (quantity_per_batch
     * × units produced — output_quantity is 1 on every BOM here, see
     * createBillsOfMaterial()).
     */
    private function produce(array $fg, float $quantity, string $date, string $label): void
    {
        try {
            $order = $this->productionOrderService->create($this->companyId, [
                'product_id'                => $fg['model']->id,
                'bill_of_material_id'      => $fg['bom']->id,
                'quantity_to_produce'      => $quantity,
                'raw_material_location_id' => $this->masterLocationId,
                'output_location_id'      => $this->masterLocationId,
                'planned_start_date'      => $date,
            ], $this->actor);
            $this->productionOrderService->start($order, $this->actor);
            $this->successCount += 2;

            foreach ($fg['bom']->lines as $bomLine) {
                $qtyNeeded = bcmul((string) $bomLine->quantity_per_batch, (string) $quantity, 4);
                $this->productionOrderService->consumeMaterial(
                    $order, $bomLine->component_product_id, $qtyNeeded, $this->actor
                );
                $this->successCount++;
            }

            // Section 5 — read the same figure off the BOM's Routing
            // Operations now (this demo BOM has exactly one), instead
            // of the removed flat fields.
            $laborHours = round((float) $fg['bom']->operations->sum('standard_labor_hours') * $quantity, 2);
            $this->productionOrderService->recordLaborCost(
                $order, (string) $laborHours, $this->actor, $date
            );
            $this->successCount++;

            $machineHours = round((float) $fg['bom']->operations->sum('standard_machine_hours') * $quantity, 2);
            $this->productionOrderService->recordOverheadCost(
                $order, $this->overheadPool, (string) $machineHours, $this->actor, $date
            );
            $this->successCount++;

            $this->productionOrderService->complete($order, $this->actor, $date);
            $this->successCount++;

            $this->line("    {$label}: {$order->order_code} — {$quantity} units of {$fg['model']->name_en} completed.");
        } catch (\Throwable $e) {
            $this->recordFailure($label.' — '.$fg['model']->name_en, $e);
        }
    }

    // ── One real Letter of Credit financing a raw material import ──

    private function issueImportLetterOfCredit(): void
    {
        try {
            $supplier = $this->suppliers[3]; // Suez Import Trading
            $bank = $this->banks[0]['account'];

            $this->lcService->create($this->companyId, [
                'issuance_type'    => 'facility',
                'posting_mode'     => 'new_issuance',
                'lc_type'          => 'Sight LC',
                'transaction_name' => 'Raw Material Import — Steel & Aluminum',
                'bank_id'          => $this->banks[0]['bank']->id,
                'currency_id'      => $this->egpId,
                'supplier_partner_id' => $supplier->id,
                'issuance_date'    => '2026-02-01',
                'duration_days'    => 90,
                'lc_amount'        => 500000,
                'exchange_rate'    => 1,
                'commission_rate_pct'       => 1,
                'fees_commission_account_id' => $bank->id,
                'financed_by'      => 'self',
            ], $this->actor);
            $this->successCount++;
            $this->line('  Letter of Credit issued — 500,000 EGP raw material import (90 days).');
        } catch (\Throwable $e) {
            $this->recordFailure('Letter of Credit issuance', $e);
        }
    }

    // ── Purchase cycle: raw materials, PO → GRN → Bill ───────────

    private function monthlyPurchaseCycle(Carbon $monthStart, int $m): void
    {
        for ($i = 0; $i < 2; $i++) {
            try {
                $supplier = $this->suppliers[($m * 2 + $i) % 4];
                $issuance = (clone $monthStart)->addDays(2 + $i * 5);

                $lines = [];
                $picked = $this->pickRawMaterials(3, $m * 10 + $i);
                foreach ($picked as $rm) {
                    $qty = random_int(150, 400);
                    $lines[] = [
                        'product_id'   => $rm['model']->id,
                        'description'  => $rm['model']->name_en,
                        'quantity'     => $qty,
                        'uom_id'       => $rm['model']->primary_uom_id,
                        'unit_price'   => $rm['cost'],
                    ];
                }

                $po = $this->poService->create($this->companyId, [
                    'partner_id'      => $supplier->id,
                    'subject'         => 'Raw material restock',
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
                        'description'                  => $line->product?->name_en ?? 'Raw material',
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

    // ── Production cycle: one batch of one finished good per month ──

    private function monthlyProductionCycle(Carbon $monthStart, int $m): void
    {
        $fg = $this->finishedGoods[$m % count($this->finishedGoods)];
        $this->produce($fg, 40, (clone $monthStart)->addDays(8)->toDateString(), 'Month '.($m + 1).' production run');
    }

    // ── Sales cycle: SO → Delivery → Invoice, finished goods only ──

    private function monthlySalesCycle(Carbon $monthStart, int $m): void
    {
        for ($i = 0; $i < 3; $i++) {
            try {
                $customer = $this->customers[($m * 3 + $i) % 5];
                $issuance = (clone $monthStart)->addDays(12 + $i * 4);

                $lines = [];
                $picked = $this->pickFinishedGoods(random_int(1, 2), $m * 20 + $i);
                foreach ($picked as $fg) {
                    $qty = random_int(3, 12);
                    $lines[] = [
                        'product_id'          => $fg['model']->id,
                        'description'         => $fg['model']->name_en,
                        'quantity'             => $qty,
                        'uom_id'               => $fg['model']->primary_uom_id,
                        'unit_price'           => $fg['price'],
                        'revenue_account_id'  => $fg['model']->revenue_account_id,
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
                'payee_description' => 'Landlord — monthly factory rent',
                'payment_date'       => (clone $monthStart)->addDays(1)->toDateString(),
                'money_type'         => 'outgoing_transfer',
                'currency_id'        => $this->egpId,
                'source_type'        => 'bank_account',
                'source_bank_account_id' => $bank->id,
                'lines' => [[
                    'description'         => 'Factory rent — '.$monthStart->format('F Y'),
                    'expense_account_id'  => $this->expenseAccounts['Factory Rent'],
                    'subtotal'             => 28000,
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
                    'subtotal'             => random_int(8000, 14000),
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
                    'subtotal'             => 2200,
                ]],
            ], $this->actor);
            $this->successCount++;

            $this->line('    Monthly rent/utilities/telecom posted.');
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
                        'description'          => 'Annual factory & equipment insurance — 2026',
                        'unit_price'            => 60000,
                        'quantity'               => 1,
                        'amortization_months'   => 12,
                    ]],
                ], $this->actor);
                $bill = $this->sbService->post($bill, $this->actor);
                $this->openSupplierBills[] = $bill;
                $this->successCount++;
                $this->line('    Prepaid factory insurance bill posted (amortizes over 12 months).');
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
                    'amount'               => random_int(10000, 18000),
                ], $this->actor);
                $this->successCount++;
                $this->line('    Accrued professional fees recorded.');
            } catch (\Throwable $e) {
                $this->recordFailure('Accrual month '.($m + 1), $e);
            }
        }
    }

    // ── Treasury: shareholder funding, employee custody, safe
    //    top-up, and heavier OD facility usage than the Trading demo ──

    private function monthlyTreasuryMovements(Carbon $monthStart, int $m): void
    {
        $bank0 = $this->banks[0]['account'];
        $bank1 = $this->banks[1]['account'];
        $facility0 = $this->facilities[0];
        $facility1 = $this->facilities[1];

        // Shareholder capital injection — January only, split across
        // both banks (settlements later pick a bank at random, so
        // both need real float from day one).
        if ($m === 0) {
            try {
                foreach ([$bank0, $bank1] as $bank) {
                    $this->ocService->record($this->companyId, [
                        'partner_id'        => $this->shareholders[0]->id,
                        'partner_type'       => 'shareholder',
                        'collection_date'    => '2026-01-02',
                        'money_type'         => 'bank_deposit',
                        'currency_id'        => $this->egpId,
                        // Manufacturing carries more raw-material and
                        // fixed-asset investment than the Trading demo
                        // — sized accordingly (opening raw stock alone
                        // runs into the millions across 6 materials ×
                        // 2,000 units, plus ~1.57M in fixed assets).
                        'amount'             => 6000000,
                        'destination_type'             => 'bank_account',
                        'destination_bank_account_id'  => $bank->id,
                        'credit_account_id' => $this->coaByCode('311'),
                    ], $this->actor);
                    $this->successCount++;
                }
                $this->line('    Shareholder capital injection recorded (6,000,000 EGP into each bank — 12,000,000 total).');
            } catch (\Throwable $e) {
                $this->recordFailure('Shareholder injection', $e);
            }
        }

        // Safe top-up — must run AFTER the shareholder injection
        // above (money can't leave an account before it arrives).
        try {
            $this->imtService->record($this->companyId, [
                'from_leg_type'        => 'bank',
                'from_bank_account_id'  => $bank0->id,
                'to_leg_type'            => 'safe',
                'to_safe_account_id'     => $this->safeAccountId,
                'currency_id'            => $this->egpId,
                'amount'                 => 18000,
                'transfer_date'          => (clone $monthStart)->addDays(1)->toDateString(),
                'comment'                => 'Monthly safe top-up',
            ], $this->actor);
            $this->successCount++;
        } catch (\Throwable $e) {
            $this->recordFailure('Safe top-up month '.($m + 1), $e);
        }

        // Employee custody advance/return, alternating months.
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
                    'amount'             => 2500,
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
                    'amount'             => 2500,
                    'safe_account_id'    => $this->safeAccountId,
                    'credit_account_id' => $this->coaByCode('148'),
                ], $this->actor);
                $this->successCount++;
            } catch (\Throwable $e) {
                $this->recordFailure('Employee custody return month '.($m + 1), $e);
            }
        }

        // ── Heavier OD facility usage than the Trading demo ──────
        // Facility 0 (CIB): draw in month 3 to cover a raw-material
        // bill, partially repay in month 6.
        if ($m === 2 && ! empty($this->openSupplierBills)) {
            try {
                $bill = end($this->openSupplierBills);
                $amount = min(120000, (float) $bill->amount_outstanding);
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
                    $this->line('    Drew on CIB Clean Overdraft to pay a raw-material supplier (120,000 EGP).');
                }
            } catch (\Throwable $e) {
                $this->recordFailure('CIB OD draw month '.($m + 1), $e);
            }
        }

        // Facility 1 (NBE): a SECOND, independent draw in month 4 —
        // this is the "increased banking facility usage" the Trading
        // demo didn't have: two facilities actually carrying balances
        // at the same time, not just one.
        if ($m === 3 && ! empty($this->openSupplierBills)) {
            try {
                $bill = end($this->openSupplierBills);
                $amount = min(90000, (float) $bill->amount_outstanding);
                if ($amount > 0) {
                    $this->spService->record($this->companyId, [
                        'partner_id'    => $bill->partner_id,
                        'payment_type'   => 'settlement',
                        'payment_date'   => (clone $monthStart)->addDays(12)->toDateString(),
                        'money_type'     => 'outgoing_transfer',
                        'pay_currency_id' => $this->egpId,
                        'amount'          => $amount,
                        'source_type'              => 'credit_facility',
                        'source_credit_facility_id' => $facility1->id,
                        'settlements' => [[
                            'supplier_bill_id'   => $bill->id,
                            'settlement_amount'   => $amount,
                        ]],
                    ], $this->actor);
                    $this->successCount++;
                    $this->line('    Drew on NBE Clean Overdraft to pay a raw-material supplier (90,000 EGP).');
                }
            } catch (\Throwable $e) {
                $this->recordFailure('NBE OD draw month '.($m + 1), $e);
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
                    'amount'                 => 60000,
                    'transfer_date'          => (clone $monthStart)->addDays(10)->toDateString(),
                    'comment'                => 'Partial repayment of CIB Overdraft draw',
                ], $this->actor);
                $this->successCount++;
                $this->line('    Partial CIB OD repayment via internal transfer (60,000 EGP).');
            } catch (\Throwable $e) {
                $this->recordFailure('CIB OD repayment month '.($m + 1), $e);
            }
        }

        if ($m === 7) {
            try {
                $this->imtService->record($this->companyId, [
                    'from_leg_type'        => 'bank',
                    'from_bank_account_id'  => $bank1->id,
                    'to_leg_type'            => 'facility',
                    'to_credit_facility_id'  => $facility1->id,
                    'currency_id'            => $this->egpId,
                    'amount'                 => 45000,
                    'transfer_date'          => (clone $monthStart)->addDays(10)->toDateString(),
                    'comment'                => 'Partial repayment of NBE Overdraft draw',
                ], $this->actor);
                $this->successCount++;
                $this->line('    Partial NBE OD repayment via internal transfer (45,000 EGP).');
            } catch (\Throwable $e) {
                $this->recordFailure('NBE OD repayment month '.($m + 1), $e);
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
                    'amount'             => 80000,
                    'source_type'                => 'bank_account',
                    'source_bank_account_id'     => $bank0->id,
                    'debit_account_id'   => $this->coaByCode('314'),
                ], $this->actor);
                $this->successCount++;
                $this->line('    Shareholder dividend payout recorded (80,000 EGP).');
            } catch (\Throwable $e) {
                $this->recordFailure('Shareholder dividend month '.($m + 1), $e);
            }
        }
    }

    // ── Pay down AP / collect AR older than ~25 days ─────────────

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

    /** @return array<int, array{model: Product, cost: float}> */
    private function pickRawMaterials(int $count, int $seed): array
    {
        $total = count($this->rawMaterials);
        $picked = [];
        for ($i = 0; $i < $count; $i++) {
            $picked[] = $this->rawMaterials[($seed + $i * 2) % $total];
        }
        return $picked;
    }

    /** @return array<int, array{model: Product, price: float, bom: BillOfMaterial}> */
    private function pickFinishedGoods(int $count, int $seed): array
    {
        $total = count($this->finishedGoods);
        $picked = [];
        $used = [];
        for ($i = 0; $i < $count; $i++) {
            $idx = ($seed + $i * 3) % $total;
            if (in_array($idx, $used, true)) {
                $idx = ($idx + 1) % $total;
            }
            $used[] = $idx;
            $picked[] = $this->finishedGoods[$idx];
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