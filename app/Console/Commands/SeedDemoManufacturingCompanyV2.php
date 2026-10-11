<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  SeedDemoManufacturingCompanyV2
//
//  A much larger, longer-running sibling of SeedDemoManufacturingCompany
//  — same "Delta Industries Co." story, same proven BOM → WIP →
//  Finished Goods production engine, standard costing, and treasury
//  patterns — rebuilt around Mahmoud's brief:
//
//    - 32 months of history: 1 Jan 2024 → 31 Aug 2026.
//    - Revenue sized at roughly 350M EGP in 2024, 450M EGP in 2025,
//      and an annualized ~500M EGP/year rate through Jan–Aug 2026
//      (≈333M EGP for those 8 months) — driven by a real monthly
//      Production → Sales cycle across 6 finished goods, not lump
//      contracts, so quantities/prices are value-sized every month
//      to land on those targets.
//    - Heavy Letter of Guarantee usage: two LG Agreements (CIB,
//      NBE), each covering Bid Bond / Advance Payment LG /
//      Performance LG / Customs Guarantee LG — the set a real
//      manufacturer actually uses (tenders, customer deposits,
//      delivery guarantees, and customs guarantees on imported raw
//      materials), issued against Sales Orders since a
//      manufacturing company doesn't run Projects.
//    - Heavier Overdraft utilization: Clean OD at both CIB and NBE
//      drawn on and repaid every month, plus an OD Against Sales
//      Order (Contract Assignment OD) pledging large customer
//      orders periodically.
//    - Letters of Credit: a third bank (AAIB) finances raw-material
//      imports roughly every 4 months, each with a real issue → pay
//      supplier → (bank-financed ones) settle-the-bank-debt
//      lifecycle, plus a Customs Guarantee LG on the imported goods.
//    - Fixed assets get real emphasis: an initial capex round in
//      Jan 2024, a second expansion round in Feb 2025, and a third
//      in Feb 2026 — CNC machines, a hydraulic press, a conveyor
//      line, trucks, forklifts, a generator, lab equipment, and a
//      warehouse building improvement — all depreciated monthly
//      from the month each goes into service.
//    - HR / Payroll: 13 employee payroll profiles across
//      Manufacturing / Sales / Marketing / G&A, with a real Payroll
//      Run posted every month (manufacturing salaries clear through
//      Direct Labor Clearing, same as the standard-costing engine
//      the production orders already post to) plus two annual
//      raises.
//
//  USAGE:
//    php artisan demo:seed-manufacturing-company-v2
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

