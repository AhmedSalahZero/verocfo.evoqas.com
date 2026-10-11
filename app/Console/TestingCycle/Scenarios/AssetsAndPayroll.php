<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  AssetsAndPayroll
//
//  Two domains that only make sense over a long history, which is
//  why a single-month test never reaches them properly:
//
//    FIXED ASSETS   are bought once and then depreciate every month
//                   for years. The interesting question is not "did
//                   the purchase post" but "after 36 months, is
//                   accumulated depreciation exactly 36 instalments,
//                   and has the asset stopped at its residual value".
//
//    PAYROLL        runs every month, and salaries are revised
//                   annually — so the accrual has to follow the
//                   revision, and end-of-service provision has to
//                   follow both.
//
//  A fixed asset is not created directly. It arrives the way it does
//  in real life: a purchase order for an asset type, a goods receipt,
//  a supplier bill — and the Fixed Asset Register picks it up from
//  the receipt line. The cycle then puts it in service and starts
//  depreciating. Creating the FixedAsset row directly would skip the
//  entire chain being tested.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Verify;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Services\Accounting\EosbProvisionRunService;
use App\Services\Accounting\EmployeePayrollProfileService;
use App\Services\Accounting\FixedAssetService;
use App\Services\Accounting\GoodsReceiptNoteService;
use App\Services\Accounting\PayrollRunService;
use App\Services\Accounting\PurchaseOrderService;
use App\Services\Accounting\SupplierBillService;
use App\Services\ProductService;
use Carbon\Carbon;

class AssetsAndPayroll
{
    /** So the salary-dating limitation is reported once, not per employee per year. */
    private bool $reportedSalaryDating = false;

    public function __construct(
        private CycleLog $log,
        private FormGate $gate,
    ) {
    }

    // ══════════════════════════════════════════════════════════════
    //  Setup
    // ══════════════════════════════════════════════════════════════

    public function setUpPayroll(CycleContext $ctx, bool $isManufacturer = false): void
    {
        $this->log->enterPeriod('payroll setup');

        // Round salaries so tax, social insurance and the EOSB
        // provision all stay checkable by hand.
        //
        // A manufacturer puts its first employee on the factory floor.
        // That is not decoration: manufacturing variance closing has
        // nothing to sweep unless a posted payroll run has actually
        // charged direct labour, so without one the whole variance
        // module is unreachable.
        $salaries   = ['20000.00', '15000.00'];
        $categories = $isManufacturer
            ? ['manufacturing', 'general_admin']
            : ['general_admin', 'sales'];

        foreach ($ctx->employees as $index => $employee) {
            $category = $categories[$index % count($categories)];

            $payload = [
                'partner_id'        => $employee->id,
                'base_salary'       => $salaries[$index % count($salaries)],
                'currency_id'       => $ctx->currencyId,
                'expense_category'  => $category,
                'eosb_rate_percent' => 8.33,
            ];

            if ($category === 'manufacturing') {
                $payload['manufacturing_role'] = \App\Models\EmployeePayrollProfile::ROLE_DIRECT_LABOR;
            }

            $profile = $this->gate->submit(
                \App\Http\Requests\Accounting\EmployeePayrollProfileRequest::class,
                $payload,
                fn (array $data) => app(EmployeePayrollProfileService::class)->create($ctx->companyId, $data, $ctx->actor),
                'Create a payroll profile for '.$employee->name_en,
                'payroll profile'
            );

            if ($profile) {
                $ctx->payrollProfiles[] = $profile;
            }
        }

        $this->log->note(count($ctx->payrollProfiles).' payroll profiles created.');
    }

    // ══════════════════════════════════════════════════════════════
    //  Fixed assets
    // ══════════════════════════════════════════════════════════════

