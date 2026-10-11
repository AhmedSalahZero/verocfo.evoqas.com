<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  Setup
//
//  Day one of a company: incorporation, the chart of accounts it will
//  actually use, warehouses, catalogue, people, banks, and — for a
//  manufacturer — the standard costing and bills of material that
//  every later production order depends on.
//
//  Everything goes through the real services, in the order the
//  application itself requires. Two ordering constraints are not
//  obvious and both were learned from the existing demo commands:
//
//    - Standard costing comes BEFORE bills of material, because a
//      BOM's routing operation needs a Machine that is already
//      linked to an Overhead Pool.
//    - A BOM must be ACTIVATED after it is created. create() always
//      produces a draft, and production orders only ever resolve an
//      active version — the existing manufacturing seeder silently
//      produced nothing for 32 months because of exactly this.
//
//  The catalogue is deliberately small and its costs are round
//  numbers. This is a correctness cycle, not a volume test: six
//  products whose weighted average can be checked by hand at every
//  step are worth more than sixty that cannot.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Verify;
use App\Models\ChartOfAccount;
use App\Models\InventoryLocation;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Services\Accounting\BillOfMaterialService;
use App\Services\Accounting\LaborCategoryService;
use App\Services\Accounting\LeaveTypeService;
use App\Services\Accounting\MachineService;
use App\Services\Accounting\OverheadPoolService;
use App\Services\Accounting\ProcessService;
use App\Services\Accounting\StandardRateService;
use App\Services\ChartOfAccountService;
use App\Services\CompanyService;
use App\Services\FinancialInstitutions\BankService;
use App\Services\PartnerService;
use App\Services\ProductCategoryService;
use App\Services\ProductService;
use App\Models\Currency;
use App\Models\FiscalYear;

class Setup
{
    public const ARCHETYPE_MANUFACTURING = 'manufacturing';
    public const ARCHETYPE_TRADING       = 'trading';

    public function __construct(
        private CycleLog $log,
        private FormGate $gate,
    ) {
    }

    public function run(User $actor, string $name, string $archetype, int $startYear): ?CycleContext
    {
        $ctx = new CycleContext($actor, $name);

        $this->log->enterCompany($name);
        $this->log->enterPeriod('setup');

        if (! $this->createCompany($ctx, $name, $archetype, $startYear)) {
            return null;
        }

        $this->createLocationsAndSafe($ctx);
        $this->createCatalogue($ctx, $archetype);

        if ($archetype === self::ARCHETYPE_MANUFACTURING) {
            $this->createStandardCosting($ctx, $startYear);
            $this->createBillsOfMaterial($ctx, $startYear);
        }

        $this->createPartners($ctx);
        $this->createBanks($ctx);
        $this->createExpenseAccounts($ctx);
        $this->createLeaveTypes($ctx);

        return $ctx;
    }

    // ══════════════════════════════════════════════════════════════

