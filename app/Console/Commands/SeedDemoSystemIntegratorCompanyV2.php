<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  SeedDemoSystemIntegratorCompanyV2
//
//  A much larger, longer-running sibling of SeedDemoSystemIntegratorCompany
//  — same "Nova Systems Integration Co." story, same proven patterns
//  (partners, treasury, PO/GRN/Bill, SO/Delivery/Invoice, accruals,
//  fixed assets), but rebuilt around Mahmoud's brief:
//
//    - 32 months of history: 1 Jan 2024 → 31 Aug 2026 (not 8 months)
//    - ~29 mid-size Project/Contracts (25M–50M EGP each), scheduled
//      so invoiced revenue lands at roughly 350M EGP in 2024,
//      450M EGP in 2025, and 400M EGP across Jan–Aug 2026. Every
//      contract's execution window runs 9–16 months, so most
//      genuinely straddle two calendar years even though billing
//      itself happens as one milestone at kickoff (keeps the
//      per-year revenue math predictable and checkable).
//    - Heavy Letter of Guarantee usage: two LG Agreements (rate
//      cards) at CIB and NBE, each covering Bid Bond / Advance
//      Payment LG / Performance LG / Final LG. About a third of
//      projects get a Bid Bond before award (issued, then
//      cancelled on award), every project gets an Advance Payment
//      LG + Performance LG, about half get a Final/warranty LG
//      near completion (with the Performance LG liquidated at the
//      same time).
//    - Heavier Overdraft ("ODA") utilization: Clean OD at both CIB
//      and NBE with far more draws/repayments than the v1 demo, plus
//      the Contract Assignment OD pledging several different
//      projects over the 32 months (not just one).
//    - Letters of Credit: a third bank (AAIB) issues an import LC
//      roughly every 4 months to finance hardware restocking ahead
//      of the bigger projects, with a real issue → pay supplier →
//      (for bank-financed ones) settle-the-bank-debt lifecycle.
//    - HR / Payroll actually used: 12 employee payroll profiles
//      across Sales / Marketing / G&A / Cost-of-Service, with a
//      real Payroll Run created, calculated, and POSTED every
//      single month, plus two annual raises.
//
//  USAGE:
//    php artisan demo:seed-system-integrator-company-v2
//
//  Safe to re-run: always creates a NEW company. Every transaction
//  block is wrapped in try/catch so one failure never stops the
//  whole run — check the failure list printed at the end.
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
use App\Models\EmployeePayrollProfile;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityLgType;
use App\Models\FinancialInstitutions\LgIssuance;
use App\Models\InventoryLocation;
use App\Models\Partner;
use App\Models\PaymentTerm;
use App\Models\PayrollRun;
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
use App\Services\Accounting\EmployeePayrollProfileService;
use App\Services\Accounting\FixedAssetService;
use App\Services\Accounting\GoodsReceiptNoteService;
use App\Services\Accounting\OtherCollectionService;
use App\Services\Accounting\OtherPaymentService;
use App\Services\Accounting\PayrollRunService;
use App\Services\Accounting\PurchaseOrderService;
use App\Services\Accounting\SupplierBillService;
use App\Services\Accounting\SupplierPaymentService;
use App\Services\ChartOfAccountService;
use App\Services\CompanyService;
use App\Services\FinancialInstitutions\BankService;
use App\Services\FinancialInstitutions\CleanOverdraftService;
use App\Services\FinancialInstitutions\ContractAssignmentOdService;
use App\Services\FinancialInstitutions\InternalMoneyTransferService;
use App\Services\FinancialInstitutions\LetterOfCreditIssuanceService;
use App\Services\FinancialInstitutions\LetterOfCreditService;
use App\Services\FinancialInstitutions\LetterOfGuaranteeService;
use App\Services\FinancialInstitutions\LgIssuanceService;
use App\Services\FiscalYearService;
use App\Services\PartnerService;
use App\Services\ProductCategoryService;
use App\Services\ProductService;
use App\Services\Projects\ProjectService;
use App\Services\Projects\SalesOrderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SeedDemoSystemIntegratorCompanyV2 extends Command
{
    use \App\Console\Concerns\RefusesInProduction; // Audit H8

    protected $signature = 'demo:seed-system-integrator-company-v2';

    protected $description = 'Creates a LARGE demo System Integrator company (Growth tier): 32 months (Jan 2024 - Aug 2026), ~29 mid-size projects sized to ~350M/450M/400M EGP revenue per year-slice, heavy LG/OD/LC banking activity, and a full monthly Payroll cycle.';

    // ── Services ─────────────────────────────────────────────────
    private CompanyService $companyService;
    private ProductCategoryService $categoryService;
    private ProductService $productService;
    private PartnerService $partnerService;
    private ChartOfAccountService $coaService;
    private BankService $bankService;
    private CleanOverdraftService $odService;
    private ContractAssignmentOdService $contractOdService;
    private LetterOfGuaranteeService $lgAgreementService;
    private LgIssuanceService $lgIssuanceService;
    private LetterOfCreditIssuanceService $lcService;
    private LetterOfCreditService $lcAgreementService;
    private FiscalYearService $fiscalYearService;
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
    private EmployeePayrollProfileService $payrollProfileService;
    private PayrollRunService $payrollRunService;

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
    /** @var array<int, array{model: Product, name: string}> */
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
    /** @var CreditFacility[] — [0]=CIB Clean OD, [1]=NBE Clean OD, [2]=Contract Assignment OD (CIB) */
    private array $facilities = [];
    /** @var CreditFacility[] — [0]=CIB LG Agreement, [1]=NBE LG Agreement */
    private array $lgAgreements = [];
    /** @var ?CreditFacility — AAIB Import LC Agreement */
    private ?CreditFacility $lcAgreement = null;
    /** @var array<int, array<string, int>> facility_lg_type ids keyed [agreementIndex][lgTypeName] */
    private array $lgTypeIds = [];
    /** @var array<string, int> */
    private array $expenseAccounts = [];
    /** @var EmployeePayrollProfile[] */
    private array $payrollProfiles = [];

    /** @var array<int, array{schedule: array, project: Project, performanceLg: ?LgIssuance, endMonth: int}> */
    private array $activeProjects = [];
    /** @var array<int, LgIssuance> keyed by schedule index */
    private array $issuedBidBonds = [];
    /** @var array<int, array<int>> pending Final-LG actions keyed by trigger month => [scheduleIndex,...] */
    private array $pendingFinalLg = [];

    /** @var string[] */
    private array $failures = [];
    private int $successCount = 0;

    /** @var SupplierBill[] */
    private array $openSupplierBills = [];
    /** @var CustomerInvoice[] */
    private array $openCustomerInvoices = [];

    private const TOTAL_MONTHS = 32; // Jan 2024 .. Aug 2026 inclusive
    private const START_YEAR = 2024;
    private const START_MONTH = 1;

    public function handle(
        CompanyService $companyService,
        ProductCategoryService $categoryService,
        ProductService $productService,
        PartnerService $partnerService,
        ChartOfAccountService $coaService,
        BankService $bankService,
        CleanOverdraftService $odService,
        ContractAssignmentOdService $contractOdService,
        LetterOfGuaranteeService $lgAgreementService,
        LgIssuanceService $lgIssuanceService,
        LetterOfCreditIssuanceService $lcService,
        LetterOfCreditService $lcAgreementService,
        FiscalYearService $fiscalYearService,
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
        EmployeePayrollProfileService $payrollProfileService,
        PayrollRunService $payrollRunService,
    ): int {
        // Audit H8 — never on the live server
        if ($this->refuseInProduction()) {
            return 1;
        }

        $this->companyService        = $companyService;
        $this->categoryService       = $categoryService;
        $this->productService        = $productService;
        $this->partnerService        = $partnerService;
        $this->coaService            = $coaService;
        $this->bankService           = $bankService;
        $this->odService             = $odService;
        $this->contractOdService     = $contractOdService;
        $this->lgAgreementService    = $lgAgreementService;
        $this->lgIssuanceService     = $lgIssuanceService;
        $this->lcService             = $lcService;
        $this->lcAgreementService    = $lcAgreementService;
        $this->fiscalYearService     = $fiscalYearService;
        $this->imtService            = $imtService;
        $this->projectService        = $projectService;
        $this->soService             = $soService;
        $this->dnService             = $dnService;
        $this->ciService             = $ciService;
        $this->cpService             = $cpService;
        $this->poService             = $poService;
        $this->grnService            = $grnService;
        $this->sbService             = $sbService;
        $this->spService             = $spService;
        $this->accrualService        = $accrualService;
        $this->cashExpService        = $cashExpService;
        $this->ocService             = $ocService;
        $this->opService             = $opService;
        $this->fixedAssetService     = $fixedAssetService;
        $this->payrollProfileService = $payrollProfileService;
        $this->payrollRunService     = $payrollRunService;

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

        $this->info('── Step  1/9 — Creating the company ──────────────────');
        $this->createCompany();

        $this->info('── Step  2/9 — Hardware & service catalogue ───────────');
        $this->createCatalogue();

        $this->info('── Step  3/9 — Partners ───────────────────────────────');
        $this->createPartners();

        $this->info('── Step  4/9 — Treasury (banks, safe, OD + LG facilities) ─');
        $this->createTreasury();

        $this->info('── Step  5/9 — Custom expense accounts ────────────────');
        $this->createExpenseAccounts();

        $this->info('── Step  6/9 — Fixed assets ───────────────────────────');
        $this->buyFixedAssets();

        $this->info('── Step  7/9 — HR: payroll profiles for 12 employees ──');
        $this->setupPayrollProfiles();

        $this->info('── Step  8/9 — Opening hardware stock ─────────────────');
        $this->seedInitialHardwareStock();

        $this->info('── Step  9/9 — 32 months of projects, banking & payroll ─');
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
        $name = 'Vero Systems Integration Co. (Enterprise)';
        if (Company::withoutGlobalScopes()->where('name_en', $name)->exists()) {
            $name .= ' ('.now()->format('H:i:s').')';
        }

        $this->company = $this->companyService->create([
            'name_en'                 => $name,
            'name_ar'                 => 'شركة فيرو لتكامل النظم',
            'company_type'            => ['Service with Inventory'],
            'grn_policy'               => 'po_required',
            'base_currency'            => 'EGP',
            'fiscal_year_start_month'  => 1,
            'opening_balance_date'     => '2024-01-01',
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
    //  Prices reflect a realistic bigger-ticket 2024-2026 Egyptian
    //  market (post-devaluation EGP), not the earlier small demo.
    // ══════════════════════════════════════════════════════════════

    private function createCatalogue(): void
    {
        $unitId = (int) UnitOfMeasure::withoutGlobalScopes()->whereNull('company_id')->where('code', 'UNIT')->value('id');

        $hwCategory = $this->categoryService->create($this->companyId, ['name_en' => 'Network & IT Hardware'], $this->actor);
        $svcCategory = $this->categoryService->create($this->companyId, ['name_en' => 'Professional Services'], $this->actor);

        $hardwareDefs = [
            ['Enterprise 48-Port Network Switch',        165000, 239250],
            ['Rack-Mount Server Unit',                    480000, 696000],
            ['Fiber Optic Cable Reel (300m)',               38000,  55100],
            ['UPS Backup Power Unit',                      210000, 304500],
            ['Enterprise Wireless Access Point',            42000,  60900],
            ['Enterprise Firewall / Security Appliance',   320000, 464000],
            ['Structured Cabling & Data Center Rack Kit',   95000, 137750],
        ];
        foreach ($hardwareDefs as [$name, $cost, $price]) {
            $product = $this->productService->create($this->companyId, [
                'use_auto_code'   => true,
                'name_en'         => $name,
                'category_id'     => $hwCategory->id,
                'usage_types'     => [Product::TYPE_SALEABLE],
                // Service-with-Inventory companies have TWO valid
                // natures (tradable/service) so this can't auto-resolve.
                'nature'          => Product::NATURE_TRADABLE,
                'primary_uom_id'  => $unitId,
                'reorder_level'   => 20,
                'reorder_qty'     => 100,
            ], $this->actor);

            $this->hardware[] = ['model' => $product, 'cost' => (float) $cost, 'price' => (float) $price];
        }

        $serviceNames = [
            'Installation & Configuration Labor',
            'Project Management Services',
            'System Testing & Commissioning',
            'Annual Support & Maintenance Contract',
        ];
        foreach ($serviceNames as $name) {
            $product = $this->productService->create($this->companyId, [
                'use_auto_code'   => true,
                'name_en'         => $name,
                'category_id'     => $svcCategory->id,
                'usage_types'     => [Product::TYPE_SALEABLE],
                'nature'          => Product::NATURE_SERVICE,
                'primary_uom_id'  => $unitId,
            ], $this->actor);

            $this->services[] = ['model' => $product, 'name' => $name];
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
            'Egyptian Ministry of Education — Connectivity Program', 'Suez Canal Container Terminal',
            'Banque Misr Regional Branches Rollout', 'New Administrative Capital — Smart City Authority',
        ];
        foreach ($customerNames as $name) {
            $this->customers[] = $this->partnerService->create($this->companyId, [
                'name_en'             => $name,
                'types'                => ['customer'],
                'credit_limit'         => 200000000,
                'collection_term_id'   => $this->net30Id,
                'country'              => 'Egypt',
            ], $this->actor);
        }

        $supplierNames = [
            'Cisco Regional Distributor Egypt', 'Dell Enterprise Hardware Partner', 'Fiber Networks Supply Co.',
            'Power Systems & UPS Trading', 'Huawei Enterprise Egypt', 'Ahmed Fathy & Co. — Audit & Legal Services',
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
            ['Youssef Tarek',    'Project Director',                  'Operations',       75000, EmployeePayrollProfile::CATEGORY_COST_OF_SERVICE],
            ['Dina Mahmoud',     'Senior Network Engineer',           'Operations',       45000, EmployeePayrollProfile::CATEGORY_COST_OF_SERVICE],
            ['Omar Sherif',      'Systems Integration Engineer',      'Operations',       38000, EmployeePayrollProfile::CATEGORY_COST_OF_SERVICE],
            ['Nour Fathy',       'Systems Integration Engineer',      'Operations',       38000, EmployeePayrollProfile::CATEGORY_COST_OF_SERVICE],
            ['Karim Hussein',    'Project Manager',                   'Operations',       55000, EmployeePayrollProfile::CATEGORY_COST_OF_SERVICE],
            ['Salma Nabil',      'Finance & Contracts Manager',       'Finance',          50000, EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN],
            ['Ahmed Ezzat',      'Procurement Officer',               'Procurement',      30000, EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN],
            ['Mariam Adel',      'HR & Admin Officer',                'HR & Admin',       28000, EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN],
            ['Hossam Kamal',     'Warehouse & Logistics Supervisor',  'Logistics',        25000, EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN],
            ['Tamer Younis',     'Business Development Manager',      'Sales',            48000, EmployeePayrollProfile::CATEGORY_SALES],
            ['Rana Samir',       'Sales Account Manager',             'Sales',            35000, EmployeePayrollProfile::CATEGORY_SALES],
            ['Yasmin Farouk',    'Marketing & Bids Coordinator',      'Marketing',        27000, EmployeePayrollProfile::CATEGORY_MARKETING],
        ];
        foreach ($employeeNames as [$name, $title, $dept, $salary, $category]) {
            $partner = $this->partnerService->create($this->companyId, [
                'name_en'     => $name,
                'types'        => ['employee'],
                'job_title'    => $title,
                'department'   => $dept,
            ], $this->actor);
            // salary + category stashed on the partner object for step 7
            $partner->seedBaseSalary = $salary;
            $partner->seedCategory   = $category;
            $this->employees[] = $partner;
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
    //  STEP 4 — Treasury: 3 Banks, Clean OD x2, Contract Assignment
    //  OD, and 2 LG Agreements (rate cards) — the heart of the
    //  "heavier LG / ODA usage" request.
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

        // ── 3 banks: CIB + NBE carry the OD/LG business, AAIB is the
        //    dedicated trade-finance bank for Letters of Credit. ────
        $bankDefs = ['Commercial International Bank (CIB)', 'National Bank of Egypt (NBE)', 'Arab African International Bank (AAIB)'];

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
        }

        // ── Clean Overdraft — CIB and NBE, sized for ~1.2B EGP of
        //    revenue over the period. ──────────────────────────────
        $odLimits = [45000000, 35000000];
        foreach ([0, 1] as $i) {
            try {
                $facility = $this->odService->create($this->companyId, [
                    'bank_id'               => $this->banks[$i]['bank']->id,
                    'start_date'            => '2024-01-01',
                    'review_date'           => '2025-01-01',
                    'account_number'        => 'OD-'.random_int(100000, 999999),
                    'currency_id'           => $this->egpId,
                    'coa_account_id'        => $od223001Id,
                    'facility_limit'        => $odLimits[$i],
                    'outstanding_balance'   => 0,
                    'balance_date'          => '2024-01-01',
                    'borrowing_rate'        => 18,
                    'bank_margin_rate'      => 3,
                    'min_interest_rate'     => 1,
                    'hdb_rate'              => 0.5,
                    'admin_fee_rate'        => 0.25,
                    'settled_max_days'      => 90,
                    'follows_corridor_rate' => false,
                    'purpose'               => 'Working capital for hardware imports & project execution',
                ], $this->actor);
                $facility->update(['bank_account_id' => $this->banks[$i]['account']->id]);
                $this->facilities[] = $facility;
                $this->successCount++;
                $this->line('  Clean Overdraft created at '.$this->banks[$i]['bank']->name_en.' (limit '.number_format($odLimits[$i]).' EGP).');
            } catch (\Throwable $e) {
                $this->recordFailure('Clean OD facility '.$i, $e);
            }
        }

        // ── Contract Assignment OD — projects pledged as collateral ──
        try {
            $facility = $this->contractOdService->create($this->companyId, $this->banks[0]['bank'], [
                'bank_id'               => $this->banks[0]['bank']->id,
                'start_date'            => '2024-01-01',
                'review_date'           => '2025-01-01',
                'account_number'        => 'CAOD-'.random_int(100000, 999999),
                'currency_id'           => $this->egpId,
                'coa_account_id'        => $od223003Id,
                'facility_limit'        => 70000000,
                'outstanding_balance'   => 0,
                'balance_date'          => '2024-01-01',
                'borrowing_rate'        => 17,
                'bank_margin_rate'      => 2.5,
                'min_interest_rate'     => 1,
                'hdb_rate'              => 0.5,
                'admin_fee_rate'        => 0.25,
                'settled_max_days'      => 90,
                'max_lending_limit_per_contract' => 30000000,
                'follows_corridor_rate' => false,
                'purpose'               => 'Financing against pledged customer contracts',
            ], $this->actor);
            $facility->update(['bank_account_id' => $this->banks[0]['account']->id]);
            $this->facilities[2] = $facility;
            $this->successCount++;
            $this->line('  Contract Assignment Overdraft facility created (limit 70,000,000 EGP, max 30,000,000 EGP per contract).');
        } catch (\Throwable $e) {
            $this->recordFailure('Contract Assignment OD facility creation', $e);
        }

        // ── LG Agreements — CIB and NBE, each with a 4-type rate
        //    card. This is the headline "heavier LG usage" feature. ──
        $lgDefs = [
            [
                'bankIdx' => 0, 'limit' => 130000000,
                'types' => [
                    ['lg_type' => 'Bid Bond',           'cash_cover_pct' => 0,  'commission_rate_pct' => 0.50, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 2000, 'issuance_fee_amount' => 1000],
                    ['lg_type' => 'Advance Payment LG', 'cash_cover_pct' => 10, 'commission_rate_pct' => 1.25, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 5000, 'issuance_fee_amount' => 2000],
                    ['lg_type' => 'Performance LG',     'cash_cover_pct' => 10, 'commission_rate_pct' => 1.00, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 5000, 'issuance_fee_amount' => 2000],
                    ['lg_type' => 'Final LG',           'cash_cover_pct' => 5,  'commission_rate_pct' => 0.75, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 3000, 'issuance_fee_amount' => 1500],
                ],
            ],
            [
                'bankIdx' => 1, 'limit' => 90000000,
                'types' => [
                    ['lg_type' => 'Bid Bond',           'cash_cover_pct' => 0,  'commission_rate_pct' => 0.60, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 2500, 'issuance_fee_amount' => 1200],
                    ['lg_type' => 'Advance Payment LG', 'cash_cover_pct' => 15, 'commission_rate_pct' => 1.35, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 6000, 'issuance_fee_amount' => 2200],
                    ['lg_type' => 'Performance LG',     'cash_cover_pct' => 12, 'commission_rate_pct' => 1.10, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 6000, 'issuance_fee_amount' => 2200],
                    ['lg_type' => 'Final LG',           'cash_cover_pct' => 5,  'commission_rate_pct' => 0.80, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 3500, 'issuance_fee_amount' => 1700],
                ],
            ],
        ];

        foreach ($lgDefs as $i => $def) {
            try {
                $facility = $this->lgAgreementService->create($this->companyId, [
                    'bank_id'        => $this->banks[$def['bankIdx']]['bank']->id,
                    'currency_id'    => $this->egpId,
                    'facility_limit' => $def['limit'],
                    'start_date'     => '2024-01-01',
                    'review_date'    => '2025-01-01',
                    'purpose'        => 'Bid Bond / Advance Payment / Performance / Final LGs for customer contracts',
                    'lg_types'       => $def['types'],
                ], $this->actor);
                $this->lgAgreements[$i] = $facility;

                foreach ($facility->lgTypes as $lgType) {
                    $this->lgTypeIds[$i][$lgType->lg_type] = $lgType->id;
                }

                $this->successCount++;
                $this->line('  LG Agreement created at '.$this->banks[$def['bankIdx']]['bank']->name_en.' (limit '.number_format($def['limit']).' EGP, 4 LG types).');
            } catch (\Throwable $e) {
                $this->recordFailure('LG Agreement '.$i, $e);
            }
        }

        // ── Import LC Agreement at AAIB — needed so a bank-financed
        //    LC has somewhere to carry its bank-debt liability. The
        //    agreement-level service always leaves coa_account_id
        //    null (an "Issuance layer concern" by its own design,
        //    same as LG) so it's set directly here afterward, same
        //    pattern as bank_account_id above. ─────────────────────
        try {
            $lcFacility = $this->lcAgreementService->create($this->companyId, [
                'bank_id'          => $this->banks[2]['bank']->id,
                'name'             => 'AAIB Import LC Agreement',
                'currency_id'      => $this->egpId,
                'start_date'       => '2024-01-01',
                'review_date'      => '2025-01-01',
                'lc_security_type' => 'unsecured',
                'facility_limit'   => 150000000,
                'borrowing_rate'   => 16,
                'bank_margin_rate' => 2,
                'min_interest_rate' => 1,
                'hdb_rate'          => 0.5,
                'follows_corridor_rate' => false,
                'lc_types' => [
                    ['lc_type' => 'Sight LC', 'cash_cover_pct' => 0, 'commission_rate_pct' => 1, 'min_commission_fee_amount' => 3000, 'issuance_fee_amount' => 1500],
                ],
            ], $this->actor);
            $lcFacility->update(['coa_account_id' => $this->coaByCode('223005')]);
            $this->lcAgreement = $lcFacility;
            $this->successCount++;
            $this->line('  Import LC Agreement created at AAIB (limit 150,000,000 EGP).');
        } catch (\Throwable $e) {
            $this->recordFailure('Import LC Agreement', $e);
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
            ['Customs Clearance & Freight',        $gaParentId],
            ['Staff Training & Certification',     $gaParentId],
            ['Bank Guarantee & LG Charges',         $gaParentId],
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
    //  STEP 6 — Fixed assets (a fleet + lab + warehouse gear)
    // ══════════════════════════════════════════════════════════════

    /** @var array<int, array{asset: \App\Models\FixedAsset, from: Carbon}> */
    private array $fixedAssets = [];

    private function buyFixedAssets(): void
    {
        $assetDefs = [
            ['name' => 'Service Vehicle #1',              'account_code' => '111004', 'cost' => 520000,  'life_months' => 72, 'residual' => 40000],
            ['name' => 'Service Vehicle #2',              'account_code' => '111004', 'cost' => 520000,  'life_months' => 72, 'residual' => 40000],
            ['name' => 'Service Vehicle #3',              'account_code' => '111004', 'cost' => 520000,  'life_months' => 72, 'residual' => 40000],
            ['name' => 'Network Testing Lab Equipment',    'account_code' => '111006', 'cost' => 380000,  'life_months' => 48, 'residual' => 25000],
            ['name' => 'Office Fit-Out & IT Infrastructure','account_code' => '111006', 'cost' => 650000, 'life_months' => 60, 'residual' => 0],
            ['name' => 'Warehouse Racking & Handling Equipment', 'account_code' => '111006', 'cost' => 210000, 'life_months' => 60, 'residual' => 10000],
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
                    'issuance_date'   => '2024-01-05',
                    'start_date'      => '2024-01-05',
                    'end_date'        => '2024-02-04',
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
                    'received_date'           => '2024-01-10',
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
                    'supplier_invoice_date'    => '2024-01-10',
                    'bill_date'                => '2024-01-10',
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
    //  STEP 7 — HR: one payroll profile per employee
    // ══════════════════════════════════════════════════════════════

    private function setupPayrollProfiles(): void
    {
        foreach ($this->employees as $partner) {
            try {
                $profile = $this->payrollProfileService->create($this->companyId, [
                    'partner_id'         => $partner->id,
                    'base_salary'        => $partner->seedBaseSalary,
                    'currency_id'        => $this->egpId,
                    'expense_category'   => $partner->seedCategory,
                    'eosb_rate_percent'  => 8.33,
                ], $this->actor);
                $this->payrollProfiles[] = $profile;
                $this->successCount++;
            } catch (\Throwable $e) {
                $this->recordFailure('Payroll profile for '.$partner->name_en, $e);
            }
        }
        $this->line('  '.count($this->payrollProfiles).' payroll profiles created.');
    }

    // ══════════════════════════════════════════════════════════════
    //  Opening hardware stock (2024-01-01)
    // ══════════════════════════════════════════════════════════════

    private function seedInitialHardwareStock(): void
    {
        try {
            $chunks = array_chunk($this->hardware, 2);
            foreach ($chunks as $i => $chunk) {
                $supplier = $this->suppliers[$i % 4];

                $lines = [];
                foreach ($chunk as $hw) {
                    // Value-based sizing: hardware cost ranges from
                    // 38K to 480K EGP across the catalogue (a 12x
                    // spread), so a flat unit count blows up wildly
                    // on the expensive items. Target ~2M EGP of
                    // opening stock per line instead.
                    $qty = max(5, (int) round(2000000 / $hw['cost']));
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
                    'subject'         => 'Opening hardware stock',
                    'currency_id'     => $this->egpId,
                    'exchange_rate'   => 1,
                    'issuance_date'   => '2024-01-01',
                    'start_date'      => '2024-01-01',
                    'end_date'        => '2024-01-31',
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
                    'received_date'           => '2024-01-01',
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
                    'supplier_invoice_date'    => '2024-01-01',
                    'bill_date'                => '2024-01-01',
                    'currency_id'              => $this->egpId,
                    'payment_term_id'          => $this->net30Id,
                    'lines'                    => $billLines,
                ], $this->actor);
                $bill = $this->sbService->post($bill, $this->actor);
                $this->openSupplierBills[] = $bill;
                $this->successCount += 3;
            }

            $this->line('  Opening hardware stock posted — 300 units of every hardware item, dated 2024-01-01.');
        } catch (\Throwable $e) {
            $this->recordFailure('Initial hardware stock', $e);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  MAIN ENGINE — 32 months
    // ══════════════════════════════════════════════════════════════

    private function runTransactions(): void
    {
        // ── Shareholder capital injection to fund ramp-up ─────────
        try {
            foreach ([0, 1] as $i) {
                $this->ocService->record($this->companyId, [
                    'partner_id'        => $this->shareholders[0]->id,
                    'partner_type'       => 'shareholder',
                    'collection_date'    => '2024-01-02',
                    'money_type'         => 'bank_deposit',
                    'currency_id'        => $this->egpId,
                    'amount'             => 10000000,
                    'destination_type'             => 'bank_account',
                    'destination_bank_account_id'  => $this->banks[$i]['account']->id,
                    'credit_account_id' => $this->coaByCode('311'),
                ], $this->actor);
                $this->successCount++;
            }
            $this->line('  Shareholder capital injection recorded (10,000,000 EGP into each of CIB & NBE — 20,000,000 total).');
        } catch (\Throwable $e) {
            $this->recordFailure('Shareholder injection', $e);
        }

        $schedule = $this->buildProjectSchedule();

        // Group schedule entries by kickoff month, and precompute
        // Bid Bond trigger months (2 months before kickoff) for
        // roughly a third of the projects.
        $projectsByMonth = [];
        $bidBondByMonth = [];
        foreach ($schedule as $idx => $entry) {
            $projectsByMonth[$entry['month']][] = $idx;
            if ($idx % 3 === 0) {
                $bbMonth = max(0, $entry['month'] - 2);
                $bidBondByMonth[$bbMonth][] = $idx;
            }
        }

        for ($m = 0; $m < self::TOTAL_MONTHS; $m++) {
            $monthStart = Carbon::create(self::START_YEAR, self::START_MONTH, 1)->addMonths($m);
            $this->newLine();
            $this->info('  Month '.($m + 1).'/'.self::TOTAL_MONTHS.' — '.$monthStart->format('F Y'));

            // Fiscal Year 2024 is created automatically with the
            // company. Every 12 months after that, open the next
            // one — "Create Next Fiscal Year" is a real one-button
            // action in the app; this is that same action, just
            // called from code instead of clicked.
            if ($m === 12 || $m === 24) {
                try {
                    $fy = $this->fiscalYearService->createNext($this->companyId, $this->actor);
                    $this->successCount++;
                    $this->line('    Opened next Fiscal Year: '.$fy->name.' ('.$fy->start_date->toDateString().' to '.$fy->end_date->toDateString().').');
                } catch (\Throwable $e) {
                    $this->recordFailure('Opening Fiscal Year at month '.($m + 1), $e);
                }
            }

            $this->monthlyPurchaseCycle($monthStart, $m);

            foreach ($bidBondByMonth[$m] ?? [] as $idx) {
                $this->issueBidBond($schedule[$idx], $idx, $monthStart);
            }

            foreach ($projectsByMonth[$m] ?? [] as $idx) {
                $this->kickOffProject($schedule[$idx], $idx, $monthStart);
            }

            foreach ($this->pendingFinalLg[$m] ?? [] as $idx) {
                $this->closeOutProjectLg($idx, $monthStart);
            }

            if ($m % 4 === 0) {
                $this->issueTradeFinanceLc($monthStart, $m);
            }
            if ($m % 5 === 2) {
                $this->settleTradeFinanceLc($monthStart, $m);
            }

            $this->monthlyOdActivity($monthStart, $m);
            $this->monthlyExpenses($monthStart, $m);
            $this->postMonthlyDepreciation($monthStart);
            $this->monthlyAccrualsAndPrepaid($monthStart, $m);
            $this->monthlyPayroll($monthStart, $m);

            if ($m === 12 || $m === 24) {
                $this->annualSalaryRevision($monthStart);
            }

            $this->settleAgingPayables($monthStart);
            $this->settleAgingReceivables($monthStart);
        }
    }

    // ── Project schedule — pure data, month index 0 = Jan 2024 ────

    /** @return array<int, array{month: int, value: float, customer: int}> */
    private function buildProjectSchedule(): array
    {
        return [
            // 2024 — target ~350,000,000 EGP
            ['month' => 0,  'value' => 34000000, 'customer' => 0],
            ['month' => 1,  'value' => 38000000, 'customer' => 1],
            ['month' => 3,  'value' => 40000000, 'customer' => 2],
            ['month' => 4,  'value' => 30000000, 'customer' => 3],
            ['month' => 6,  'value' => 45000000, 'customer' => 4],
            ['month' => 7,  'value' => 28000000, 'customer' => 5],
            ['month' => 8,  'value' => 42000000, 'customer' => 6],
            ['month' => 10, 'value' => 48000000, 'customer' => 7],
            ['month' => 11, 'value' => 45000000, 'customer' => 8],
            // 2025 — target ~450,000,000 EGP
            ['month' => 12, 'value' => 38000000, 'customer' => 0],
            ['month' => 13, 'value' => 45000000, 'customer' => 1],
            ['month' => 14, 'value' => 30000000, 'customer' => 2],
            ['month' => 15, 'value' => 42000000, 'customer' => 3],
            ['month' => 16, 'value' => 48000000, 'customer' => 4],
            ['month' => 17, 'value' => 35000000, 'customer' => 5],
            ['month' => 18, 'value' => 50000000, 'customer' => 6],
            ['month' => 19, 'value' => 28000000, 'customer' => 7],
            ['month' => 20, 'value' => 44000000, 'customer' => 8],
            ['month' => 21, 'value' => 40000000, 'customer' => 0],
            ['month' => 22, 'value' => 50000000, 'customer' => 1],
            // Jan-Aug 2026 — target ~400,000,000 EGP
            ['month' => 24, 'value' => 50000000, 'customer' => 2],
            ['month' => 24, 'value' => 50000000, 'customer' => 3],
            ['month' => 25, 'value' => 45000000, 'customer' => 4],
            ['month' => 26, 'value' => 50000000, 'customer' => 5],
            ['month' => 27, 'value' => 40000000, 'customer' => 6],
            ['month' => 28, 'value' => 45000000, 'customer' => 7],
            ['month' => 29, 'value' => 35000000, 'customer' => 8],
            ['month' => 30, 'value' => 45000000, 'customer' => 0],
            ['month' => 31, 'value' => 40000000, 'customer' => 1],
        ];
    }

    // ── Purchase cycle: restock hardware monthly (scaled up) ──────

    private function monthlyPurchaseCycle(Carbon $monthStart, int $m): void
    {
        try {
            $supplier = $this->suppliers[$m % 4];
            $issuance = (clone $monthStart)->addDays(3);

            $lines = [];
            $picked = $this->pickHardware(3, $m * 10);
            foreach ($picked as $hw) {
                // Value-based sizing (see note in seedInitialHardwareStock)
                // — ~700K EGP of ambient restock per line per month.
                // The bulk of what a project actually needs is bought
                // separately, per-contract, inside kickOffProject().
                $qty = max(5, (int) round(700000 / $hw['cost']));
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
                'supplier_invoice_number'  => 'INV-'.strtoupper(substr(md5($po->id.'-'.$m), 0, 8)),
                'supplier_invoice_date'    => $receivedDate->toDateString(),
                'bill_date'                => $receivedDate->toDateString(),
                'currency_id'              => $this->egpId,
                'payment_term_id'          => $this->net30Id,
                'lines'                    => $billLines,
            ], $this->actor);
            $bill = $this->sbService->post($bill, $this->actor);
            $this->successCount++;

            $this->openSupplierBills[] = $bill;
            $this->line("    Hardware restock PO/GRN/Bill posted — {$supplier->name_en} ({$bill->bill_code})");
        } catch (\Throwable $e) {
            $this->recordFailure('Purchase cycle month '.($m + 1), $e);
        }
    }

    // ── Bid Bond LG — issued ~2 months before a subset of projects
    //    kick off, then cancelled once the contract is awarded. ────

    private function issueBidBond(array $entry, int $idx, Carbon $monthStart): void
    {
        try {
            $customer = $this->customers[$entry['customer']];
            $agreementIdx = $idx % 2;
            $facility = $this->lgAgreements[$agreementIdx] ?? null;
            $typeId = $this->lgTypeIds[$agreementIdx]['Bid Bond'] ?? null;
            if (! $facility || ! $typeId) {
                return;
            }

            $issuance = $this->lgIssuanceService->create($this->companyId, [
                'issuance_type'       => 'facility',
                'posting_mode'        => 'new_issuance',
                'lg_type'             => 'Bid Bond',
                'transaction_name'    => 'Bid Bond — Tender for '.$customer->name_en,
                'bank_id'             => $this->banks[$agreementIdx]['bank']->id,
                'currency_id'         => $this->egpId,
                'credit_facility_id'  => $facility->id,
                'facility_lg_type_id' => $typeId,
                'beneficiary_partner_id' => $customer->id,
                'issuance_date'       => $monthStart->toDateString(),
                'end_date'            => (clone $monthStart)->addDays(60)->toDateString(),
                'lg_amount'           => round($entry['value'] * 0.02, 2),
            ], $this->actor);

            $this->issuedBidBonds[$idx] = $issuance;
            $this->successCount++;
            $this->line('    Bid Bond LG issued for tender — '.$customer->name_en.' ('.number_format($entry['value'] * 0.02).' EGP).');
        } catch (\Throwable $e) {
            $this->recordFailure('Bid Bond issuance idx '.$idx, $e);
        }
    }

    // ── Kick off one Project/Contract: create → down payment →
    //    mixed hardware+service SO → Delivery → Invoice → LGs ──────

    private function kickOffProject(array $entry, int $idx, Carbon $monthStart): void
    {
        try {
            $customer = $this->customers[$entry['customer']];
            $contractValue = (float) $entry['value'];
            $startDate = (clone $monthStart)->addDays(2);
            $durationMonths = 9 + ($idx % 8); // 9..16 months — many straddle a calendar year
            $endDate = (clone $startDate)->addMonths($durationMonths);

            $hwPct = (55 + ($idx % 11)) / 100; // 0.55 .. 0.65
            $hwValue = round($contractValue * $hwPct, 2);
            $svcValue = round($contractValue - $hwValue, 2);

            $hwLines = $this->pickHardware(2, $idx * 5);
            $lines = [];
            $hwSplit = [0.55, 0.45];
            foreach ($hwLines as $i => $hw) {
                $lineValue = round($hwValue * $hwSplit[$i], 2);
                $qty = max(5, (int) round($lineValue / $hw['price'] / 5) * 5);
                $lines[] = [
                    'product_id'          => $hw['model']->id,
                    'description'         => $hw['model']->name_en,
                    'quantity'             => $qty,
                    'uom_id'               => $hw['model']->primary_uom_id,
                    'unit_price'           => $hw['price'],
                    'revenue_account_id'  => $hw['model']->revenue_account_id,
                ];
            }

            $svcSplit = [
                ['Installation & Configuration Labor',   0.35],
                ['Project Management Services',          0.30],
                ['System Testing & Commissioning',        0.15],
                ['Annual Support & Maintenance Contract', 0.20],
            ];
            foreach ($svcSplit as [$svcName, $pct]) {
                $svc = $this->serviceByName($svcName);
                $lines[] = [
                    'product_id'          => $svc['model']->id,
                    'description'         => $svc['name'],
                    'quantity'             => 1,
                    'uom_id'               => $svc['model']->primary_uom_id,
                    'unit_price'           => round($svcValue * $pct, 2),
                    'revenue_account_id'  => $svc['model']->revenue_account_id,
                ];
            }

            $project = $this->projectService->create($this->companyId, [
                'name_en'        => 'IT Infrastructure Contract — '.$customer->name_en.' — '.$startDate->format('Y'),
                'partner_id'     => $customer->id,
                'contract_value' => $contractValue,
                'currency_id'    => $this->egpId,
                'exchange_rate'  => 1,
                'start_date'     => $startDate->toDateString(),
                'end_date'       => $endDate->toDateString(),
            ], $this->actor);
            $this->successCount++;
            $this->line("    Project created: {$project->contract_code} — {$project->name_en} (contract value ".number_format($contractValue).' EGP)');

            // Bid Bond release, if one was issued for this contract
            if (isset($this->issuedBidBonds[$idx])) {
                try {
                    $this->lgIssuanceService->cancel($this->issuedBidBonds[$idx], [
                        'cancelled_at' => $startDate->toDateString(),
                        'notes'        => 'Contract awarded — bid bond released.',
                    ], $this->actor);
                    $this->successCount++;
                    $this->line('    Bid Bond released — contract awarded.');
                } catch (\Throwable $e) {
                    $this->recordFailure('Bid Bond release idx '.$idx, $e);
                }
            }

            // ── Down payment — 20% of contract value ───────────────
            $bank = $this->banks[$idx % 2]['account'];
            $downPayment = round($contractValue * 0.20, 2);
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
            $this->line('    Down payment collected: '.number_format($downPayment).' EGP (20% of contract value).');

            // ── Advance Payment LG against the down payment ────────
            $agreementIdx = $idx % 2;
            $performanceLg = null;
            try {
                $facility = $this->lgAgreements[$agreementIdx];
                $advTypeId = $this->lgTypeIds[$agreementIdx]['Advance Payment LG'];
                $this->lgIssuanceService->create($this->companyId, [
                    'issuance_type'       => 'facility',
                    'posting_mode'        => 'new_issuance',
                    'lg_type'             => 'Advance Payment LG',
                    'transaction_name'    => 'Advance Payment LG — '.$project->contract_code,
                    'bank_id'             => $this->banks[$agreementIdx]['bank']->id,
                    'currency_id'         => $this->egpId,
                    'credit_facility_id'  => $facility->id,
                    'facility_lg_type_id' => $advTypeId,
                    'beneficiary_partner_id' => $customer->id,
                    'contract_reference_project_id' => $project->id,
                    'issuance_date'       => $startDate->toDateString(),
                    'end_date'            => $endDate->toDateString(),
                    'lg_amount'           => $downPayment,
                    'cash_cover_account_id' => $this->banks[$agreementIdx]['account']->id,
                ], $this->actor);
                $this->successCount++;
                $this->line('    Advance Payment LG issued ('.number_format($downPayment).' EGP).');
            } catch (\Throwable $e) {
                $this->recordFailure('Advance Payment LG — '.$project->contract_code, $e);
            }

            // ── Performance LG (10% of contract value) ─────────────
            try {
                $facility = $this->lgAgreements[$agreementIdx];
                $perfTypeId = $this->lgTypeIds[$agreementIdx]['Performance LG'];
                $perfAmount = round($contractValue * 0.10, 2);
                $performanceLg = $this->lgIssuanceService->create($this->companyId, [
                    'issuance_type'       => 'facility',
                    'posting_mode'        => 'new_issuance',
                    'lg_type'             => 'Performance LG',
                    'transaction_name'    => 'Performance LG — '.$project->contract_code,
                    'bank_id'             => $this->banks[$agreementIdx]['bank']->id,
                    'currency_id'         => $this->egpId,
                    'credit_facility_id'  => $facility->id,
                    'facility_lg_type_id' => $perfTypeId,
                    'beneficiary_partner_id' => $customer->id,
                    'contract_reference_project_id' => $project->id,
                    'issuance_date'       => (clone $startDate)->addDays(10)->toDateString(),
                    'end_date'            => $endDate->toDateString(),
                    'lg_amount'           => $perfAmount,
                    'cash_cover_account_id' => $this->banks[$agreementIdx]['account']->id,
                ], $this->actor);
                $this->successCount++;
                $this->line('    Performance LG issued ('.number_format($perfAmount).' EGP).');
            } catch (\Throwable $e) {
                $this->recordFailure('Performance LG — '.$project->contract_code, $e);
            }

            // Schedule a Final LG + Performance LG liquidation near
            // contract completion, for about half of the projects,
            // ONLY if that month still falls inside our 32-month window.
            if ($idx % 2 === 0) {
                $endMonthApprox = $monthStart->diffInMonths($endDate) + $monthStart->month - $monthStart->month; // placeholder, computed properly below
            }
            $endMonthIndex = (int) Carbon::create(self::START_YEAR, self::START_MONTH, 1)->diffInMonths($endDate, false);
            if ($idx % 2 === 0 && $endMonthIndex >= 0 && $endMonthIndex < self::TOTAL_MONTHS) {
                $this->activeProjects[$idx] = [
                    'project'       => $project,
                    'customer'      => $customer,
                    'contractValue' => $contractValue,
                    'performanceLg' => $performanceLg,
                    'agreementIdx'  => $agreementIdx,
                    'endDate'       => $endDate,
                ];
                $this->pendingFinalLg[$endMonthIndex][] = $idx;
            }

            // ── Project-specific hardware procurement ──────────────
            // A contract this size can't rely on ambient monthly
            // restocking to have the right hardware sitting around —
            // procure exactly what this project's Delivery Note will
            // need (plus a 10% buffer), a few days ahead of delivery.
            try {
                $procureLines = [];
                foreach ($hwLines as $i => $hw) {
                    $soLine = $lines[$i]; // hwLines and $lines share index 0/1 for hardware
                    $procureQty = (int) ceil($soLine['quantity'] * 1.10);
                    $procureLines[] = [
                        'product_id'   => $hw['model']->id,
                        'description'  => $hw['model']->name_en,
                        'quantity'     => $procureQty,
                        'uom_id'       => $hw['model']->primary_uom_id,
                        'unit_price'   => $hw['cost'],
                    ];
                }
                $procureSupplier = $this->suppliers[$idx % 4];
                $procurePo = $this->poService->create($this->companyId, [
                    'partner_id'      => $procureSupplier->id,
                    'subject'         => 'Project hardware procurement — '.$project->contract_code,
                    'currency_id'     => $this->egpId,
                    'exchange_rate'   => 1,
                    'issuance_date'   => $startDate->toDateString(),
                    'start_date'      => $startDate->toDateString(),
                    'end_date'        => (clone $startDate)->addDays(20)->toDateString(),
                    'payment_term_id' => $this->net30Id,
                    'lines'           => $procureLines,
                ], $this->actor);
                $this->poService->activate($procurePo, $this->actor);

                $procureGrnLines = [];
                foreach ($procurePo->lines as $line) {
                    $procureGrnLines[] = [
                        'purchase_order_line_id' => $line->id,
                        'product_id'              => $line->product_id,
                        'quantity'                 => $line->quantity,
                        'uom_id'                   => $line->uom_id,
                        'unit_cost'                => $line->unit_price,
                    ];
                }
                $procureGrn = $this->grnService->create($this->companyId, [
                    'purchase_order_id'      => $procurePo->id,
                    'partner_id'              => $procureSupplier->id,
                    'inventory_location_id'   => $this->masterLocationId,
                    'received_date'           => (clone $startDate)->addDays(2)->toDateString(),
                    'lines'                   => $procureGrnLines,
                ], $this->actor);
                $procureGrn = $this->grnService->post($procureGrn, $this->actor);

                $procureBillLines = [];
                foreach ($procureGrn->lines as $line) {
                    $procureBillLines[] = [
                        'goods_receipt_note_line_id' => $line->id,
                        'product_id'                   => $line->product_id,
                        'description'                  => $line->product?->name_en ?? 'Hardware',
                        'quantity'                      => $line->quantity,
                        'uom_id'                        => $line->uom_id,
                        'unit_price'                    => $line->unit_cost,
                        'vat_rate'                       => 14,
                    ];
                }
                $procureBill = $this->sbService->create($this->companyId, [
                    'bill_type'               => 'goods',
                    'partner_id'               => $procureSupplier->id,
                    'purchase_order_id'        => $procurePo->id,
                    'supplier_invoice_number'  => 'PROJ-'.$project->contract_code,
                    'supplier_invoice_date'    => (clone $startDate)->addDays(2)->toDateString(),
                    'bill_date'                => (clone $startDate)->addDays(2)->toDateString(),
                    'currency_id'              => $this->egpId,
                    'payment_term_id'          => $this->net30Id,
                    'lines'                    => $procureBillLines,
                ], $this->actor);
                $procureBill = $this->sbService->post($procureBill, $this->actor);
                $this->openSupplierBills[] = $procureBill;
                $this->successCount += 3;
                $this->line('    Project-specific hardware procured (PO/GRN/Bill) ahead of delivery.');
            } catch (\Throwable $e) {
                $this->recordFailure('Project hardware procurement — '.$project->contract_code, $e);
            }

            // ── Sales Order — mixes hardware + service on one contract ─
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

            // Delivery Note — hardware (stocked) lines ONLY.
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

            // Invoice — ALL lines, one contract milestone invoice.
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
            $this->recordFailure('Project kickoff idx '.$idx, $e);
        }
    }

    // ── Final LG + Performance LG liquidation near completion ─────

    private function closeOutProjectLg(int $idx, Carbon $monthStart): void
    {
        if (! isset($this->activeProjects[$idx])) {
            return;
        }
        $ctx = $this->activeProjects[$idx];

        try {
            $agreementIdx = $ctx['agreementIdx'];
            $facility = $this->lgAgreements[$agreementIdx];
            $finalTypeId = $this->lgTypeIds[$agreementIdx]['Final LG'];
            $finalAmount = round($ctx['contractValue'] * 0.05, 2);

            $this->lgIssuanceService->create($this->companyId, [
                'issuance_type'       => 'facility',
                'posting_mode'        => 'new_issuance',
                'lg_type'             => 'Final LG',
                'transaction_name'    => 'Final / Warranty LG — '.$ctx['project']->contract_code,
                'bank_id'             => $this->banks[$agreementIdx]['bank']->id,
                'currency_id'         => $this->egpId,
                'credit_facility_id'  => $facility->id,
                'facility_lg_type_id' => $finalTypeId,
                'beneficiary_partner_id' => $ctx['customer']->id,
                'contract_reference_project_id' => $ctx['project']->id,
                'issuance_date'       => $monthStart->toDateString(),
                'end_date'            => (clone $ctx['endDate'])->addMonths(12)->toDateString(),
                'lg_amount'           => $finalAmount,
                'cash_cover_account_id' => $this->banks[$agreementIdx]['account']->id,
            ], $this->actor);
            $this->successCount++;
            $this->line('    Final/Warranty LG issued for '.$ctx['project']->contract_code.' ('.number_format($finalAmount).' EGP).');

            if ($ctx['performanceLg']) {
                $this->lgIssuanceService->liquidate($ctx['performanceLg'], [
                    'liquidated_at' => $monthStart->toDateString(),
                    'claim_amount'  => 0,
                    'liquidation_expense_account_id' => $this->expenseAccounts['Bank Guarantee & LG Charges'],
                ], $this->actor);
                $this->successCount++;
                $this->line('    Performance LG liquidated (no claim) — replaced by Final LG.');
            }
        } catch (\Throwable $e) {
            $this->recordFailure('Final LG close-out idx '.$idx, $e);
        }
    }

    // ── Trade-finance LC — hardware import financing ──────────────

    /** @var array<int, array{lc: \App\Models\FinancialInstitutions\LetterOfCreditIssuance, bill: SupplierBill}> */
    private array $openTradeFinanceLcs = [];

    private function issueTradeFinanceLc(Carbon $monthStart, int $m): void
    {
        try {
            $supplier = $this->suppliers[4]; // Huawei Enterprise Egypt
            $bank = $this->banks[2]; // AAIB
            $financedByBank = ($m % 8 === 0);

            $lines = [];
            $picked = $this->pickHardware(2, $m * 7);
            foreach ($picked as $hw) {
                // Value-based sizing — target ~6M EGP per line, so
                // the whole LC lands in a sensible ~12M EGP range
                // regardless of which two catalogue items get picked.
                $qty = max(5, (int) round(6000000 / $hw['cost']));
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
                'subject'         => 'LC-financed hardware import',
                'currency_id'     => $this->egpId,
                'exchange_rate'   => 1,
                'issuance_date'   => $monthStart->toDateString(),
                'start_date'      => $monthStart->toDateString(),
                'end_date'        => (clone $monthStart)->addDays(60)->toDateString(),
                'payment_term_id' => $this->net30Id,
                'lines'           => $lines,
            ], $this->actor);
            $this->poService->activate($po, $this->actor);

            $lcAmount = round(collect($lines)->sum(fn ($l) => $l['quantity'] * $l['unit_price']), 2);

            $lc = $this->lcService->create($this->companyId, [
                'issuance_type'    => 'facility',
                'posting_mode'     => 'new_issuance',
                'lc_type'          => 'Sight LC',
                'transaction_name' => 'Hardware Import — '.$monthStart->format('F Y'),
                'bank_id'          => $bank['bank']->id,
                'currency_id'      => $this->egpId,
                'credit_facility_id' => $this->lcAgreement?->id,
                'supplier_partner_id' => $supplier->id,
                'issuance_date'    => $monthStart->toDateString(),
                'duration_days'    => 90,
                'lc_amount'        => $lcAmount,
                'exchange_rate'    => 1,
                'commission_rate_pct'       => 1,
                'fees_commission_account_id' => $this->banks[0]['account']->id,
                'financed_by'      => $financedByBank ? 'bank' : 'self',
            ], $this->actor);
            $this->successCount++;
            $this->line('    Letter of Credit issued at AAIB — '.number_format($lcAmount).' EGP hardware import ('.($financedByBank ? 'bank-financed' : 'self-financed').').');

            $receivedDate = (clone $monthStart)->addDays(35);
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
                ];
            }
            $bill = $this->sbService->create($this->companyId, [
                'bill_type'               => 'goods',
                'partner_id'               => $supplier->id,
                'purchase_order_id'        => $po->id,
                'supplier_invoice_number'  => 'LC-IMP-'.($m + 1),
                'supplier_invoice_date'    => $receivedDate->toDateString(),
                'bill_date'                => $receivedDate->toDateString(),
                'currency_id'              => $this->egpId,
                'payment_term_id'          => $this->net30Id,
                'lines'                    => $billLines,
            ], $this->actor);
            $bill = $this->sbService->post($bill, $this->actor);
            $this->successCount++;

            $this->lcService->markAsPaid($lc, [
                'paid_at'          => $receivedDate->toDateString(),
                'supplier_bill_id' => $bill->id,
                'payment_account_id' => $this->banks[0]['account']->id,
            ], $this->actor);
            $this->successCount++;
            $this->line('    LC marked as paid — supplier settled via the Letter of Credit.');

            if ($financedByBank) {
                $this->openTradeFinanceLcs[] = ['lc' => $lc->fresh(), 'bill' => $bill];
            }
        } catch (\Throwable $e) {
            $this->recordFailure('Trade finance LC month '.($m + 1), $e);
        }
    }

    private function settleTradeFinanceLc(Carbon $monthStart, int $m): void
    {
        if (empty($this->openTradeFinanceLcs)) {
            return;
        }
        $ctx = array_shift($this->openTradeFinanceLcs);

        try {
            $lc = $ctx['lc']->fresh();
            if (! $lc || bccomp((string) $lc->bank_debt_outstanding, '0', 2) <= 0) {
                return;
            }
            $principal = (string) $lc->bank_debt_outstanding;
            $interest  = round((float) $principal * 0.015, 2);

            $this->lcService->settle($lc, [
                'principal_amount' => $principal,
                'interest_amount'  => $interest,
                'settlement_date'  => $monthStart->toDateString(),
                'settlement_from_account_id' => $this->banks[0]['account']->id,
                'interest_expense_account_id' => $this->coaByCode('551'),
            ], $this->actor);
            $this->successCount++;
            $this->line('    Bank-financed LC settled — principal '.number_format((float) $principal).' EGP + interest '.number_format($interest).' EGP repaid.');
        } catch (\Throwable $e) {
            $this->recordFailure('LC settlement month '.($m + 1), $e);
        }
    }

    // ── Overdraft draws & repayments — heavier utilization ────────

    private function monthlyOdActivity(Carbon $monthStart, int $m): void
    {
        // Two draws a month, alternating CIB / NBE Clean OD, paying
        // down a supplier bill each time — this is what actually
        // moves the "utilization" needle on both facilities.
        foreach ([0, 1] as $facilityIdx) {
            if (empty($this->openSupplierBills)) {
                continue;
            }
            try {
                $bill = $this->openSupplierBills[array_rand($this->openSupplierBills)];
                $bill->refresh();
                $outstanding = (float) $bill->amount_outstanding;
                if ($outstanding <= 0) {
                    continue;
                }
                $amount = round(min($outstanding, random_int(300000, 900000)), 2);
                $this->spService->record($this->companyId, [
                    'partner_id'    => $bill->partner_id,
                    'payment_type'   => 'settlement',
                    'payment_date'   => (clone $monthStart)->addDays(6 + $facilityIdx * 8)->toDateString(),
                    'money_type'     => 'outgoing_transfer',
                    'pay_currency_id' => $this->egpId,
                    'amount'          => $amount,
                    'source_type'              => 'credit_facility',
                    'source_credit_facility_id' => $this->facilities[$facilityIdx]->id,
                    'settlements' => [[
                        'supplier_bill_id'   => $bill->id,
                        'settlement_amount'   => $amount,
                    ]],
                ], $this->actor);
                $this->successCount++;
                $this->line('    Drew on '.$this->banks[$facilityIdx]['bank']->name_en.' Clean Overdraft ('.number_format($amount).' EGP).');
            } catch (\Throwable $e) {
                $this->recordFailure('OD draw facility '.$facilityIdx.' month '.($m + 1), $e);
            }
        }

        // A partial repayment every other month, alternating banks.
        if ($m % 2 === 1) {
            $facilityIdx = ($m / 2) % 2 === 0 ? 0 : 1;
            try {
                $this->imtService->record($this->companyId, [
                    'from_leg_type'        => 'bank',
                    'from_bank_account_id'  => $this->banks[$facilityIdx]['account']->id,
                    'to_leg_type'            => 'facility',
                    'to_credit_facility_id'  => $this->facilities[$facilityIdx]->id,
                    'currency_id'            => $this->egpId,
                    'amount'                 => random_int(200000, 500000),
                    'transfer_date'          => (clone $monthStart)->addDays(20)->toDateString(),
                    'comment'                => 'Partial repayment of Clean Overdraft draw',
                ], $this->actor);
                $this->successCount++;
                $this->line('    Partial OD repayment via internal transfer — '.$this->banks[$facilityIdx]['bank']->name_en.'.');
            } catch (\Throwable $e) {
                $this->recordFailure('OD repayment month '.($m + 1), $e);
            }
        }

        // Pledge a fresh project as Contract Assignment OD collateral
        // every few months (once one exists), instead of just once.
        if ($m > 0 && $m % 5 === 0 && ! empty($this->activeProjects)) {
            $candidate = end($this->activeProjects);
            try {
                $this->contractOdService->addAssignment($this->companyId, $this->facilities[2], [
                    'partner_id'            => $candidate['project']->partner_id,
                    'collateral_type'       => 'project',
                    'collateral_project_id' => $candidate['project']->id,
                    'assignment_date'       => $monthStart->toDateString(),
                    'lending_pct'           => 70,
                ], $this->actor);
                $this->successCount++;
                $this->line("    Project {$candidate['project']->contract_code} pledged as Contract Assignment OD collateral.");
            } catch (\Throwable $e) {
                $this->recordFailure('Pledge project as collateral month '.($m + 1), $e);
            }
        }
    }

    // ── Recurring expenses — rent, utilities, telecom, site visits ─

    private function monthlyExpenses(Carbon $monthStart, int $m): void
    {
        try {
            $bank = $this->banks[0]['account'];

            // Top up the safe BEFORE any safe-based expense this
            // month — utilities and site-visit payments below draw
            // from it, and it starts every run at zero.
            $this->imtService->record($this->companyId, [
                'from_leg_type'        => 'bank',
                'from_bank_account_id'  => $bank->id,
                'to_leg_type'            => 'safe',
                'to_safe_account_id'     => $this->safeAccountId,
                'currency_id'            => $this->egpId,
                'amount'                 => 90000,
                'transfer_date'          => (clone $monthStart)->addDays(1)->toDateString(),
                'comment'                => 'Monthly safe top-up',
            ], $this->actor);
            $this->successCount++;

            $this->cashExpService->record($this->companyId, [
                'payee_description' => 'Landlord — monthly office & warehouse rent',
                'payment_date'       => (clone $monthStart)->addDays(1)->toDateString(),
                'money_type'         => 'outgoing_transfer',
                'currency_id'        => $this->egpId,
                'source_type'        => 'bank_account',
                'source_bank_account_id' => $bank->id,
                'lines' => [[
                    'description'         => 'Office & warehouse rent — '.$monthStart->format('F Y'),
                    'expense_account_id'  => $this->expenseAccounts['Office Rent'],
                    'subtotal'             => 250000,
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
                    'subtotal'             => random_int(40000, 70000),
                ]],
            ], $this->actor);
            $this->successCount++;

            $this->cashExpService->record($this->companyId, [
                'payee_description' => 'Telecom provider',
                'payment_date'       => (clone $monthStart)->addDays(8)->toDateString(),
                'money_type'         => 'outgoing_transfer',
                'currency_id'        => $this->egpId,
                'source_type'        => 'bank_account',
                'source_bank_account_id' => $bank->id,
                'lines' => [[
                    'description'         => 'Telecom & Internet — '.$monthStart->format('F Y'),
                    'expense_account_id'  => $this->expenseAccounts['Telecom & Internet'],
                    'subtotal'             => 18000,
                ]],
            ], $this->actor);
            $this->successCount++;

            // Site visit travel — allocated to whichever project is
            // active this month (reporting-only tag).
            if (! empty($this->activeProjects)) {
                $ctx = $this->activeProjects[array_rand($this->activeProjects)];
                $this->cashExpService->record($this->companyId, [
                    'payee_description' => 'Site visit travel — '.$ctx['project']->contract_code,
                    'payment_date'       => (clone $monthStart)->addDays(14)->toDateString(),
                    'money_type'         => 'cash_payment',
                    'currency_id'        => $this->egpId,
                    'safe_account_id'    => $this->safeAccountId,
                    'lines' => [[
                        'description'         => 'Site visit — '.$ctx['project']->name_en,
                        'expense_account_id'  => $this->expenseAccounts['Site Visit & Travel Expense'],
                        'subtotal'             => random_int(8000, 15000),
                    ]],
                    'allocations' => [[
                        'line_index'         => 0,
                        'project_id'          => $ctx['project']->id,
                        'allocated_amount'    => 8000,
                    ]],
                ], $this->actor);
                $this->successCount++;
            }

            // Quarterly business development / marketing spend.
            if ($m % 3 === 0) {
                $this->cashExpService->record($this->companyId, [
                    'payee_description' => 'Marketing & tender bid preparation',
                    'payment_date'       => (clone $monthStart)->addDays(18)->toDateString(),
                    'money_type'         => 'outgoing_transfer',
                    'currency_id'        => $this->egpId,
                    'source_type'        => 'bank_account',
                    'source_bank_account_id' => $bank->id,
                    'lines' => [[
                        'description'         => 'Business development & marketing — Q covering '.$monthStart->format('F Y'),
                        'expense_account_id'  => $this->expenseAccounts['Business Development & Marketing'],
                        'subtotal'             => random_int(60000, 120000),
                    ]],
                ], $this->actor);
                $this->successCount++;
            }

            $this->line('    Monthly rent/utilities/telecom/site-visit posted.');
        } catch (\Throwable $e) {
            $this->recordFailure('Monthly expenses month '.($m + 1), $e);
        }
    }

    // ── Accruals + prepaid insurance (renewed every January) ──────

    private function monthlyAccrualsAndPrepaid(Carbon $monthStart, int $m): void
    {
        if (in_array($m, [0, 12, 24], true)) {
            try {
                $supplier = $this->suppliers[5];
                $bill = $this->sbService->create($this->companyId, [
                    'bill_type'      => 'prepaid',
                    'partner_id'      => $supplier->id,
                    'supplier_invoice_number' => 'INS-'.$monthStart->format('Y').'-001',
                    'supplier_invoice_date'   => $monthStart->toDateString(),
                    'bill_date'                => $monthStart->toDateString(),
                    'currency_id'              => $this->egpId,
                    'payment_term_id'          => $this->net30Id,
                    'lines' => [[
                        'chart_of_account_id' => $this->expenseAccounts['Insurance Expense'],
                        'description'          => 'Annual professional indemnity & office insurance — '.$monthStart->format('Y'),
                        'unit_price'            => 90000,
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

        if ($m % 2 === 1) {
            try {
                $supplier = $this->suppliers[5];
                $this->accrualService->create($this->companyId, [
                    'partner_id'          => $supplier->id,
                    'chart_of_account_id' => $this->expenseAccounts['Professional Fees — Audit & Legal'],
                    'description'          => 'Accrued audit & legal fees — '.$monthStart->format('F Y'),
                    'accrual_date'         => (clone $monthStart)->endOfMonth()->toDateString(),
                    'amount'               => random_int(15000, 30000),
                ], $this->actor);
                $this->successCount++;
            } catch (\Throwable $e) {
                $this->recordFailure('Accrual month '.($m + 1), $e);
            }
        }

        if ($m % 6 === 3) {
            try {
                $this->accrualService->create($this->companyId, [
                    'partner_id'          => $this->suppliers[5]->id,
                    'chart_of_account_id' => $this->expenseAccounts['Staff Training & Certification'],
                    'description'          => 'Accrued staff certification & training costs — '.$monthStart->format('F Y'),
                    'accrual_date'         => (clone $monthStart)->endOfMonth()->toDateString(),
                    'amount'               => random_int(20000, 45000),
                ], $this->actor);
                $this->successCount++;
            } catch (\Throwable $e) {
                $this->recordFailure('Training accrual month '.($m + 1), $e);
            }
        }
    }

    // ── Payroll — a real Payroll Run every single month ───────────

    private function monthlyPayroll(Carbon $monthStart, int $m): void
    {
        try {
            $runDate = (clone $monthStart)->endOfMonth()->toDateString();
            $run = $this->payrollRunService->create($this->companyId, $monthStart->year, $monthStart->month, $runDate, $this->actor);

            foreach ($run->lines as $line) {
                $gross = (float) $line->gross_pay;
                // DEMO ONLY: flat 10% stands in for the salaries tax, which is entered by hand in the system (no bracket engine). Real tax is progressive.
                $tax = round($gross * 0.10, 2);
                $siWageBase = min($gross, 12000);
                $siEmployee = round($siWageBase * 0.11, 2);
                $siEmployer = round($siWageBase * 0.1875, 2);
                $this->payrollRunService->setManualAmounts($line, (string) $tax, (string) $siEmployee, (string) $siEmployer, '0.00', $this->actor);
            }

            $this->payrollRunService->post($run->fresh(), $this->actor);
            $this->successCount++;
            $this->line('    Payroll Run posted for '.$monthStart->format('F Y').' — '.count($run->lines).' employees.');
        } catch (\Throwable $e) {
            $this->recordFailure('Payroll run '.$monthStart->format('M Y'), $e);
        }
    }

    private function annualSalaryRevision(Carbon $monthStart): void
    {
        foreach ($this->payrollProfiles as $profile) {
            try {
                $newSalary = round((float) $profile->base_salary * 1.10, 2);
                $this->payrollProfileService->addSalaryRevision($profile, [
                    'base_salary'     => $newSalary,
                    'effective_date'  => $monthStart->toDateString(),
                    'note'            => 'Annual salary review — '.$monthStart->format('Y'),
                ], $this->actor);
                $this->successCount++;
            } catch (\Throwable $e) {
                $this->recordFailure('Salary revision — '.$profile->partner?->name_en, $e);
            }
        }
        $this->line('    Annual 10% salary raise applied to all '.count($this->payrollProfiles).' employees.');
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

    /** @return array{model: Product, name: string} */
    private function serviceByName(string $name): array
    {
        foreach ($this->services as $svc) {
            if ($svc['name'] === $name) {
                return $svc;
            }
        }
        return $this->services[0];
    }

    private function recordFailure(string $context, \Throwable $e): void
    {
        $message = "{$context}: {$e->getMessage()}";
        $this->failures[] = $message;
        $this->warn('    ⚠ '.$message);
    }
}
