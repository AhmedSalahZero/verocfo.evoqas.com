<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  InventoryAndMasterData
//
//  The parts of the system a company touches constantly and no test
//  had ever reached: kits assembled out of components, stock reserved
//  against a customer order, projects with their own stock, supplier
//  credit notes, and the settings screens everything else depends on
//  — cost centres, payment terms, tax rates, locations.
//
//  Master data is not glamorous and it is where multi-tenant defects
//  hide, because it is the layer that gets written once per company
//  and then trusted for ever. So each one here is created through its
//  real Form Request, and the things that must not be allowed —
//  a payment term whose instalments do not add to 100%, a kit
//  assembled from stock that is not there — are attempted on purpose.
//
//  KIT ASSEMBLY IS THE INTERESTING ONE. It consumes several products
//  and receives a different one, at a cost computed from what it
//  consumed. That makes it a second, quieter implementation of the
//  same weighted-average arithmetic production orders use, and the
//  two have never been compared. Here the cycle computes the expected
//  kit cost itself, from its own parallel book, before assembling.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Verify;
use App\Models\KitRecipe;
use App\Models\SalesOrder;
use App\Services\Accounting\AdvancesSettlementsService;
use App\Services\Accounting\GovernmentRemittanceService;
use App\Services\Accounting\JobOrderTemplateService;
use App\Services\Accounting\KitAssemblyService;
use App\Services\Accounting\KitRecipeService;
use App\Services\Accounting\SupplierCreditNoteService;
use App\Services\CostCenterService;
use App\Services\CurrencyService;
use App\Services\Accounting\DeductionReasonService;
use App\Services\InventoryLocationService;
use App\Services\Inventory\ProjectInventoryService;
use App\Services\Inventory\StockReservationService;
use App\Services\PaymentTermService;
use App\Services\Projects\ProjectService;
use App\Services\SalesChannelService;
use App\Services\TaxService;
use Carbon\Carbon;

class InventoryAndMasterData
{
    public function __construct(
        private CycleLog $log,
        private FormGate $gate,
    ) {
    }

    public function run(CycleContext $ctx, string $archetype, int $year): void
    {
        $this->log->enterPeriod("inventory & master data {$year}");
        $this->log->note('Kits, reservations, projects and the settings everything depends on.');

        $this->masterData($ctx, $year);
        $this->kits($ctx, $year);
        $this->reservations($ctx, $year);
        $this->projects($ctx, $year);
        $this->supplierCreditNote($ctx, $year);
        $this->balanceScreens($ctx);
    }

    // ══════════════════════════════════════════════════════════════
    //  Settings
    // ══════════════════════════════════════════════════════════════