    private function createCompany(CycleContext $ctx, string $name, string $archetype, int $startYear): bool
    {
        $company = $this->gate->submit(
            \App\Http\Requests\CompanyRequest::class,
            [
                'name_en'                 => $name,
                'name_ar'                 => $name,
                'company_type'            => $archetype === self::ARCHETYPE_MANUFACTURING
                                                ? ['Manufacturing']
                                                : ['Trading'],
                'grn_policy'              => 'po_required',
                'base_currency'           => 'EGP',
                'fiscal_year_start_month' => 1,
                'opening_balance_date'    => $startYear.'-01-01',
                // Capped below 2038: subscription_expires_at is a MySQL
                // TIMESTAMP, so it cannot hold a date past the Y2038
                // boundary. Noted as a schema limitation, not worked
                // around anywhere else.
                'subscription_expires_at' => min($startYear + 20, 2037).'-12-31',
                'country'                 => 'Egypt',
                'subscription_tier'       => 'Enterprise',
                'is_active'               => true,
            ],
            fn (array $data) => app(CompanyService::class)->create($data, $ctx->actor),
            'Incorporate '.$name,
            'company'
        );

        if (! $company) {
            return false;
        }

        $ctx->company   = $company;
        $ctx->companyId = $company->id;

        app()->instance('currentCompanyId', $company->id);

        $ctx->currencyId = (int) Currency::withoutGlobalScopes()
            ->whereNull('company_id')->where('code', 'EGP')->value('id');
        $ctx->paymentTermId = (int) PaymentTerm::withoutGlobalScopes()
            ->whereNull('company_id')->orderBy('id')->value('id');
        $ctx->unitId = (int) UnitOfMeasure::withoutGlobalScopes()
            ->whereNull('company_id')->where('code', 'UNIT')->value('id');

        $verify = new Verify($this->log, $ctx->companyId);

        // Incorporation should have provisioned a first fiscal year
        // and its periods — every later posting depends on it.
        $fy = FiscalYear::withoutGlobalScopes()->where('company_id', $ctx->companyId)->first();
        $verify->truth(
            $fy !== null,
            'Creating a company did not open its first fiscal year',
            'Incorporate '.$name,
            severity: Finding::SEVERITY_CRITICAL,
            prefix: 'SET'
        );

        if ($fy) {
            $verify->truth(
                $fy->periods()->count() === 12,
                'The first fiscal year does not have 12 accounting periods',
                'Incorporate '.$name,
                ['periods found' => $fy->periods()->count()],
                prefix: 'SET'
            );
        }

        $this->log->note("Company created (id {$ctx->companyId}), fiscal year {$startYear} open.");

        return true;
    }

    private function createLocationsAndSafe(CycleContext $ctx): void
    {
        $master = InventoryLocation::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('type', InventoryLocation::TYPE_MASTER)
            ->first();

        $verify = new Verify($this->log, $ctx->companyId);
        $verify->truth(
            $master !== null,
            'Creating a company did not provision its master warehouse',
            'Company setup',
            severity: Finding::SEVERITY_CRITICAL,
            prefix: 'SET'
        );

        if ($master) {
            $ctx->masterLocationId = $master->id;
        }

        // A second warehouse, so transfers are part of the history
        // rather than an untested corner. Through the Form Request and
        // the service like everything else — the code is generated by
        // InventoryLocation::nextMainCode(), which is itself worth
        // exercising rather than bypassing with a hand-written 'WH2'.
        $second = $this->gate->submit(
            \App\Http\Requests\Settings\InventoryLocationRequest::class,
            [
                'name_en' => 'Secondary Warehouse',
                'name_ar' => 'المخزن الفرعي',
            ],
            fn (array $data) => app(\App\Services\InventoryLocationService::class)
                ->create($ctx->companyId, $data, $ctx->actor),
            'Open a second warehouse',
            'warehouse'
        );

        if ($second) {
            $ctx->secondLocationId = $second->id;
        }

        // Cash safe — 131 ships as a non-postable group, so the
        // company creates its own leaf underneath, exactly as the
        // demo commands do.
        $safe = app(ChartOfAccountService::class)->create($ctx->companyId, [
            'parent_id'     => $this->coa($ctx, '131'),
            'use_auto_code' => true,
            'name_en'       => 'Main Safe',
            'name_ar'       => 'الخزينة الرئيسية',
        ], $ctx->actor);

        $ctx->safeAccountId = $safe->id;
        $this->log->operation('chart of accounts leaf');
    }