    /**
     * Buys one asset through the ordinary purchase chain and puts it
     * in service. Called once per year so the register accumulates a
     * real portfolio over the cycle.
     */
    public function buyFixedAsset(CycleContext $ctx, Carbon $date, int $yearIndex): void
    {
        $verify   = new Verify($this->log, $ctx->companyId);
        $supplier = $ctx->suppliers[0];

        // 120,000 over 60 months with no residual = exactly 2,000 a
        // month. Chosen so accumulated depreciation is checkable by
        // multiplication rather than by trusting the schedule.
        $cost        = '120000.00';
        $lifeMonths  = 60;
        $residual    = 0;
        $monthly     = '2000.00';
        $name        = 'Delivery Vehicle '.($yearIndex + 1);

        $assetAccount = ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')->where('code', '111004')->first();

        if (! $assetAccount) {
            return;
        }

        $assetType = $this->gate->submit(
            \App\Http\Requests\Settings\FixedAssetTypeRequest::class,
            [
                'name_en'                    => $name,
                'fixed_asset_account_id'     => $assetAccount->id,
                'default_useful_life_months' => $lifeMonths,
                'default_residual_value'     => $residual,
            ],
            fn (array $data) => app(ProductService::class)->createFixedAssetType($ctx->companyId, $data, $ctx->actor),
            'Register a fixed asset type — '.$name,
            'fixed asset type'
        );

        if (! $assetType) {
            return;
        }

        $po = app(PurchaseOrderService::class)->create($ctx->companyId, [
            'partner_id'      => $supplier->id,
            'subject'         => 'Fixed asset purchase — '.$name,
            'currency_id'     => $ctx->currencyId,
            'exchange_rate'   => 1,
            'issuance_date'   => $date->toDateString(),
            'start_date'      => $date->toDateString(),
            'end_date'        => $date->copy()->addDays(30)->toDateString(),
            'payment_term_id' => $ctx->paymentTermId,
            'lines'           => [[
                'product_id'  => $assetType->id,
                'description' => $name,
                'quantity'    => 1,
                'uom_id'      => $assetType->primary_uom_id,
                'unit_price'  => $cost,
            ]],
        ], $ctx->actor);
        app(PurchaseOrderService::class)->activate($po, $ctx->actor);

        $grn = $this->gate->submit(
            \App\Http\Requests\Accounting\GoodsReceiptNoteRequest::class,
            [
                'purchase_order_id'     => $po->id,
                'partner_id'            => $supplier->id,
                'inventory_location_id' => $ctx->masterLocationId,
                'received_date'         => $date->copy()->addDays(3)->toDateString(),
                'lines'                 => [[
                    'purchase_order_line_id' => $po->lines[0]->id,
                    'product_id'             => $assetType->id,
                    'quantity'               => 1,
                    'uom_id'                 => $assetType->primary_uom_id,
                    'unit_cost'              => $cost,
                ]],
            ],
            fn (array $data) => app(GoodsReceiptNoteService::class)->post(
                app(GoodsReceiptNoteService::class)->create($ctx->companyId, $data, $ctx->actor),
                $ctx->actor
            ),
            'Receive a fixed asset — '.$name,
            'fixed asset purchase'
        );

        if (! $grn) {
            return;
        }

        $bill = app(SupplierBillService::class)->create($ctx->companyId, [
            'bill_type'               => 'goods',
            'partner_id'              => $supplier->id,
            'purchase_order_id'       => $po->id,
            'supplier_invoice_number' => 'FA-'.$ctx->companyId.'-'.$yearIndex,
            'supplier_invoice_date'   => $date->copy()->addDays(3)->toDateString(),
            'bill_date'               => $date->copy()->addDays(3)->toDateString(),
            'currency_id'             => $ctx->currencyId,
            'payment_term_id'         => $ctx->paymentTermId,
            'lines'                   => [[
                'goods_receipt_note_line_id' => $grn->lines[0]->id,
                'product_id'                 => $assetType->id,
                'description'                => $name,
                'quantity'                   => 1,
                'unit_price'                 => $cost,
                'vat_rate'                   => 14,
            ]],
        ], $ctx->actor);
        $bill = app(SupplierBillService::class)->post($bill, $ctx->actor);
        $ctx->openSupplierBills[] = $bill;

        // The register should have picked the asset up from the
        // receipt line without anyone creating it by hand.
        $asset = FixedAsset::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('source_type', 'goods_receipt_note_line')
            ->where('source_id', $grn->lines[0]->id)
            ->first();

        if (! $asset) {
            $this->log->finding(
                prefix: 'FA',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'Receiving a fixed asset did not create a Fixed Asset Register entry',
                operation: 'Receive a fixed asset — '.$name,
                expected: 'one fixed_assets row linked to the goods receipt line',
                actual: 'none was created',
                rootCause: 'The asset is on the balance sheet with nothing depreciating it.',
            );

            return;
        }

        app(FixedAssetService::class)->markInService($asset, $ctx->actor);

        $ctx->fixedAssets[] = [
            'asset'   => $asset->fresh(),
            'from'    => Carbon::parse($asset->fresh()->depreciation_start_date),
            'monthly' => $monthly,
            'cost'    => $cost,
            'name'    => $name,
            'posted'  => 0,
        ];

        $this->log->note("Fixed asset in service: {$name} — 120,000 EGP over 60 months (2,000/month).");
    }