use App\Models\BillOfMaterial;
use App\Models\ChartOfAccount;
use App\Models\Company;
use App\Models\Currency;
use App\Models\CustomerInvoice;
use App\Models\EmployeePayrollProfile;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityLgType;
use App\Models\FiscalYear;
use App\Models\InventoryLocation;
use App\Models\OverheadPool;
use App\Models\Process;
use App\Models\Machine;
use App\Models\LaborCategory;
use App\Models\Partner;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\SalesOrder;
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
use App\Services\Accounting\EmployeePayrollProfileService;
use App\Services\Accounting\FixedAssetService;
use App\Services\Accounting\GoodsReceiptNoteService;
use App\Services\Accounting\OtherCollectionService;
use App\Services\Accounting\OtherPaymentService;
use App\Services\Accounting\OverheadPoolService;
use App\Services\Accounting\PayrollRunService;
use App\Services\Accounting\ProductionOrderService;
use App\Services\Accounting\PurchaseOrderService;
use App\Services\Accounting\StandardRateService;
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
use App\Services\Projects\SalesOrderService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SeedDemoManufacturingCompanyV2 extends Command
{
    use \App\Console\Concerns\RefusesInProduction; // Audit H8

    protected $signature = 'demo:seed-manufacturing-company-v2';

    protected $description = 'Creates a LARGE demo Manufacturing company (Growth tier): 32 months (Jan 2024 - Aug 2026), revenue sized to ~350M/450M/500M(annualized) EGP, real BOM-driven production, heavy LG/OD/LC banking activity, three fixed-asset capex rounds, and a full monthly Payroll cycle.';

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
    /** @var CreditFacility[] — [0]=CIB Clean OD, [1]=NBE Clean OD, [2]=Contract Assignment OD (CIB) */
    private array $facilities = [];
    /** @var CreditFacility[] — [0]=CIB LG Agreement, [1]=NBE LG Agreement */
    private array $lgAgreements = [];
    /** @var array<int, array<string, int>> facility_lg_type ids keyed [agreementIndex][lgTypeName] */
    private array $lgTypeIds = [];
    /** @var ?CreditFacility — AAIB Import LC Agreement */
    private ?CreditFacility $lcAgreement = null;
    /** @var array<string, int> */
    private array $expenseAccounts = [];
    private ?OverheadPool $overheadPool = null;
    // Routing master data (VeroCFO_Manufacturing_Costing_Redesign_v1_0.md,
    // Section 5) — one of each is enough for this demo company: every
    // finished good's single Operation shares the same Process/Machine/
    // Labor Category, only the hours differ per product (same shape the
    // old flat BOM fields had, just moved onto a real Operation now).
    private ?Process $demoProcess = null;
    private ?Machine $demoMachine = null;
    private ?LaborCategory $demoLaborCategory = null;
    /** @var EmployeePayrollProfile[] */
    private array $payrollProfiles = [];

    /** @var string[] */
    private array $failures = [];
    private int $successCount = 0;

    /** @var SupplierBill[] */
    private array $openSupplierBills = [];
    /** @var CustomerInvoice[] */
    private array $openCustomerInvoices = [];
    /** @var array<int, array{lc: \App\Models\FinancialInstitutions\LetterOfCreditIssuance, bill: SupplierBill}> */
    private array $openTradeFinanceLcs = [];
    /** @var array<int, array{month: int, lgIssuance: \App\Models\FinancialInstitutions\LgIssuance}> pending Performance LG liquidations */
    private array $pendingPerformanceLg = [];
    /** @var array<int, \App\Models\FinancialInstitutions\LgIssuance> pending Customs Guarantee LG liquidations, keyed by trigger month */
    private array $pendingCustomsLg = [];
    /** @var array<int, \App\Models\FinancialInstitutions\LgIssuance> issued Bid Bonds keyed by trigger month for cancellation */
    private array $pendingBidBondCancel = [];

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
        $this->bomService              = $bomService;
        $this->overheadPoolService     = $overheadPoolService;
        $this->standardRateService     = $standardRateService;
        $this->processService          = $processService;
        $this->machineService          = $machineService;
        $this->laborCategoryService    = $laborCategoryService;
        $this->productionOrderService  = $productionOrderService;
        $this->fixedAssetService       = $fixedAssetService;
        $this->payrollProfileService   = $payrollProfileService;
        $this->payrollRunService       = $payrollRunService;

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

        $this->info('── Step  1/10 — Creating the company ─────────────────');
        $this->createCompany();

        $this->info('── Step  2/10 — Raw materials & finished goods catalogue ─');
        $this->createCatalogue();

        $this->info('── Step  3/10 — Overhead pool, Routing master data & standard costing rates ─');
        $this->createStandardCosting();

        $this->info('── Step  4/10 — Bill of Materials ─────────────────────');
        $this->createBillsOfMaterial();

        $this->info('── Step  5/10 — Partners ──────────────────────────────');
        $this->createPartners();

        $this->info('── Step  6/10 — Treasury (banks, safe, OD + LG + LC facilities) ─');
        $this->createTreasury();

        $this->info('── Step  7/10 — Custom expense accounts ───────────────');
        $this->createExpenseAccounts();

        $this->info('── Step  8/10 — Fixed assets — capex round 1 ──────────');
        $this->buyFixedAssetsRound(1, '2024-01-05', $this->capexRound1());

        $this->info('── Step  9/10 — HR: payroll profiles for 13 employees ─');
        $this->setupPayrollProfiles();

        $this->info('── Step 10/10 — Opening stock, first production run, 32 months ─');
        $this->seedInitialRawMaterialStock();
        $this->runInitialProductionBatch();
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
        $name = 'Delta Industries Co. (Enterprise)';
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

        $fy = FiscalYear::where('company_id', $this->companyId)->first();
        $this->fiscalYearId = $fy->id;

        $this->line("  Company created: {$this->company->name_en} (id={$this->companyId})");
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 2 — Catalogue: bulk industrial raw materials + big-
    //  ticket finished goods. Prices reflect a realistic 2024-2026
    //  Egyptian market (post-devaluation EGP), not the earlier
    //  small demo.
    // ══════════════════════════════════════════════════════════════

    private function createCatalogue(): void
    {
        $unitId = (int) UnitOfMeasure::withoutGlobalScopes()->whereNull('company_id')->where('code', 'UNIT')->value('id');

        $rawCategory = $this->categoryService->create($this->companyId, ['name_en' => 'Raw Materials'], $this->actor);
        $finishedCategory = $this->categoryService->create($this->companyId, ['name_en' => 'Finished Products'], $this->actor);

        $rawMaterialDefs = [
            ['Heavy Steel Plate',                    3500],
            ['Aluminum Billet',                       4200],
            ['Industrial Electronic Control Board',   8500],
            ['Engineering Plastic Resin (25kg batch)', 2800],
            ['Heavy Copper Wire Coil',                 5600],
            ['Precision Fastener & Hardware Kit',      1200],
            ['Hydraulic Cylinder Component',          12000],
            ['Industrial Bearing Set',                 3000],
        ];
        foreach ($rawMaterialDefs as [$name, $cost]) {
            $product = $this->productService->create($this->companyId, [
                'use_auto_code'   => true,
                'name_en'         => $name,
                'category_id'     => $rawCategory->id,
                'usage_types'     => [Product::TYPE_RAW_MATERIAL],
                'primary_uom_id'  => $unitId,
                'reorder_level'   => 100,
                'reorder_qty'     => 500,
            ], $this->actor);

            $this->rawMaterials[] = ['model' => $product, 'cost' => (float) $cost];
        }

        $finishedGoodNames = [
            'Industrial Cooling System Unit',
            'Heavy-Duty Control Panel Cabinet',
            'Steel Structural Frame Assembly',
            'Electric Motor Drive Unit',
            'Hydraulic Press Component Set',
            'Industrial Conveyor Module',
        ];
        foreach ($finishedGoodNames as $name) {
            $product = $this->productService->create($this->companyId, [
                'use_auto_code'   => true,
                'name_en'         => $name,
                'category_id'     => $finishedCategory->id,
                'usage_types'     => [Product::TYPE_SALEABLE],
                'nature'          => Product::NATURE_MANUFACTURED,
                'primary_uom_id'  => $unitId,
                'reorder_level'   => 15,
                'reorder_qty'     => 60,
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
        // [rawIndex, qtyPerUnit] pairs. output_quantity is 1 on every
        // BOM, so quantity_per_batch is already "per one finished unit".
        $recipes = [
            0 => [[0, 4], [4, 3], [5, 10]],   // Cooling System Unit: steel, copper, fasteners
            1 => [[2, 3], [3, 2], [5, 8]],    // Control Panel Cabinet: control boards, resin, fasteners
            2 => [[0, 10], [5, 15]],          // Structural Frame: steel, fasteners
            3 => [[1, 5], [4, 4], [7, 2]],    // Motor Drive Unit: aluminum, copper, bearings
            4 => [[6, 3], [0, 6], [7, 4]],    // Hydraulic Press Set: hydraulic cylinders, steel, bearings
            5 => [[0, 8], [7, 6], [5, 12]],   // Conveyor Module: steel, bearings, fasteners
        ];
        $laborHours   = [1.8, 2.2, 1.2, 2.0, 2.6, 1.6];
        $machineHours = [1.2, 1.5, 0.9, 1.4, 1.8, 1.1];

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
            // createStandardCosting() (now run first, Step 3), only
            // the hours differ per product. This preserves the exact
            // same labor/machine hour figures the old flat fields
            // used to carry — just moved onto a real Operation.
            $operations = [[
                'process_id'               => $this->demoProcess->id,
                'default_machine_id'       => $this->demoMachine->id,
                'labor_category_id'        => $this->demoLaborCategory->id,
                'standard_labor_hours'     => $laborHours[$fgIndex],
                'standard_machine_hours'   => $machineHours[$fgIndex],
            ]];

            $bom = $this->bomService->create($this->companyId, [
                'product_id'      => $fg['model']->id,
                'output_quantity' => 1,
                'effective_date'  => '2024-01-01',
                'lines'           => $lines,
                'operations'      => $operations,
            ], $this->actor);

            // Bug fix: create() always starts a BOM as Draft (a
            // deliberate safety feature — see BillOfMaterialService
            // header). Production Orders only ever resolve to an
            // Active version (resolveEffective()), so a demo BOM that
            // was created but never activated is invisible to every
            // Production Order the rest of this script tries to run —
            // this was silently failing all 32 months of production
            // before this fix.
            $this->bomService->activate($this->companyId, $bom, $this->actor);

            $fg['bom'] = $bom;
            // Labor+overhead allowance at the 2024 standard rates, then
            // a margin on top, so invoices look realistic from month 1.
            $laborCost    = $laborHours[$fgIndex] * 65;
            $overheadCost = $machineHours[$fgIndex] * 45;
            $fg['price'] = round(($materialCost + $laborCost + $overheadCost) * 2.7, -2);
            $this->line('    '.$fg['model']->name_en.' — BOM set, target price ~'.number_format($fg['price']).' EGP.');
        }
        unset($fg);

        $this->line('  '.count($this->finishedGoods).' Bills of Material created.');
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 3 — Overhead pool, Routing master data (Process/Machine/
    //  Labor Category — VeroCFO_Manufacturing_Costing_Redesign_v1_0.md,
    //  Section 4/5), and standard labor/overhead rates. Moved ahead of
    //  Bill of Materials (previously Step 3, now Step 4) because a
    //  BOM's Routing Operations need an existing Machine, and that
    //  Machine needs to already be linked to the Overhead Pool created
    //  here. Rates step up with each new fiscal year (see
    //  runTransactions) to reflect wage/cost inflation over the period.
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

        $this->standardRateService->setLaborCategoryRate($this->companyId, $this->fiscalYearId, $this->demoLaborCategory->id, '65.00', $this->actor, 'Machine Operator — 2024');
        $this->standardRateService->setOverheadPoolRate($this->companyId, $this->fiscalYearId, $this->overheadPool->id, '45.00', $this->actor, 'Factory overhead — 2024');

        $this->line('  Overhead pool, Process, Machine & Labor Category created; labor rate 65.00 EGP/hr, overhead rate 45.00 EGP/machine-hr.');
    }

    // ══════════════════════════════════════════════════════════════
    //  STEP 5 — Partners
    // ══════════════════════════════════════════════════════════════

    private function createPartners(): void
    {
        $customerNames = [
            'Cairo Industrial Supplies', 'Delta Engineering Works', 'Alexandria Manufacturing Group',
            'Nile Valley Contractors', 'Upper Egypt Construction Co.', 'Suez Steel Fabrication Co.',
            'New Capital Infrastructure Projects', 'Red Sea Industrial Zone Authority', 'Nile Delta Agro-Industries',
        ];
        foreach ($customerNames as $name) {
            $this->customers[] = $this->partnerService->create($this->companyId, [
                'name_en'             => $name,
                'types'                => ['customer'],
                'credit_limit'         => 60000000,
                'collection_term_id'   => $this->net30Id,
                'country'              => 'Egypt',
            ], $this->actor);
        }

        $supplierNames = [
            'Egyptian Steel & Metals Co.', 'Nile Plastics & Polymers', 'Cairo Electronics Components',
            'Suez Import Trading — Raw Materials', 'Alexandria Heavy Machinery Suppliers',
            'Delta Hydraulics & Precision Parts', 'Ahmed Fathy & Co. — Audit & Legal Services',
        ];
        foreach ($supplierNames as $name) {
            $this->suppliers[] = $this->partnerService->create($this->companyId, [
                'name_en'          => $name,
                'types'             => ['supplier'],
                'payment_term_id'   => $this->net30Id,
                'country'           => 'Egypt',
            ], $this->actor);
        }

        $employeeDefs = [
            ['Hassan Ali',        'Production Manager',                'Production',  60000, EmployeePayrollProfile::CATEGORY_MANUFACTURING],
            ['Mostafa Kamal',     'Quality Control Supervisor',        'Production',  38000, EmployeePayrollProfile::CATEGORY_MANUFACTURING],
            ['Ibrahim Nasser',    'Shift Supervisor',                  'Production',  32000, EmployeePayrollProfile::CATEGORY_MANUFACTURING],
            ['Tarek Aboul-Fotouh','Machine Operator',                  'Production',  22000, EmployeePayrollProfile::CATEGORY_MANUFACTURING],
            ['Sameh Reda',        'Maintenance Engineer',              'Production',  30000, EmployeePayrollProfile::CATEGORY_MANUFACTURING],
            ['Nourhan Samir',     'Finance Manager',                   'Finance',     55000, EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN],
            ['Dina Salah',        'Senior Accountant',                 'Finance',     32000, EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN],
            ['Rania Adel',        'Procurement Officer',               'Procurement', 28000, EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN],
            ['Heba Younis',       'HR & Admin Officer',                'HR & Admin',  26000, EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN],
            ['Amr Farouk',        'Warehouse & Logistics Supervisor',  'Logistics',   24000, EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN],
            ['Khaled Mansour',    'Business Development Manager',      'Sales',       45000, EmployeePayrollProfile::CATEGORY_SALES],
            ['Yara Ismail',       'Sales Account Manager',             'Sales',       32000, EmployeePayrollProfile::CATEGORY_SALES],
            ['Salma Wagdy',       'Marketing & Trade Show Coordinator','Marketing',   26000, EmployeePayrollProfile::CATEGORY_MARKETING],
        ];
        foreach ($employeeDefs as [$name, $title, $dept, $salary, $category]) {
            $partner = $this->partnerService->create($this->companyId, [
                'name_en'     => $name,
                'types'        => ['employee'],
                'job_title'    => $title,
                'department'   => $dept,
            ], $this->actor);
            $partner->seedBaseSalary = $salary;
            $partner->seedCategory   = $category;
            $this->employees[] = $partner;
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
    //  STEP 6 — Treasury: 3 Banks, Clean OD x2, Contract Assignment
    //  OD (against Sales Orders), 2 LG Agreements, and an Import LC
    //  Agreement. Every LG/LC fix learned on the System Integrator
    //  build is applied here from the start: the LC facility gets
    //  its coa_account_id set explicitly, and AAIB is never used to
    //  actually move cash (fees/settlements route through CIB, which
    //  is properly funded) so it never goes negative.
    // ══════════════════════════════════════════════════════════════

    private function createTreasury(): void
    {
        $banksParentId = $this->coaByCode('132');
        $cashParentId  = $this->coaByCode('131');
        $od223001Id    = $this->coaByCode('223001'); // Clean Overdraft
        $od223003Id    = $this->coaByCode('223003'); // OD Against Contract/SO

        $safeAccount = $this->coaService->create($this->companyId, [
            'parent_id'      => $cashParentId,
            'use_auto_code'  => true,
            'name_en'        => 'Main Safe',
        ], $this->actor);
        $this->safeAccountId = $safeAccount->id;

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
                'account_number'  => '400'.random_int(1000000, 9999999),
                'account_type'    => 'current',
                'currency_id'     => $this->egpId,
                'coa_account_id'  => $coaLeaf->id,
                'is_active'       => true,
            ], $this->actor);

            $this->banks[] = ['bank' => $bank, 'account' => $account];
        }

        // ── Clean Overdraft — CIB and NBE ────────────────────────
        $odLimits = [40000000, 30000000];
        foreach ([0, 1] as $i) {
            try {
                $facility = $this->odService->create($this->companyId, [
                    'bank_id'               => $this->banks[$i]['bank']->id,
                    'start_date'            => '2024-01-01',
                    'review_date'           => '2027-06-01',
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
                    'purpose'               => 'Working capital / raw material financing',
                ], $this->actor);
                $facility->update(['bank_account_id' => $this->banks[$i]['account']->id]);
                $this->facilities[] = $facility;
                $this->successCount++;
                $this->line('  Clean Overdraft created at '.$this->banks[$i]['bank']->name_en.' (limit '.number_format($odLimits[$i]).' EGP).');
            } catch (\Throwable $e) {
                $this->recordFailure('Clean OD facility '.$i, $e);
            }
        }

        // ── Contract Assignment OD — large Sales Orders pledged
        //    as collateral (manufacturing's version of "OD against
        //    a contract" — here it's against a big customer LPO). ──
        try {
            $facility = $this->contractOdService->create($this->companyId, $this->banks[0]['bank'], [
                'bank_id'               => $this->banks[0]['bank']->id,
                'start_date'            => '2024-01-01',
                'review_date'           => '2027-06-01',
                'account_number'        => 'CAOD-'.random_int(100000, 999999),
                'currency_id'           => $this->egpId,
                'coa_account_id'        => $od223003Id,
                'facility_limit'        => 55000000,
                'outstanding_balance'   => 0,
                'balance_date'          => '2024-01-01',
                'borrowing_rate'        => 17,
                'bank_margin_rate'      => 2.5,
                'min_interest_rate'     => 1,
                'hdb_rate'              => 0.5,
                'admin_fee_rate'        => 0.25,
                'settled_max_days'      => 90,
                'max_lending_limit_per_contract' => 25000000,
                'follows_corridor_rate' => false,
                'purpose'               => 'Financing against large customer purchase orders',
            ], $this->actor);
            $facility->update(['bank_account_id' => $this->banks[0]['account']->id]);
            $this->facilities[2] = $facility;
            $this->successCount++;
            $this->line('  Contract Assignment Overdraft facility created (limit 55,000,000 EGP, max 25,000,000 EGP per order).');
        } catch (\Throwable $e) {
            $this->recordFailure('Contract Assignment OD facility creation', $e);
        }

        // ── LG Agreements — CIB and NBE, each with the 4 LG types
        //    a manufacturer actually uses: Bid Bond (tenders),
        //    Advance Payment LG (customer deposits), Performance LG
        //    (delivery guarantees), Customs Guarantee LG (imported
        //    raw materials clearing customs). ──────────────────────
        $lgDefs = [
            [
                'bankIdx' => 0, 'limit' => 100000000,
                'types' => [
                    ['lg_type' => 'Bid Bond',              'cash_cover_pct' => 0,  'commission_rate_pct' => 0.50, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 2000, 'issuance_fee_amount' => 1000],
                    ['lg_type' => 'Advance Payment LG',    'cash_cover_pct' => 10, 'commission_rate_pct' => 1.25, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 5000, 'issuance_fee_amount' => 2000],
                    ['lg_type' => 'Performance LG',        'cash_cover_pct' => 10, 'commission_rate_pct' => 1.00, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 5000, 'issuance_fee_amount' => 2000],
                    ['lg_type' => 'Customs Guarantee LG',  'cash_cover_pct' => 5,  'commission_rate_pct' => 0.75, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 3000, 'issuance_fee_amount' => 1500],
                ],
            ],
            [
                'bankIdx' => 1, 'limit' => 70000000,
                'types' => [
                    ['lg_type' => 'Bid Bond',              'cash_cover_pct' => 0,  'commission_rate_pct' => 0.60, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 2500, 'issuance_fee_amount' => 1200],
                    ['lg_type' => 'Advance Payment LG',    'cash_cover_pct' => 15, 'commission_rate_pct' => 1.35, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 6000, 'issuance_fee_amount' => 2200],
                    ['lg_type' => 'Performance LG',        'cash_cover_pct' => 12, 'commission_rate_pct' => 1.10, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 6000, 'issuance_fee_amount' => 2200],
                    ['lg_type' => 'Customs Guarantee LG',  'cash_cover_pct' => 5,  'commission_rate_pct' => 0.80, 'commission_interval' => 'quarterly', 'min_commission_fee_amount' => 3500, 'issuance_fee_amount' => 1700],
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
                    'review_date'    => '2027-06-01',
                    'purpose'        => 'Bid Bond / Advance Payment / Performance / Customs Guarantee LGs',
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

        // ── Import LC Agreement at AAIB — coa_account_id set
        //    explicitly (the agreement-level service always leaves
        //    it null, by design — this is the "issuance layer"
        //    concern, same as LG). ────────────────────────────────
        try {
            $lcFacility = $this->lcAgreementService->create($this->companyId, [
                'bank_id'          => $this->banks[2]['bank']->id,
                'name'             => 'AAIB Import LC Agreement',
                'currency_id'      => $this->egpId,
                'start_date'       => '2024-01-01',
                'review_date'      => '2027-06-01',
                'lc_security_type' => 'unsecured',
                'facility_limit'   => 120000000,
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
            $this->line('  Import LC Agreement created at AAIB (limit 120,000,000 EGP).');
        } catch (\Throwable $e) {
            $this->recordFailure('Import LC Agreement', $e);
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
            ['Customs Clearance & Freight',         $gaParentId],
            ['Staff Training & Certification',      $gaParentId],
            ['Bank Guarantee & LG Charges',          $gaParentId],
            ['Outbound Freight',                    $salesParentId],
            ['Sales Commissions',                   $salesParentId],
            ['Trade Show & Marketing',               $mktParentId],
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
    //  STEP 8 — Fixed assets: THREE capex rounds, not one, to give
    //  fixed assets real emphasis across the whole period.
    // ══════════════════════════════════════════════════════════════

    /** @var array<int, array{asset: \App\Models\FixedAsset, from: Carbon}> */
    private array $fixedAssets = [];

    private function capexRound1(): array
    {
        return [
            ['name' => 'CNC Production Machine #1',        'account_code' => '111003', 'cost' => 850000,  'life_months' => 96,  'residual' => 50000],
            ['name' => 'CNC Production Machine #2',        'account_code' => '111003', 'cost' => 900000,  'life_months' => 96,  'residual' => 55000],
            ['name' => 'Industrial Hydraulic Press',        'account_code' => '111003', 'cost' => 1200000, 'life_months' => 120, 'residual' => 80000],
            ['name' => 'Assembly Line Conveyor System',     'account_code' => '111003', 'cost' => 650000,  'life_months' => 84,  'residual' => 40000],
            ['name' => 'Forklift #1',                       'account_code' => '111003', 'cost' => 280000,  'life_months' => 60,  'residual' => 20000],
            ['name' => 'Industrial Backup Generator',       'account_code' => '111003', 'cost' => 450000,  'life_months' => 96,  'residual' => 30000],
            ['name' => 'Factory Delivery Truck #1',         'account_code' => '111004', 'cost' => 620000,  'life_months' => 72,  'residual' => 40000],
            ['name' => 'Factory Delivery Truck #2',         'account_code' => '111004', 'cost' => 640000,  'life_months' => 72,  'residual' => 40000],
            ['name' => 'Quality Testing Lab Equipment',     'account_code' => '111006', 'cost' => 380000,  'life_months' => 60,  'residual' => 25000],
            ['name' => 'Office & QC Computers',             'account_code' => '111006', 'cost' => 120000,  'life_months' => 36,  'residual' => 8000],
        ];
    }

    private function capexRound2(): array
    {
        return [
            ['name' => 'CNC Production Machine #3',         'account_code' => '111003', 'cost' => 950000,  'life_months' => 96, 'residual' => 55000],
            ['name' => 'Warehouse Building Improvement',     'account_code' => '111002', 'cost' => 1800000, 'life_months' => 180, 'residual' => 0],
            ['name' => 'Forklift #2',                        'account_code' => '111003', 'cost' => 300000,  'life_months' => 60, 'residual' => 20000],
        ];
    }

    private function capexRound3(): array
    {
        return [
            ['name' => 'CNC Production Machine #4',          'account_code' => '111003', 'cost' => 1000000, 'life_months' => 96, 'residual' => 60000],
            ['name' => 'Factory Delivery Truck #3',           'account_code' => '111004', 'cost' => 680000,  'life_months' => 72, 'residual' => 42000],
            ['name' => 'Automated Packaging Line',            'account_code' => '111003', 'cost' => 750000,  'life_months' => 84, 'residual' => 45000],
        ];
    }

    private function buyFixedAssetsRound(int $roundNumber, string $issuanceDate, array $assetDefs): void
    {
        $supplier = $this->suppliers[4]; // Alexandria Heavy Machinery Suppliers
        $receivedDate = Carbon::parse($issuanceDate)->addDays(5)->toDateString();

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
                    'issuance_date'   => $issuanceDate,
                    'start_date'      => $issuanceDate,
                    'end_date'        => Carbon::parse($issuanceDate)->addDays(30)->toDateString(),
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
                    'received_date'           => $receivedDate,
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
                    'supplier_invoice_number'  => 'FA-R'.$roundNumber.'-'.strtoupper(substr(md5($def['name']), 0, 8)),
                    'supplier_invoice_date'    => $receivedDate,
                    'bill_date'                => $receivedDate,
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
    //  STEP 9 — HR: one payroll profile per employee
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
    //  Opening raw material stock (2024-01-01) — value-sized per
    //  line, not a flat unit count: raw material costs here span
    //  1,200 to 12,000 EGP (a 10x range), so a flat quantity would
    //  wildly over- or under-shoot depending which items get picked.
    // ══════════════════════════════════════════════════════════════

    private function seedInitialRawMaterialStock(): void
    {
        try {
            $chunks = array_chunk($this->rawMaterials, 2);
            foreach ($chunks as $i => $chunk) {
                $supplier = $this->suppliers[$i % 4];

                $lines = [];
                foreach ($chunk as $rm) {
                    // Heavy Steel Plate (index 0) and Precision Fastener &
                    // Hardware Kit (index 5) each feed 4 of the 6 BOMs —
                    // fasteners at even higher per-unit quantities than
                    // steel (8-15 vs 4-10) — so both get a much bigger
                    // opening slug than the other 6 raw materials.
                    $target = match ($rm['model']->id) {
                        $this->rawMaterials[0]['model']->id => 12000000,
                        $this->rawMaterials[5]['model']->id => 8000000,
                        default => 3500000,
                    };
                    $qty = max(50, (int) round($target / $rm['cost']));
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
                    'subject'         => 'Opening raw material stock',
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

            $this->line('  Opening raw material stock posted, dated 2024-01-01.');
        } catch (\Throwable $e) {
            $this->recordFailure('Initial raw material stock', $e);
        }
    }

    private function runInitialProductionBatch(): void
    {
        foreach ($this->finishedGoods as $fg) {
            $this->produce($fg, 70, '2024-01-08', 'Opening production batch');
        }
    }

    /**
     * Runs one Production Order end to end: create → start → consume
     * every BOM component → labor → overhead → complete.
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
                    $order, $bomLine->component_product_id, $qtyNeeded, $this->actor, $date
                );
                $this->successCount++;
            }

            // Section 5 — read the same figures off the BOM's Routing
            // Operations now, instead of the removed flat fields. This
            // demo BOM has exactly one Operation per product, so this
            // is the same number the old flat fields used to carry.
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

    private float $currentLaborRate = 65.00;
    private float $currentOverheadRate = 45.00;

    // ══════════════════════════════════════════════════════════════
    //  MAIN ENGINE — 32 months
    // ══════════════════════════════════════════════════════════════

    private function runTransactions(): void
    {
        try {
            foreach ([0, 1] as $i) {
                $this->ocService->record($this->companyId, [
                    'partner_id'        => $this->shareholders[0]->id,
                    'partner_type'       => 'shareholder',
                    'collection_date'    => '2024-01-02',
                    'money_type'         => 'bank_deposit',
                    'currency_id'        => $this->egpId,
                    'amount'             => 12500000,
                    'destination_type'             => 'bank_account',
                    'destination_bank_account_id'  => $this->banks[$i]['account']->id,
                    'credit_account_id' => $this->coaByCode('311'),
                ], $this->actor);
                $this->successCount++;
            }
            $this->line('  Shareholder capital injection recorded (12,500,000 EGP into each of CIB & NBE — 25,000,000 total).');
        } catch (\Throwable $e) {
            $this->recordFailure('Shareholder injection', $e);
        }

        for ($m = 0; $m < self::TOTAL_MONTHS; $m++) {
            $monthStart = Carbon::create(self::START_YEAR, self::START_MONTH, 1)->addMonths($m);
            $this->newLine();
            $this->info('  Month '.($m + 1).'/'.self::TOTAL_MONTHS.' — '.$monthStart->format('F Y'));

            if ($m === 12 || $m === 24) {
                try {
                    $fy = $this->fiscalYearService->createNext($this->companyId, $this->actor);
                    $this->fiscalYearId = $fy->id;
                    $this->successCount++;
                    $this->line('    Opened next Fiscal Year: '.$fy->name.' ('.$fy->start_date->toDateString().' to '.$fy->end_date->toDateString().').');

                    // Standard rates step up with the new fiscal year —
                    // wage/cost inflation over a 32-month window.
                    $this->currentLaborRate    = $m === 12 ? 72.00 : 80.00;
                    $this->currentOverheadRate = $m === 12 ? 50.00 : 55.00;
                    $this->standardRateService->setLaborCategoryRate($this->companyId, $this->fiscalYearId, $this->demoLaborCategory->id, (string) $this->currentLaborRate, $this->actor, 'Machine Operator — '.$monthStart->year);
                    $this->standardRateService->setOverheadPoolRate($this->companyId, $this->fiscalYearId, $this->overheadPool->id, (string) $this->currentOverheadRate, $this->actor, 'Factory overhead — '.$monthStart->year);
                    $this->successCount += 2;
                    $this->line('    Standard rates updated: labor '.$this->currentLaborRate.' EGP/hr, overhead '.$this->currentOverheadRate.' EGP/machine-hr.');
                } catch (\Throwable $e) {
                    $this->recordFailure('Opening Fiscal Year / rates at month '.($m + 1), $e);
                }
            }

            // Capex expansion rounds.
            if ($m === 13) {
                $this->buyFixedAssetsRound(2, (clone $monthStart)->addDays(4)->toDateString(), $this->capexRound2());
            }
            if ($m === 25) {
                $this->buyFixedAssetsRound(3, (clone $monthStart)->addDays(4)->toDateString(), $this->capexRound3());
            }

            $this->monthlyPurchaseCycle($monthStart, $m);
            $this->monthlyProductionCycle($monthStart, $m);
            $this->monthlySalesCycle($monthStart, $m);

            if ($m % 3 === 1) {
                $this->issueBidBondForTender($monthStart, $m);
            }
            if ($m % 3 === 0 && $m > 0) {
                $this->issueBigOrderLgs($monthStart, $m);
            }
            foreach ($this->pendingPerformanceLg[$m] ?? [] as $ctx) {
                $this->closeOutPerformanceLg($ctx, $monthStart);
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

    // ── Revenue targets by year — total EGP/month, 6 finished
    //    goods carrying the load between them. ─────────────────────

    private function monthlyRevenueTarget(int $year): float
    {
        return match ($year) {
            2024 => 29000000.0,
            2025 => 37500000.0,
            default => 41700000.0, // 2026 — 500M/year rate, prorated to the 8 months we cover
        };
    }

    private function monthlyProductionUnitsTarget(int $year): int
    {
        return match ($year) {
            2024 => 140,
            2025 => 180,
            default => 200,
        };
    }

    // ── Purchase cycle: restock raw materials, 2 cycles/month,
    //    value-sized per line (see seedInitialRawMaterialStock note) ─

    private function monthlyPurchaseCycle(Carbon $monthStart, int $m): void
    {
        for ($i = 0; $i < 2; $i++) {
            try {
                $supplier = $this->suppliers[($m * 2 + $i) % 4];
                $issuance = (clone $monthStart)->addDays(2 + $i * 5);

                $lines = [];
                // Heavy Steel Plate and Precision Fastener & Hardware Kit
                // each feed 4 of the 6 BOMs and deplete much faster than
                // the other raw materials — every restock cycle guarantees
                // both a dedicated, larger line on top of whichever 3
                // items rotate in below.
                $steel = $this->rawMaterials[0];
                $fasteners = $this->rawMaterials[5];
                $lines[] = [
                    'product_id'   => $steel['model']->id,
                    'description'  => $steel['model']->name_en,
                    'quantity'     => max(200, (int) round(4000000 / $steel['cost'])),
                    'uom_id'       => $steel['model']->primary_uom_id,
                    'unit_price'   => $steel['cost'],
                ];
                $lines[] = [
                    'product_id'   => $fasteners['model']->id,
                    'description'  => $fasteners['model']->name_en,
                    'quantity'     => max(200, (int) round(3000000 / $fasteners['cost'])),
                    'uom_id'       => $fasteners['model']->primary_uom_id,
                    'unit_price'   => $fasteners['cost'],
                ];
                $picked = $this->pickRawMaterials(3, $m * 10 + $i);
                foreach ($picked as $rm) {
                    $qty = max(50, (int) round(1800000 / $rm['cost']));
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
                    'supplier_invoice_number'  => 'INV-'.strtoupper(substr(md5($po->id.$i.$m), 0, 8)),
                    'supplier_invoice_date'    => $receivedDate->toDateString(),
                    'bill_date'                => $receivedDate->toDateString(),
                    'currency_id'              => $this->egpId,
                    'payment_term_id'          => $this->net30Id,
                    'lines'                    => $billLines,
                ], $this->actor);
                $bill = $this->sbService->post($bill, $this->actor);
                $this->successCount++;

                $this->openSupplierBills[] = $bill;
                $this->line("    Raw material restock PO/GRN/Bill posted — {$supplier->name_en} ({$bill->bill_code})");
            } catch (\Throwable $e) {
                $this->recordFailure('Purchase cycle month '.($m + 1)." #{$i}", $e);
            }
        }
    }

    // ── Production cycle: every finished good, every month ────────

    private function monthlyProductionCycle(Carbon $monthStart, int $m): void
    {
        $totalUnits = $this->monthlyProductionUnitsTarget($monthStart->year);
        $perFg = max(10, (int) round($totalUnits / count($this->finishedGoods) * 1.15));

        foreach ($this->finishedGoods as $fg) {
            $this->produce($fg, $perFg, (clone $monthStart)->addDays(8)->toDateString(), 'Month '.($m + 1).' production run');
        }
    }

    // ── Sales cycle: SO → Delivery → Invoice, value-sized to hit
    //    this year's monthly revenue target. ────────────────────────

    private function monthlySalesCycle(Carbon $monthStart, int $m): void
    {
        $target = $this->monthlyRevenueTarget($monthStart->year);
        $soCount = 5;
        $perSo = $target / $soCount;

        for ($i = 0; $i < $soCount; $i++) {
            try {
                $customer = $this->customers[($m * $soCount + $i) % count($this->customers)];
                $issuance = (clone $monthStart)->addDays(10 + $i * 3);
                $soValue = $perSo * (0.85 + (($i * 37 + $m * 13) % 30) / 100); // ±~15% spread, deterministic

                $picked = $this->pickFinishedGoods($i % 2 === 0 ? 1 : 2, $m * 20 + $i);
                $lines = [];
                $share = 1 / count($picked);
                foreach ($picked as $fg) {
                    $lineValue = $soValue * $share;
                    $qty = max(1, (int) round($lineValue / $fg['price']));
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

    // ── Bid Bond LG — issued for a tender, cancelled a month later
    //    once the (simulated) tender outcome is known. ─────────────

    private function issueBidBondForTender(Carbon $monthStart, int $m): void
    {
        try {
            $customer = $this->customers[$m % count($this->customers)];
            $agreementIdx = $m % 2;
            $facility = $this->lgAgreements[$agreementIdx] ?? null;
            $typeId = $this->lgTypeIds[$agreementIdx]['Bid Bond'] ?? null;
            if (! $facility || ! $typeId) {
                return;
            }

            $issuance = $this->lgIssuanceService->create($this->companyId, [
                'issuance_type'       => 'facility',
                'posting_mode'        => 'new_issuance',
                'lg_type'             => 'Bid Bond',
                'transaction_name'    => 'Bid Bond — Supply tender for '.$customer->name_en,
                'bank_id'             => $this->banks[$agreementIdx]['bank']->id,
                'currency_id'         => $this->egpId,
                'credit_facility_id'  => $facility->id,
                'facility_lg_type_id' => $typeId,
                'beneficiary_partner_id' => $customer->id,
                'issuance_date'       => $monthStart->toDateString(),
                'end_date'            => (clone $monthStart)->addDays(45)->toDateString(),
                'lg_amount'           => random_int(500000, 1200000),
            ], $this->actor);

            $this->pendingBidBondCancel[$m + 1][] = $issuance;
            $this->successCount++;
            $this->line('    Bid Bond LG issued for supply tender — '.$customer->name_en.'.');
        } catch (\Throwable $e) {
            $this->recordFailure('Bid Bond issuance month '.($m + 1), $e);
        }
    }

    // ── Big Order LGs — Advance Payment LG (against a customer
    //    deposit on a large order) + Performance LG (delivery
    //    guarantee), tied to a Sales Order since Manufacturing has
    //    no Projects module. ──────────────────────────────────────

    private function issueBigOrderLgs(Carbon $monthStart, int $m): void
    {
        try {
            // Release any Bid Bond scheduled to close this month.
            foreach ($this->pendingBidBondCancel[$m] ?? [] as $bidBond) {
                try {
                    $this->lgIssuanceService->cancel($bidBond, [
                        'cancelled_at' => $monthStart->toDateString(),
                        'notes'        => 'Tender outcome resolved — bid bond released.',
                    ], $this->actor);
                    $this->successCount++;
                } catch (\Throwable $e) {
                    $this->recordFailure('Bid Bond release month '.($m + 1), $e);
                }
            }

            $customer = $this->customers[($m + 2) % count($this->customers)];
            $target = $this->monthlyRevenueTarget($monthStart->year);
            $orderValue = round($target * 0.55, -3); // one big strategic order this month, on top of the regular sales cycle

            $picked = $this->pickFinishedGoods(2, $m * 31);
            $lines = [];
            foreach ($picked as $i => $fg) {
                $lineValue = $orderValue * ($i === 0 ? 0.6 : 0.4);
                $qty = max(1, (int) round($lineValue / $fg['price']));
                $lines[] = [
                    'product_id'          => $fg['model']->id,
                    'description'         => $fg['model']->name_en,
                    'quantity'             => $qty,
                    'uom_id'               => $fg['model']->primary_uom_id,
                    'unit_price'           => $fg['price'],
                    'revenue_account_id'  => $fg['model']->revenue_account_id,
                ];
            }

            $issuance = (clone $monthStart)->addDays(6);
            $so = $this->soService->create($this->companyId, [
                'partner_id'      => $customer->id,
                'subject'         => 'Large strategic supply order',
                'currency_id'     => $this->egpId,
                'exchange_rate'   => 1,
                'issuance_date'   => $issuance->toDateString(),
                'start_date'      => $issuance->toDateString(),
                'end_date'        => (clone $issuance)->addDays(60)->toDateString(),
                'payment_term_id' => $this->net30Id,
                'lines'           => $lines,
            ], $this->actor);
            $this->soService->activate($so, $this->actor);
            $this->successCount++;
            $this->line('    Large strategic order created — '.$customer->name_en.' ('.number_format($orderValue).' EGP).');

            $downPayment = round($orderValue * 0.20, 2);
            $this->cpService->record($this->companyId, [
                'partner_id'        => $customer->id,
                'collection_type'    => 'down_payment',
                'payment_date'       => $issuance->toDateString(),
                'money_type'         => 'bank_deposit',
                'receive_currency_id' => $this->egpId,
                'amount'              => $downPayment,
                'destination_type'             => 'bank_account',
                'destination_bank_account_id'  => $this->banks[0]['account']->id,
            ], $this->actor);
            $this->successCount++;
            $this->line('    Down payment collected: '.number_format($downPayment).' EGP (20% of order value).');

            $agreementIdx = $m % 2;
            try {
                $advTypeId = $this->lgTypeIds[$agreementIdx]['Advance Payment LG'];
                $this->lgIssuanceService->create($this->companyId, [
                    'issuance_type'       => 'facility',
                    'posting_mode'        => 'new_issuance',
                    'lg_type'             => 'Advance Payment LG',
                    'transaction_name'    => 'Advance Payment LG — '.$so->so_code,
                    'bank_id'             => $this->banks[$agreementIdx]['bank']->id,
                    'currency_id'         => $this->egpId,
                    'credit_facility_id'  => $this->lgAgreements[$agreementIdx]->id,
                    'facility_lg_type_id' => $advTypeId,
                    'beneficiary_partner_id' => $customer->id,
                    'purchase_order_so_id'   => $so->id,
                    'issuance_date'       => $issuance->toDateString(),
                    'end_date'            => (clone $issuance)->addDays(90)->toDateString(),
                    'lg_amount'           => $downPayment,
                    'cash_cover_account_id' => $this->banks[$agreementIdx]['account']->id,
                ], $this->actor);
                $this->successCount++;
                $this->line('    Advance Payment LG issued ('.number_format($downPayment).' EGP).');
            } catch (\Throwable $e) {
                $this->recordFailure('Advance Payment LG — '.$so->so_code, $e);
            }

            try {
                $perfTypeId = $this->lgTypeIds[$agreementIdx]['Performance LG'];
                $perfAmount = round($orderValue * 0.10, 2);
                $performanceLg = $this->lgIssuanceService->create($this->companyId, [
                    'issuance_type'       => 'facility',
                    'posting_mode'        => 'new_issuance',
                    'lg_type'             => 'Performance LG',
                    'transaction_name'    => 'Performance LG — '.$so->so_code,
                    'bank_id'             => $this->banks[$agreementIdx]['bank']->id,
                    'currency_id'         => $this->egpId,
                    'credit_facility_id'  => $this->lgAgreements[$agreementIdx]->id,
                    'facility_lg_type_id' => $perfTypeId,
                    'beneficiary_partner_id' => $customer->id,
                    'purchase_order_so_id'   => $so->id,
                    'issuance_date'       => (clone $issuance)->addDays(5)->toDateString(),
                    'end_date'            => (clone $issuance)->addDays(90)->toDateString(),
                    'lg_amount'           => $perfAmount,
                    'cash_cover_account_id' => $this->banks[$agreementIdx]['account']->id,
                ], $this->actor);
                $this->successCount++;
                $this->line('    Performance LG issued ('.number_format($perfAmount).' EGP).');

                $closeMonth = min($m + 2, self::TOTAL_MONTHS - 1);
                $this->pendingPerformanceLg[$closeMonth][] = ['lg' => $performanceLg, 'so' => $so];
            } catch (\Throwable $e) {
                $this->recordFailure('Performance LG — '.$so->so_code, $e);
            }

            // Periodically pledge this large order as Contract
            // Assignment OD collateral too.
            if ($m % 6 === 0) {
                try {
                    $this->contractOdService->addAssignment($this->companyId, $this->facilities[2], [
                        'partner_id'            => $customer->id,
                        'collateral_type'       => 'sales_order',
                        'collateral_so_id'      => $so->id,
                        'assignment_date'       => $issuance->toDateString(),
                        'review_date'           => $issuance->toDateString(),
                        'lending_pct'           => 70,
                    ], $this->actor);
                    $this->successCount++;
                    $this->line("    Order {$so->so_code} pledged as Contract Assignment OD collateral.");
                } catch (\Throwable $e) {
                    $this->recordFailure('Pledge order as collateral month '.($m + 1), $e);
                }
            }
        } catch (\Throwable $e) {
            $this->recordFailure('Big order LGs month '.($m + 1), $e);
        }
    }

    private function closeOutPerformanceLg(array $ctx, Carbon $monthStart): void
    {
        try {
            $this->lgIssuanceService->liquidate($ctx['lg'], [
                'liquidated_at' => $monthStart->toDateString(),
                'claim_amount'  => 0,
                'liquidation_expense_account_id' => $this->expenseAccounts['Bank Guarantee & LG Charges'],
            ], $this->actor);
            $this->successCount++;
            $this->line('    Performance LG liquidated (no claim) — order '.$ctx['so']->so_code.' delivered.');
        } catch (\Throwable $e) {
            $this->recordFailure('Performance LG close-out — '.$ctx['so']->so_code, $e);
        }
    }

    // ── Trade-finance LC — raw material import financing, plus a
    //    Customs Guarantee LG on the imported goods. ───────────────

    private function issueTradeFinanceLc(Carbon $monthStart, int $m): void
    {
        try {
            $supplier = $this->suppliers[3]; // Suez Import Trading — Raw Materials
            $bank = $this->banks[2]; // AAIB
            $financedByBank = ($m % 8 === 0);

            $lines = [];
            $picked = $this->pickRawMaterials(2, $m * 7);
            foreach ($picked as $rm) {
                $qty = max(50, (int) round(5500000 / $rm['cost']));
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
                'subject'         => 'LC-financed raw material import',
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
                'transaction_name' => 'Raw Material Import — '.$monthStart->format('F Y'),
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
            $this->line('    Letter of Credit issued at AAIB — '.number_format($lcAmount).' EGP raw material import ('.($financedByBank ? 'bank-financed' : 'self-financed').').');

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
                    'description'                  => $line->product?->name_en ?? 'Raw material',
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

    private function closeOutCustomsLg($lgIssuance, Carbon $monthStart): void
    {
        try {
            $this->lgIssuanceService->liquidate($lgIssuance, [
                'liquidated_at' => $monthStart->toDateString(),
                'claim_amount'  => 0,
                'liquidation_expense_account_id' => $this->expenseAccounts['Customs Clearance & Freight'],
            ], $this->actor);
            $this->successCount++;
            $this->line('    Customs Guarantee LG liquidated — goods cleared customs.');
        } catch (\Throwable $e) {
            $this->recordFailure('Customs Guarantee LG close-out month '.($monthStart->month), $e);
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
                $amount = round(min($outstanding, random_int(300000, 800000)), 2);
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
    }

    // ── Recurring expenses ─────────────────────────────────────────

    private function monthlyExpenses(Carbon $monthStart, int $m): void
    {
        try {
            $bank = $this->banks[0]['account'];

            // Top up the safe before any safe-based expense.
            $this->imtService->record($this->companyId, [
                'from_leg_type'        => 'bank',
                'from_bank_account_id'  => $bank->id,
                'to_leg_type'            => 'safe',
                'to_safe_account_id'     => $this->safeAccountId,
                'currency_id'            => $this->egpId,
                'amount'                 => 150000,
                'transfer_date'          => (clone $monthStart)->addDays(1)->toDateString(),
                'comment'                => 'Monthly safe top-up',
            ], $this->actor);
            $this->successCount++;

            $this->cashExpService->record($this->companyId, [
                'payee_description' => 'Landlord — monthly factory rent',
                'payment_date'       => (clone $monthStart)->addDays(2)->toDateString(),
                'money_type'         => 'outgoing_transfer',
                'currency_id'        => $this->egpId,
                'source_type'        => 'bank_account',
                'source_bank_account_id' => $bank->id,
                'lines' => [[
                    'description'         => 'Factory rent — '.$monthStart->format('F Y'),
                    'expense_account_id'  => $this->expenseAccounts['Factory Rent'],
                    'subtotal'             => 220000,
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
                    'subtotal'             => random_int(45000, 75000),
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
                    'description'         => 'Internet & phone lines — '.$monthStart->format('F Y'),
                    'expense_account_id'  => $this->expenseAccounts['Telecom & Internet'],
                    'subtotal'             => 14000,
                ]],
            ], $this->actor);
            $this->successCount++;

            $this->cashExpService->record($this->companyId, [
                'payee_description' => 'Factory consumables & supplies',
                'payment_date'       => (clone $monthStart)->addDays(12)->toDateString(),
                'money_type'         => 'cash_payment',
                'currency_id'        => $this->egpId,
                'safe_account_id'    => $this->safeAccountId,
                'lines' => [[
                    'description'         => 'Factory supplies — '.$monthStart->format('F Y'),
                    'expense_account_id'  => $this->expenseAccounts['Factory Supplies Expense'],
                    'subtotal'             => random_int(15000, 30000),
                ]],
            ], $this->actor);
            $this->successCount++;

            if ($m % 3 === 0) {
                $this->cashExpService->record($this->companyId, [
                    'payee_description' => 'Trade show & marketing',
                    'payment_date'       => (clone $monthStart)->addDays(18)->toDateString(),
                    'money_type'         => 'outgoing_transfer',
                    'currency_id'        => $this->egpId,
                    'source_type'        => 'bank_account',
                    'source_bank_account_id' => $bank->id,
                    'lines' => [[
                        'description'         => 'Trade show & marketing — Q covering '.$monthStart->format('F Y'),
                        'expense_account_id'  => $this->expenseAccounts['Trade Show & Marketing'],
                        'subtotal'             => random_int(50000, 100000),
                    ]],
                ], $this->actor);
                $this->successCount++;
            }

            $this->line('    Monthly rent/utilities/telecom/supplies posted.');
        } catch (\Throwable $e) {
            $this->recordFailure('Monthly expenses month '.($m + 1), $e);
        }
    }

    // ── Accruals + prepaid insurance (renewed every January) ──────

    private function monthlyAccrualsAndPrepaid(Carbon $monthStart, int $m): void
    {
        if (in_array($m, [0, 12, 24], true)) {
            try {
                $supplier = $this->suppliers[6];
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
                        'description'          => 'Annual factory & equipment insurance — '.$monthStart->format('Y'),
                        'unit_price'            => 150000,
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

        if ($m % 2 === 1) {
            try {
                $supplier = $this->suppliers[6];
                $this->accrualService->create($this->companyId, [
                    'partner_id'          => $supplier->id,
                    'chart_of_account_id' => $this->expenseAccounts['Professional Fees — Audit & Legal'],
                    'description'          => 'Accrued audit & legal fees — '.$monthStart->format('F Y'),
                    'accrual_date'         => (clone $monthStart)->endOfMonth()->toDateString(),
                    'amount'               => random_int(20000, 40000),
                ], $this->actor);
                $this->successCount++;
            } catch (\Throwable $e) {
                $this->recordFailure('Accrual month '.($m + 1), $e);
            }
        }

        if ($m % 6 === 3) {
            try {
                $this->accrualService->create($this->companyId, [
                    'partner_id'          => $this->suppliers[6]->id,
                    'chart_of_account_id' => $this->expenseAccounts['Staff Training & Certification'],
                    'description'          => 'Accrued staff certification & training costs — '.$monthStart->format('F Y'),
                    'accrual_date'         => (clone $monthStart)->endOfMonth()->toDateString(),
                    'amount'               => random_int(25000, 50000),
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
                    $bank = $this->banks[array_rand([0, 1])]['account'];
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
                    $bank = $this->banks[array_rand([0, 1])]['account'];
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
        for ($i = 0; $i < $count; $i++) {
            $picked[] = $this->finishedGoods[($seed + $i * 2) % $total];
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