    private function createCatalogue(CycleContext $ctx, string $archetype): void
    {
        if ($archetype === self::ARCHETYPE_MANUFACTURING) {
            $rawCategory = app(ProductCategoryService::class)
                ->create($ctx->companyId, ['name_en' => 'Raw Materials'], $ctx->actor);
            $fgCategory = app(ProductCategoryService::class)
                ->create($ctx->companyId, ['name_en' => 'Finished Goods'], $ctx->actor);

            // Round costs on purpose: every weighted average in this
            // cycle has to be checkable by hand.
            $raws = [
                ['Steel Sheet',   '100.00'],
                ['Copper Wire',   '40.00'],
                ['Bearing Set',   '25.00'],
            ];
            foreach ($raws as [$rawName, $cost]) {
                $product = $this->makeProduct($ctx, $rawName, $rawCategory->id, [Product::TYPE_RAW_MATERIAL], null);
                $ctx->rawMaterials[] = $product;
                $ctx->pricing[$product->id] = ['cost' => $cost, 'price' => '0.00'];
                $ctx->book->rename($product->id, $rawName);
            }

            $goods = [
                ['Steel Cabinet',  '2400.00'],
                ['Motor Assembly', '3600.00'],
            ];
            foreach ($goods as [$fgName, $price]) {
                $product = $this->makeProduct($ctx, $fgName, $fgCategory->id, [Product::TYPE_SALEABLE], Product::NATURE_MANUFACTURED);
                $ctx->finishedGoods[] = $product;
                $ctx->pricing[$product->id] = ['cost' => '0.00', 'price' => $price];
                $ctx->book->rename($product->id, $fgName);
            }

            $this->log->note(count($ctx->rawMaterials).' raw materials and '.count($ctx->finishedGoods).' finished goods created.');

            return;
        }

        $category = app(ProductCategoryService::class)
            ->create($ctx->companyId, ['name_en' => 'Traded Goods'], $ctx->actor);

        $goods = [
            ['Office Chair',   '400.00',  '900.00'],
            ['Desk Lamp',      '120.00',  '260.00'],
            ['Filing Cabinet', '650.00', '1400.00'],
        ];
        foreach ($goods as [$goodName, $cost, $price]) {
            $product = $this->makeProduct($ctx, $goodName, $category->id, [Product::TYPE_SALEABLE], Product::NATURE_TRADABLE);
            $ctx->tradeGoods[] = $product;
            $ctx->pricing[$product->id] = ['cost' => $cost, 'price' => $price];
            $ctx->book->rename($product->id, $goodName);
        }

        $this->log->note(count($ctx->tradeGoods).' traded products created.');
    }

    private function makeProduct(CycleContext $ctx, string $name, int $categoryId, array $usageTypes, ?string $nature): Product
    {
        $data = [
            'use_auto_code'  => true,
            'name_en'        => $name,
            'category_id'    => $categoryId,
            'usage_types'    => $usageTypes,
            'primary_uom_id' => $ctx->unitId,
            'reorder_level'  => 10,
            'reorder_qty'    => 100,
        ];

        // A manufacturing company has three valid natures, so the
        // server cannot infer one — the form supplies it (audit
        // finding PRD-001).
        if ($nature !== null) {
            $data['nature'] = $nature;
        }

        $product = $this->gate->submit(
            \App\Http\Requests\Settings\ProductRequest::class,
            $data,
            fn (array $payload) => app(ProductService::class)->create($ctx->companyId, $payload, $ctx->actor),
            'Create product — '.$name,
            'product'
        );

        if (! $product) {
            // The form refused a product the cycle needs. Recorded by
            // the gate; return a stub so setup can continue and the
            // rest of the run still produces evidence.
            return app(ProductService::class)->create($ctx->companyId, $data, $ctx->actor);
        }

        // A product with no inventory account cannot be received, and
        // the failure surfaces weeks later at posting time. Catch it
        // here instead.
        (new Verify($this->log, $ctx->companyId))->truth(
            $product->inventory_account_id !== null,
            "Product \"{$name}\" was saved without an inventory account",
            'Create product',
            ['nature' => (string) $product->nature, 'usage types' => implode(',', $usageTypes)],
            prefix: 'PRD'
        );

        return $product;
    }