    /**
     * One month's depreciation on every asset in service, then the
     * check that matters: accumulated depreciation must equal the
     * number of instalments actually posted, times the instalment.
     */
    public function postDepreciation(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify = new Verify($this->log, $ctx->companyId);

        foreach ($ctx->fixedAssets as $index => $fa) {
            if ($monthStart->lt($fa['from'])) {
                continue;
            }

            try {
                app(FixedAssetService::class)->postMonthlyDepreciation(
                    $fa['asset'], $ctx->actor, \Illuminate\Support\Carbon::instance($monthStart)
                );
                $this->log->operation('depreciation posting');
                $ctx->fixedAssets[$index]['posted']++;
            } catch (\Throwable $e) {
                $this->log->finding(
                    prefix: 'FA',
                    severity: Finding::SEVERITY_MEDIUM,
                    confidence: Finding::CONFIDENCE_CONFIRMED,
                    title: 'Posting monthly depreciation failed',
                    operation: 'Depreciation '.$monthStart->format('M Y').' — '.$fa['name'],
                    expected: 'one depreciation entry of '.$fa['monthly'],
                    actual: get_class($e).': '.$e->getMessage(),
                );

                continue;
            }

            // Independent expectation: instalments posted x instalment.
            $expected = bcmul((string) $ctx->fixedAssets[$index]['posted'], $fa['monthly'], 2);
            $actual   = (string) $fa['asset']->fresh()->accumulated_depreciation;

            $verify->money(
                $expected,
                $actual,
                'Accumulated depreciation does not match the instalments posted for '.$fa['name'],
                'Depreciation '.$monthStart->format('M Y'),
                [
                    'instalments posted' => (string) $ctx->fixedAssets[$index]['posted'],
                    'monthly instalment' => $fa['monthly'],
                    'asset cost'         => $fa['cost'],
                ],
                prefix: 'FA'
            );

            // An asset may never depreciate past its own cost.
            if (bccomp($actual, $fa['cost'], 2) > 0) {
                $this->log->finding(
                    prefix: 'FA',
                    severity: Finding::SEVERITY_CRITICAL,
                    confidence: Finding::CONFIDENCE_CONFIRMED,
                    title: 'An asset has depreciated past its own cost',
                    operation: 'Depreciation '.$monthStart->format('M Y'),
                    expected: 'accumulated depreciation <= '.$fa['cost'],
                    actual: $actual,
                    difference: bcsub($actual, $fa['cost'], 2),
                );
            }
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Payroll
    // ══════════════════════════════════════════════════════════════

    public function runPayroll(CycleContext $ctx, Carbon $monthStart): void
    {
        if ($ctx->payrollProfiles === []) {
            return;
        }

        $verify  = new Verify($this->log, $ctx->companyId);
        $runDate = $monthStart->copy()->endOfMonth()->toDateString();

        $run = $this->gate->submit(
            null,
            [],
            fn () => app(PayrollRunService::class)->create(
                $ctx->companyId, $monthStart->year, $monthStart->month, $runDate, $ctx->actor
            ),
            'Create the payroll run — '.$monthStart->format('M Y'),
            'payroll run'
        );

        if (! $run) {
            return;
        }

        // Statutory deductions, at fixed rates so net pay is
        // predictable: 10% tax, 11% employee social insurance on a
        // capped wage base, 18.75% employer contribution.
        $expectedGross = '0.00';
        $expectedNet   = '0.00';

        foreach ($run->lines as $line) {
            $gross      = (string) $line->gross_pay;
            $tax        = bcmul($gross, '0.10', 2);
            $siBase     = bccomp($gross, '12000.00', 2) > 0 ? '12000.00' : $gross;
            $siEmployee = bcmul($siBase, '0.11', 2);
            $siEmployer = bcmul($siBase, '0.1875', 2);

            app(PayrollRunService::class)->setManualAmounts(
                $line, $tax, $siEmployee, $siEmployer, '0.00', $ctx->actor
            );

            $expectedGross = bcadd($expectedGross, $gross, 2);
            $expectedNet   = bcadd($expectedNet, bcsub(bcsub($gross, $tax, 2), $siEmployee, 2), 2);
        }

        $posted = $this->gate->submit(
            null,
            [],
            fn () => app(PayrollRunService::class)->post($run->fresh(), $ctx->actor),
            'Post the payroll run — '.$monthStart->format('M Y'),
            'payroll posting'
        );

        if (! $posted) {
            return;
        }

        // Net pay across the run, recomputed independently from gross
        // minus the deductions the cycle itself set.
        $actualNet = '0.00';
        foreach ($posted->fresh('lines')->lines as $line) {
            $actualNet = bcadd($actualNet, (string) $line->net_pay, 2);
        }

        $verify->money(
            $expectedNet,
            $actualNet,
            'Payroll net pay does not equal gross less the deductions entered',
            'Payroll run — '.$monthStart->format('M Y'),
            ['gross' => $expectedGross],
            prefix: 'HR'
        );

        $verify->ledgerBalanced('Payroll run — '.$monthStart->format('M Y'));
    }

    /** An annual raise, so later payroll runs accrue a different figure. */
    public function annualSalaryRevision(CycleContext $ctx, Carbon $monthStart): void
    {
        foreach ($ctx->payrollProfiles as $index => $profile) {
            $profile->refresh();
            $newSalary = bcmul((string) $profile->base_salary, '1.10', 2);

            $revised = $this->gate->submit(
                \App\Http\Requests\Accounting\EmployeeSalaryRevisionRequest::class,
                [
                    'base_salary'    => $newSalary,
                    'effective_date' => $monthStart->toDateString(),
                    'note'           => 'Annual review — '.$monthStart->format('Y'),
                ],
                fn (array $data) => app(EmployeePayrollProfileService::class)->addSalaryRevision($profile, $data, $ctx->actor),
                'Annual salary revision — '.$monthStart->format('Y'),
                'salary revision'
            );

            if (! $revised) {
                continue;
            }

            $ctx->payrollProfiles[$index] = $profile->fresh();
            $mirror = (string) $profile->fresh()->base_salary;

            if (bccomp($mirror, $newSalary, 2) === 0) {
                $this->log->checkPassed();

                continue;
            }

            // Not simply "the update failed". The profile's
            // base_salary is a mirror of "the salary effective TODAY",
            // and the initial revision written at profile creation is
            // hard-coded to now() — so any revision dated earlier than
            // the day the profile was created loses to it. That makes
            // historical payroll unrepresentable.
            if (! $this->reportedSalaryDating) {
                $this->reportedSalaryDating = true;

                $this->log->finding(
                    prefix: 'HR',
                    severity: Finding::SEVERITY_MEDIUM,
                    confidence: Finding::CONFIDENCE_CONFIRMED,
                    title: 'An employee salary cannot be given a start date earlier than the day the profile was created',
                    operation: 'Annual salary revision — '.$monthStart->format('Y'),
                    expected: "the revision effective {$monthStart->toDateString()} to become the current salary ({$newSalary})",
                    actual: "base_salary still reads {$mirror}",
                    difference: bcsub($newSalary, $mirror, 2),
                    source: 'EmployeePayrollProfileService::create() and ::addSalaryRevision()',
                    rootCause: 'create() writes the opening salary revision with '
                        ."effective_date = now()->toDateString(), with no way to state when the salary really "
                        .'began. addSalaryRevision() then recalculates base_salary as '
                        .'salaryAsOfDate(now()), so a revision dated before the profile was created is '
                        .'outranked by that opening row and never becomes current.',
                    evidence: 'Any company migrating existing employees onto the system gets today as the start '
                        .'date for every salary, and cannot enter the history that preceded it. Payroll runs for '
                        .'past periods then accrue the wrong figure.',
                    context: [
                        'revision effective date' => $monthStart->toDateString(),
                        'profile created'         => 'today',
                        'salary the mirror shows' => $mirror,
                        'salary just entered'     => $newSalary,
                    ],
                );
            }
        }
    }

    /** End-of-service provision, once a year. */
    public function runEosbProvision(CycleContext $ctx, Carbon $monthStart): void
    {
        if ($ctx->payrollProfiles === []) {
            return;
        }

        $verify = new Verify($this->log, $ctx->companyId);

        $run = $this->gate->submit(
            null,
            [],
            fn () => app(EosbProvisionRunService::class)->create(
                $ctx->companyId,
                $monthStart->year,
                $monthStart->month,
                $monthStart->copy()->endOfMonth()->toDateString(),
                $ctx->actor
            ),
            'Create the EOSB provision run — '.$monthStart->format('M Y'),
            'EOSB provision run'
        );

        if (! $run) {
            return;
        }

        $posted = $this->gate->submit(
            null,
            [],
            fn () => app(EosbProvisionRunService::class)->post($run->fresh(), $ctx->actor),
            'Post the EOSB provision run — '.$monthStart->format('M Y'),
            'EOSB provision posting'
        );

        if ($posted) {
            $verify->ledgerBalanced('EOSB provision — '.$monthStart->format('M Y'));
        }
    }
}