    private function masterData(CycleContext $ctx, int $year): void
    {
        $verify = new Verify($this->log, $ctx->companyId);

        // ── Cost centres ──────────────────────────────────────────
        $costCentre = $this->gate->submit(
            \App\Http\Requests\Settings\CostCenterRequest::class,
            ['name_en' => 'Cairo Operations', 'name_ar' => 'عمليات القاهرة'],
            fn (array $data) => app(CostCenterService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Create a cost centre',
            'cost centre'
        );

        if ($costCentre) {
            $this->gate->submit(
                \App\Http\Requests\Settings\CostCenterRequest::class,
                ['name_en' => 'Cairo — Warehouse', 'parent_id' => $costCentre->id],
                fn (array $data) => app(CostCenterService::class)->create($ctx->companyId, $data, $ctx->actor),
                'Create a child cost centre',
                'cost centre'
            );

            $ctx->costCenterId = $costCentre->id;
        }

        // ── Payment terms ─────────────────────────────────────────
        // A term whose instalments do not add up to the whole invoice
        // is the classic settings-screen defect: it looks saved and
        // silently under-bills every invoice that uses it.
        $this->gate->submit(
            \App\Http\Requests\Settings\PaymentTermRequest::class,
            [
                'code'    => 'CYC30-60',
                'name_en' => '30/60 split',
                'lines'   => [
                    ['percentage' => 50, 'due_days' => 30, 'reference_point' => 'invoice_date', 'label' => 'First half'],
                    ['percentage' => 50, 'due_days' => 60, 'reference_point' => 'invoice_date', 'label' => 'Balance'],
                ],
            ],
            fn (array $data) => app(PaymentTermService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Create a two-instalment payment term',
            'payment term'
        );

        $this->gate->expectRefused(
            fn () => app(PaymentTermService::class)->create($ctx->companyId, [
                'code'    => 'CYC-BAD',
                'name_en' => 'Adds up to 80%',
                'lines'   => [
                    ['percentage' => 40, 'due_days' => 30, 'reference_point' => 'invoice_date'],
                    ['percentage' => 40, 'due_days' => 60, 'reference_point' => 'invoice_date'],
                ],
            ], $ctx->actor),
            '/100/',
            'A payment term whose instalments do not add up to the whole invoice',
            'the instalments of a payment term must account for all of it'
        );

        // ── Locations, channels, deduction reasons ────────────────
        $this->gate->submit(
            \App\Http\Requests\Settings\InventoryLocationRequest::class,
            ['name_en' => 'Overflow Store '.$year],
            fn (array $data) => app(InventoryLocationService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Create an inventory location',
            'inventory location'
        );

        app(SalesChannelService::class)->quickCreate($ctx->companyId, ['name_en' => 'Direct Sales']);
        $this->log->operation('sales channel');

        $this->gate->submit(
            \App\Http\Requests\Accounting\DeductionReasonRequest::class,
            ['name_en' => 'Unauthorised absence', 'behavior' => 'earned_reduction'],
            fn (array $data) => app(DeductionReasonService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Create a payroll deduction reason',
            'deduction reason'
        );

        // ── Tax ───────────────────────────────────────────────────
        // The rate a product is taxed at is resolved through an
        // override chain, and getting it wrong misstates VAT on every
        // invoice — so the resolution itself is checked, not just the
        // save.
        $settings = app(TaxService::class)->getOrCreateSettings($ctx->companyId);
        $this->log->operation('tax settings');

        $product = $ctx->sellableProducts()[0] ?? null;

        if ($product) {
            $defaultRate = app(TaxService::class)->resolveVatRate($ctx->companyId, $product->id);

            app(TaxService::class)->saveOverride($ctx->companyId, [
                'product_id' => $product->id,
                'vat_rate'   => '5',
            ], $ctx->actor);
            $this->log->operation('tax override');

            $verify->unitCost(
                '5.0000',
                app(TaxService::class)->resolveVatRate($ctx->companyId, $product->id),
                'A product-level VAT override is not applied when the rate is resolved',
                'Tax settings',
                ['company default' => $defaultRate, 'override' => '5'],
                prefix: 'TAX'
            );
        }

        // ── Exchange rates ────────────────────────────────────────
        if ($ctx->foreignCurrencyId) {
            app(CurrencyService::class)->addRate($ctx->companyId, [
                'currency_id' => $ctx->foreignCurrencyId,
                'rate'        => '31.500000',
                'rate_date'   => $year.'-06-30',
            ], $ctx->actor);
            $this->log->operation('exchange rate');
        }

        // ── Job order templates ───────────────────────────────────
        if (count($ctx->rawMaterials) >= 2 && $ctx->finishedGoods !== []) {
            $this->gate->submit(
                \App\Http\Requests\Accounting\JobOrderTemplateRequest::class,
                [
                    'name'       => 'Standard cabinet build',
                    'product_id' => $ctx->finishedGoods[0]->id,
                    'is_active'  => true,
                    'lines'      => [
                        [
                            'component_product_id' => $ctx->rawMaterials[0]->id,
                            'quantity_per_unit'    => '2',
                            'uom_id'               => $ctx->rawMaterials[0]->primary_uom_id,
                        ],
                        [
                            'component_product_id' => $ctx->rawMaterials[1]->id,
                            'quantity_per_unit'    => '1',
                            'uom_id'               => $ctx->rawMaterials[1]->primary_uom_id,
                        ],
                    ],
                ],
                fn (array $data) => app(JobOrderTemplateService::class)->create($ctx->companyId, $data, $ctx->actor),
                'Create a job order template',
                'job order template'
            );
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Kits
    // ══════════════════════════════════════════════════════════════

    /**
     * A kit is two products packed as one. Assembling consumes the
     * components at their weighted average and receives the kit at
     * the total of what it consumed — so the kit's cost is fully
     * determined before the operation runs, and the cycle states it
     * in advance rather than reading it back afterwards.
     */
    private function kits(CycleContext $ctx, int $year): void
    {
        $sellable = $ctx->sellableProducts();

        if (count($sellable) < 3) {
            return;
        }

        $verify = new Verify($this->log, $ctx->companyId);

        [$kitProduct, $componentA, $componentB] = [$sellable[2], $sellable[0], $sellable[1]];

        $recipe = $this->gate->submit(
            \App\Http\Requests\Accounting\KitRecipeRequest::class,
            [
                'product_id' => $kitProduct->id,
                'notes'      => 'Two-part bundle',
                'lines'      => [
                    ['component_product_id' => $componentA->id, 'quantity_per_kit' => '2'],
                    ['component_product_id' => $componentB->id, 'quantity_per_kit' => '1'],
                ],
            ],
            fn (array $data) => app(KitRecipeService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Define a kit recipe',
            'kit recipe'
        );

        if (! $recipe) {
            return;
        }

        $quantity = '4';
        $needA    = bcmul('2', $quantity, 4);
        $needB    = bcmul('1', $quantity, 4);

        if (! $ctx->book->canIssue($componentA->id, $needA, $ctx->masterLocationId)
            || ! $ctx->book->canIssue($componentB->id, $needB, $ctx->masterLocationId)) {
            $this->log->note('  (not enough component stock to assemble a kit this year)');

            return;
        }

        // What the assembly must cost, worked out from the cycle's own
        // book before the application is asked.
        $expectedCost = bcadd(
            $ctx->book->costOfIssuing($componentA->id, $needA),
            $ctx->book->costOfIssuing($componentB->id, $needB),
            2
        );

        $date = $year.'-08-12';

        $assembly = $this->gate->submit(
            null,
            [],
            fn () => app(KitAssemblyService::class)->assemble(
                $ctx->companyId,
                $recipe->fresh('lines'),
                $quantity,
                $ctx->masterLocationId,
                $ctx->actor,
                $date
            ),
            'Assemble a kit — '.$year,
            'kit assembly'
        );

        if (! $assembly) {
            return;
        }

        $ctx->book->issue($componentA->id, $needA, $ctx->masterLocationId);
        $ctx->book->issue($componentB->id, $needB, $ctx->masterLocationId);
        $ctx->book->receive(
            $kitProduct->id,
            $quantity,
            bcdiv($expectedCost, $quantity, 4),
            $ctx->masterLocationId
        );

        // A kit must be worth exactly what went into it — no more, no
        // less. Anything else is value created or destroyed by packing.
        $verify->inventory(
            $kitProduct->id,
            $ctx->book->quantity($kitProduct->id),
            $ctx->book->value($kitProduct->id),
            $ctx->book->wac($kitProduct->id),
            $ctx->book->name($kitProduct->id),
            'Kit assembly — '.$year
        );

        $verify->ledgerBalanced('Kit assembly — '.$year);
        $verify->inventoryReconcilesToLedger('Kit assembly — '.$year);

        // Assembling more kits than there are components for must be
        // refused rather than driving stock negative.
        $this->gate->expectRefused(
            fn () => app(KitAssemblyService::class)->assemble(
                $ctx->companyId,
                $recipe->fresh('lines'),
                '100000',
                $ctx->masterLocationId,
                $ctx->actor,
                $date
            ),
            '/insufficient|not enough|available/i',
            'Assemble more kits than there are components for',
            'a kit cannot be built out of stock that does not exist'
        );

        // An inactive recipe must not be assemblable.
        app(KitRecipeService::class)->toggleActive($ctx->companyId, $recipe->fresh(), $ctx->actor);

        $this->gate->expectRefused(
            fn () => app(KitAssemblyService::class)->assemble(
                $ctx->companyId,
                $recipe->fresh('lines'),
                '1',
                $ctx->masterLocationId,
                $ctx->actor,
                $date
            ),
            '/inactive/i',
            'Assemble a kit from a recipe that has been retired',
            'a retired recipe must not still be usable'
        );

        app(KitRecipeService::class)->toggleActive($ctx->companyId, $recipe->fresh(), $ctx->actor);

        $this->log->note("Kit assembled: {$quantity} units at a total component cost of {$expectedCost}.");
    }

    // ══════════════════════════════════════════════════════════════
    //  Stock reservations
    // ══════════════════════════════════════════════════════════════

    /**
     * Reserving stock against a customer order promises it to that
     * customer. The promise is only worth anything if the same line
     * cannot be reserved twice.
     */
    private function reservations(CycleContext $ctx, int $year): void
    {
        $order = SalesOrder::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('status', 'active')
            ->with('lines')
            ->orderByDesc('id')
            ->first();

        $line = $order?->lines->first();

        if (! $line) {
            return;
        }

        $reservation = $this->gate->submit(
            null,
            [],
            fn () => app(StockReservationService::class)->reserve($ctx->companyId, $line, $ctx->actor),
            'Reserve stock for a customer order — '.$year,
            'stock reservation'
        );

        if (! $reservation) {
            return;
        }

        $this->gate->expectRefused(
            fn () => app(StockReservationService::class)->reserve($ctx->companyId, $line->fresh(), $ctx->actor),
            '/already has an active reservation/i',
            'Reserve the same order line twice',
            'the same stock cannot be promised to two people'
        );

        $verify = new Verify($this->log, $ctx->companyId);

        $reserved = app(StockReservationService::class)->reservedQuantitiesByProduct($ctx->companyId);

        $verify->quantity(
            (string) $line->quantity,
            (string) ($reserved[$line->product_id] ?? '0'),
            'The reserved quantity report does not show the reservation that was just made',
            'Stock reservation — '.$year,
            [],
            prefix: 'INV'
        );

        $this->gate->submit(
            null,
            [],
            fn () => app(StockReservationService::class)->release($reservation->fresh(), $ctx->actor, 'Delivered'),
            'Release a stock reservation — '.$year,
            'stock reservation release'
        );

        // Releasing twice must be refused too.
        $this->gate->expectRefused(
            fn () => app(StockReservationService::class)->release($reservation->fresh(), $ctx->actor),
            '/active/i',
            'Release a reservation that is already released',
            'a released reservation cannot be released again'
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  Projects
    // ══════════════════════════════════════════════════════════════

    private function projects(CycleContext $ctx, int $year): void
    {
        $project = $this->gate->submit(
            \App\Http\Requests\Projects\ProjectRequest::class,
            [
                'name_en'        => 'Nile Tower Fit-Out '.$year,
                'partner_id'     => $ctx->customers[0]->id,
                'contract_value' => '2500000.00',
                'currency_id'    => $ctx->currencyId,
                'exchange_rate'  => 1,
                'start_date'     => $year.'-02-01',
                'end_date'       => $year.'-12-15',
            ],
            fn (array $data) => app(ProjectService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Open a project — '.$year,
            'project'
        );

        if (! $project) {
            return;
        }

        // A project gets its own stock location, and its inventory
        // statement must run against it even while it holds nothing.
        try {
            app(ProjectInventoryService::class)->statement($ctx->companyId, $project->id, null);
            $this->log->operation('project inventory statement');
            $this->log->checkPassed();
        } catch (\Throwable $e) {
            $this->log->finding(
                prefix: 'RPT',
                severity: Finding::SEVERITY_MEDIUM,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'The project inventory statement could not be produced',
                operation: 'Project inventory — '.$year,
                expected: 'a statement for a project that holds no stock yet',
                actual: get_class($e).': '.$e->getMessage(),
                source: 'ProjectInventoryService::statement()',
            );
        }

        $this->gate->submit(
            null,
            [],
            fn () => app(ProjectService::class)->changeStatus($ctx->companyId, $project->fresh(), 'active', $ctx->actor),
            'Activate a project — '.$year,
            'project status change'
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  Supplier credit notes
    // ══════════════════════════════════════════════════════════════

    /**
     * The supplier's own credit note against a bill we have already
     * booked. Its effect is the mirror image of a customer credit
     * note: it must reduce what we owe.
     */
    private function supplierCreditNote(CycleContext $ctx, int $year): void
    {
        $verify = new Verify($this->log, $ctx->companyId);
        $bill   = null;

        // Only a NON-goods bill. SupplierCreditNoteService refuses a
        // goods line outright with an explicit "needs a dedicated
        // design for how it interacts with Weighted Average Cost"
        // message — a documented limitation, not a defect, so the
        // cycle works within it rather than reporting it as a failure.
        // The cycle's tracked bills are all goods bills, so look the
        // non-goods ones up directly — the prepaid and expense bills
        // the year produced are the only ones this service accepts.
        $candidates = \App\Models\SupplierBill::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('status', 'posted')
            ->whereNot('bill_type', 'goods')
            ->with('lines')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $candidate) {
            if ($candidate->lines->isNotEmpty()) {
                $bill = $candidate;
                break;
            }
        }

        if (! $bill) {
            return;
        }

        $line   = $bill->lines->first();
        $before = (string) $bill->amount_outstanding;
        $credit = '250.00';

        $note = $this->gate->submit(
            \App\Http\Requests\Accounting\SupplierCreditNoteRequest::class,
            [
                'supplier_bill_id' => $bill->id,
                'credit_date'      => $year.'-10-08',
                'credit_reason'    => 'Agreed price adjustment',
                'lines'            => [[
                    'supplier_bill_line_id' => $line->id,
                    'description'           => 'Price adjustment',
                    'quantity'              => '1',
                    'unit_price'            => $credit,
                    'vat_rate'              => 0,
                ]],
            ],
            fn (array $data) => app(SupplierCreditNoteService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Supplier credit note — '.$year,
            'supplier credit note'
        );

        if (! $note) {
            return;
        }

        $posted = $this->gate->submit(
            null,
            [],
            fn () => app(SupplierCreditNoteService::class)->post($note->fresh(), $ctx->actor),
            'Post a supplier credit note — '.$year,
            'supplier credit note posting'
        );

        if (! $posted) {
            return;
        }

        // A credit note against a bill that still has a balance must
        // reduce it. Against one already settled there is nothing left
        // to reduce, and the check would be meaningless — so it is
        // made conditional rather than dropped.
        if (bccomp($before, $credit, 2) >= 0) {
            $verify->money(
                bcsub($before, $credit, 2),
                (string) $bill->fresh()->amount_outstanding,
                'A posted supplier credit note did not reduce what the company owes',
                'Supplier credit note — '.$year,
                ['bill before' => $before, 'credit' => $credit],
                prefix: 'ACC'
            );
        }

        $entry = $verify->journalEntryFor('supplier_credit_note', $posted->id, 'Supplier credit note');

        if ($entry) {
            $verify->entryBalances($entry, 'Supplier credit note');
            $verify->entryDated($entry, $year.'-10-08', 'Supplier credit note');
        }

        $verify->ledgerBalanced('Supplier credit note — '.$year);
    }

    // ══════════════════════════════════════════════════════════════
    //  The balance screens
    // ══════════════════════════════════════════════════════════════

    /**
     * Two screens that summarise money owed in both directions —
     * employee advances and the government's share. Neither posts
     * anything; both are read by people who then act on the number.
     */
    private function balanceScreens(CycleContext $ctx): void
    {
        foreach ([
            'employee advances'     => fn () => app(AdvancesSettlementsService::class)
                ->balancesForPartner($ctx->companyId, $ctx->employees[0]->id),
            'advances history'      => fn () => app(AdvancesSettlementsService::class)
                ->history($ctx->companyId, $ctx->employees[0]->id),
            'government remittance' => fn () => app(GovernmentRemittanceService::class)->balances($ctx->companyId),
            'remittance history'    => fn () => app(GovernmentRemittanceService::class)->history($ctx->companyId),
        ] as $name => $build) {
            try {
                $build();
                $this->log->operation('balance screen');
                $this->log->checkPassed();
            } catch (\Throwable $e) {
                $this->log->finding(
                    prefix: 'RPT',
                    severity: Finding::SEVERITY_MEDIUM,
                    confidence: Finding::CONFIDENCE_CONFIRMED,
                    title: "The {$name} screen could not be produced",
                    operation: 'Balance screens',
                    expected: 'a balance summary for a company with a year of payroll behind it',
                    actual: get_class($e).': '.$e->getMessage(),
                );
            }
        }
    }
}