    private function createStandardCosting(CycleContext $ctx, int $startYear): void
    {
        $pool = app(OverheadPoolService::class)->create($ctx->companyId, [
            'name'            => 'Factory Overhead',
            'allocation_base' => 'machine_hours',
        ], $ctx->actor);
        $ctx->overheadPoolId = $pool->id;

        $process = app(ProcessService::class)->create($ctx->companyId, [
            'name' => 'Assembly',
        ], $ctx->actor);
        $ctx->processId = $process->id;

        $machine = app(MachineService::class)->create($ctx->companyId, [
            'name'              => 'Line 1',
            'process_id'        => $process->id,
            'status'            => 'active',
            'overhead_pool_ids' => [$pool->id],
        ], $ctx->actor);

        $labor = app(LaborCategoryService::class)->create($ctx->companyId, [
            'name' => 'Machine Operator',
        ], $ctx->actor);

        $fiscalYearId = (int) FiscalYear::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)->value('id');

        // Round rates so labour and overhead on every production order
        // stay checkable by hand.
        app(StandardRateService::class)->setLaborCategoryRate(
            $ctx->companyId, $fiscalYearId, $labor->id, '50.00', $ctx->actor, 'Machine Operator — '.$startYear
        );
        app(StandardRateService::class)->setOverheadPoolRate(
            $ctx->companyId, $fiscalYearId, $pool->id, '40.00', $ctx->actor, 'Factory overhead — '.$startYear
        );

        $ctx->machineId = $machine->id;
        $ctx->laborCategoryId = $labor->id;

        $this->log->operation('standard costing setup');
        $this->log->note('Overhead pool, process, machine and labour category created; labour 50.00/hr, overhead 40.00/machine-hr.');
    }

    private function createBillsOfMaterial(CycleContext $ctx, int $startYear): void
    {
        // Recipes chosen so the standard material cost per unit is a
        // round number at the opening purchase costs:
        //   Steel Cabinet  = 2 steel (200) + 4 bearings (100)  = 300.00
        //   Motor Assembly = 1 steel (100) + 5 copper  (200)   = 300.00
        $recipes = [
            0 => [[0, 2], [2, 4]],
            1 => [[0, 1], [1, 5]],
        ];
        $laborHours   = ['2.00', '3.00'];
        $machineHours = ['1.00', '2.00'];

        foreach ($ctx->finishedGoods as $index => $fg) {
            $lines = [];
            foreach ($recipes[$index] as [$rawIndex, $qtyPerUnit]) {
                $raw = $ctx->rawMaterials[$rawIndex];
                $lines[] = [
                    'component_product_id' => $raw->id,
                    'quantity_per_batch'   => $qtyPerUnit,
                    'uom_id'               => $raw->primary_uom_id,
                ];
            }

            $bom = $this->gate->submit(
                \App\Http\Requests\Accounting\BillOfMaterialRequest::class,
                [
                'product_id'      => $fg->id,
                'output_quantity' => 1,
                'effective_date'  => $startYear.'-01-01',
                'lines'           => $lines,
                'operations'      => [[
                    'process_id'             => $ctx->processId,
                    'default_machine_id'     => $ctx->machineId,
                    'labor_category_id'      => $ctx->laborCategoryId,
                    'standard_labor_hours'   => $laborHours[$index],
                    'standard_machine_hours' => $machineHours[$index],
                ]],
                ],
                fn (array $data) => app(BillOfMaterialService::class)->create($ctx->companyId, $data, $ctx->actor),
                'Create a bill of material — '.$fg->name_en,
                'bill of material'
            );

            if (! $bom) {
                continue;
            }

            // create() always produces a DRAFT. Production orders only
            // resolve an ACTIVE version — skipping this is the exact
            // reason the existing manufacturing demo produced nothing.
            app(BillOfMaterialService::class)->activate($ctx->companyId, $bom, $ctx->actor);

            $ctx->bomsByProduct[$fg->id] = [
                'bom'           => $bom->fresh(['lines', 'operations']),
                'recipe'        => $recipes[$index],
                'labor_hours'   => $laborHours[$index],
                'machine_hours' => $machineHours[$index],
            ];

        }

        $this->log->note(count($ctx->bomsByProduct).' bills of material created and activated.');
    }

    private function createPartners(CycleContext $ctx): void
    {
        foreach (['Nile Retail Group', 'Delta Trading Partners'] as $customerName) {
            $customer = $this->gate->submit(
                \App\Http\Requests\Settings\PartnerRequest::class,
                [
                    'name_en'            => $customerName,
                    'types'              => ['customer'],
                    'credit_limit'       => 50_000_000,
                    'collection_term_id' => $ctx->paymentTermId,
                ],
                fn (array $data) => app(PartnerService::class)->create($ctx->companyId, $data, $ctx->actor),
                'Register a customer — '.$customerName,
                'partner'
            );

            if ($customer) {
                // Factoring insists on partner.ar_account_id while every
                // other receivable path falls back to 133001 — an
                // inconsistency the cycle records once, in
                // Treasury::factorAnInvoice(). Setting it here so the
                // factoring lifecycle is reachable at all.
                $customer->update(['ar_account_id' => $this->coa($ctx, '133001')]);
                $ctx->customers[] = $customer->fresh();
            }
        }

        foreach (['Egypt Metals Co.', 'Cairo Components Ltd'] as $supplierName) {
            $supplier = $this->gate->submit(
                \App\Http\Requests\Settings\PartnerRequest::class,
                [
                    'name_en'         => $supplierName,
                    'types'           => ['supplier'],
                    'payment_term_id' => $ctx->paymentTermId,
                ],
                fn (array $data) => app(PartnerService::class)->create($ctx->companyId, $data, $ctx->actor),
                'Register a supplier — '.$supplierName,
                'partner'
            );

            if ($supplier) {
                $ctx->suppliers[] = $supplier;
            }
        }

        foreach (['Mona Adel', 'Karim Fouad'] as $employeeName) {
            $ctx->employees[] = app(PartnerService::class)->create($ctx->companyId, [
                'name_en' => $employeeName,
                'types'   => ['employee'],
            ], $ctx->actor);
            $this->log->operation('partner');
        }

        // A freight agent. Landing costs may only be raised against a
        // partner explicitly flagged as a landing cost supplier, and
        // routed to a 146xxx Landing Cost Clearing account.
        $freight = app(PartnerService::class)->create($ctx->companyId, [
            'name_en'         => 'Delta Freight & Customs',
            'types'           => ['supplier'],
            'payment_term_id' => $ctx->paymentTermId,
        ], $ctx->actor);

        // 146 Landing Cost Clearing ships as a non-postable group, so
        // the company creates its own leaf underneath — the same shape
        // as the cash safe under 131.
        $clearingParent = ChartOfAccount::findByCode($ctx->companyId, '146');

        $clearing = $clearingParent
            ? app(ChartOfAccountService::class)->create($ctx->companyId, [
                'parent_id'     => $clearingParent->id,
                'use_auto_code' => true,
                'name_en'       => 'Freight & Customs Clearing',
            ], $ctx->actor)
            : null;

        $freight->update([
            'is_landing_cost_supplier' => true,
            'landing_cost_account_id'  => $clearing?->id,
        ]);
        $ctx->landingCostSupplier = $freight->fresh();
        $this->log->operation('partner');

        // A partner that both buys from and sells to the company —
        // the only way an internal settlement (netting a receivable
        // against a payable) is reachable at all.
        $ctx->dualRolePartner = app(PartnerService::class)->create($ctx->companyId, [
            'name_en'            => 'Horizon Trading (both ways)',
            'types'              => ['customer', 'supplier'],
            'credit_limit'       => 10_000_000,
            'collection_term_id' => $ctx->paymentTermId,
            'payment_term_id'    => $ctx->paymentTermId,
        ], $ctx->actor);
        $ctx->dualRolePartner->update(['ar_account_id' => $this->coa($ctx, '133001')]);
        $ctx->dualRolePartner = $ctx->dualRolePartner->fresh();
        $ctx->customers[] = $ctx->dualRolePartner;
        $ctx->suppliers[] = $ctx->dualRolePartner;
        $this->log->operation('partner');

        $ctx->shareholders[] = app(PartnerService::class)->create($ctx->companyId, [
            'name_en' => 'Founding Shareholder',
            'types'   => ['shareholder'],
        ], $ctx->actor);
        $this->log->operation('partner');

        $this->log->note('Customers, suppliers, employees and a shareholder registered.');
    }

    private function createBanks(CycleContext $ctx): void
    {
        foreach (['Commercial International Bank', 'National Bank of Egypt'] as $bankName) {
            $bank = app(BankService::class)->createBank($ctx->companyId, [
                'name_en'   => $bankName,
                'is_active' => true,
            ], $ctx->actor);

            $leaf = app(ChartOfAccountService::class)->create($ctx->companyId, [
                'parent_id'     => $this->coa($ctx, '132'),
                'use_auto_code' => true,
                'name_en'       => $bankName.' — Current Account',
            ], $ctx->actor);

            $ctx->bankAccounts[] = app(BankService::class)->createBankAccount($ctx->companyId, $bank, [
                'account_name'   => $bankName.' — Current Account',
                'account_number' => '100'.str_pad((string) (count($ctx->bankAccounts) + 1), 7, '0', STR_PAD_LEFT),
                'account_type'   => 'current',
                'currency_id'    => $ctx->currencyId,
                'coa_account_id' => $leaf->id,
                'is_active'      => true,
            ], $ctx->actor);

            $this->log->operation('bank account');
        }

        $this->log->note(count($ctx->bankAccounts).' bank accounts opened.');
    }

    private function createExpenseAccounts(CycleContext $ctx): void
    {
        // 543 General & Administrative is a seeded postable leaf; the
        // company adds its own children for the expenses it actually
        // runs, which is what a real chart looks like.
        $wanted = [
            'Office Rent'      => '551',
            'Utilities'        => '551',
            'Professional Fees' => '551',
        ];

        foreach ($wanted as $label => $parentCode) {
            $parent = ChartOfAccount::findByCode($ctx->companyId, $parentCode);

            if (! $parent) {
                // Fall back to the seeded reimbursement account rather
                // than inventing a chart of accounts.
                $ctx->expenseAccounts[$label] = $this->coa($ctx, '543');

                continue;
            }

            if ($parent->is_postable) {
                $ctx->expenseAccounts[$label] = $parent->id;

                continue;
            }

            $leaf = app(ChartOfAccountService::class)->create($ctx->companyId, [
                'parent_id'     => $parent->id,
                'use_auto_code' => true,
                'name_en'       => $label,
            ], $ctx->actor);

            $ctx->expenseAccounts[$label] = $leaf->id;
            $this->log->operation('chart of accounts leaf');
        }
    }

    private function createLeaveTypes(CycleContext $ctx): void
    {
        $ctx->leaveTypes['annual'] = app(LeaveTypeService::class)->create($ctx->companyId, [
            'name_en'                 => 'Annual Leave',
            'is_paid'                 => true,
            'annual_entitlement_days' => 21,
        ], $ctx->actor);

        $ctx->leaveTypes['unpaid'] = app(LeaveTypeService::class)->create($ctx->companyId, [
            'name_en'                 => 'Unpaid Leave',
            'is_paid'                 => false,
            'annual_entitlement_days' => null,
        ], $ctx->actor);

        $this->log->operation('leave type');
        $this->log->operation('leave type');
    }

    private function coa(CycleContext $ctx, string $code): int
    {
        return (int) ChartOfAccount::findByCode($ctx->companyId, $code)?->id;
    }
}
