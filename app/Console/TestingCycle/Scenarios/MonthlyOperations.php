<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  MonthlyOperations
//
//  One month of trading, month after month, year after year — and a
//  verification after every step.
//
//  The month runs in the order a real company works in, because the
//  order is what makes the transactions depend on each other:
//
//      buy  →  receive  →  bill        (stock and a payable exist)
//        ↓
//      produce                          (consumes the stock just bought)
//        ↓
//      sell →  deliver  →  invoice      (relieves stock, raises a receivable)
//        ↓
//      collect                          (settles that receivable into a bank)
//        ↓
//      pay suppliers                    (settles those payables from a bank)
//        ↓
//      expenses, payroll, HR
//
//  EVERY FIGURE IS FIXED. No randomness anywhere: quantities, costs
//  and prices are constants that step predictably by year, chosen so
//  the weighted average and every journal entry can be worked out by
//  hand. A cycle that cannot be reproduced cannot be debugged.
//
//  THE CYCLE KEEPS ITS OWN INVENTORY. Every receipt and issue is
//  applied to CycleContext::$book using the documented weighted
//  average rule, and the application is checked against that — never
//  the other way round.
//
//  NOTHING IS WORKED AROUND. If a step fails, the finding is recorded
//  and the month continues with whatever is still safe to do. The
//  cycle never reduces a quantity or tops up a bank balance to keep
//  itself green.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Verify;
use App\Http\Requests\Accounting\ExpenseClaimRequest;
use App\Http\Requests\Accounting\LeaveRequestRequest;
use App\Models\ExpenseClaim;
use App\Models\OverheadPool;
use App\Services\Accounting\CashExpensePaymentService;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\CustomerPaymentService;
use App\Services\Accounting\DeliveryNoteService;
use App\Services\Accounting\ExpenseClaimService;
use App\Services\Accounting\GoodsReceiptNoteService;
use App\Services\Accounting\LeaveRequestService;
use App\Services\Accounting\OtherCollectionService;
use App\Services\Accounting\ProductionOrderService;
use App\Services\Accounting\PurchaseOrderService;
use App\Services\Accounting\SupplierBillService;
use App\Services\Accounting\SupplierPaymentService;
use App\Services\Projects\SalesOrderService;
use Carbon\Carbon;

use App\Console\TestingCycle\Scenarios\AssetsAndPayroll;
use App\Console\TestingCycle\Scenarios\JobOrders;
use App\Console\TestingCycle\Scenarios\Lifecycles;
use App\Console\TestingCycle\Scenarios\NotesAndAdjustments;
use App\Console\TestingCycle\Scenarios\Treasury;

class MonthlyOperations
{
    /** Reported once per run, not once per month. */
    private static bool $reportedBomFieldDivergence = false;

    /** VAT and withholding, fixed so every tax figure is checkable. */
    private const VAT_RATE = 14.0;
    private const WHT_RATE = 0.0;

    /** So the settlement-currency trap is reported once, not monthly. */
    private bool $reportedSettlementTrap = false;

    private Treasury $treasury;
    private AssetsAndPayroll $assets;
    private NotesAndAdjustments $notes;
    private JobOrders $jobOrders;
    private Lifecycles $lifecycles;

    public function __construct(
        private CycleLog $log,
        private FormGate $gate,
    ) {
        $this->treasury  = new Treasury($log, $gate);
        $this->assets    = new AssetsAndPayroll($log, $gate);
        $this->notes     = new NotesAndAdjustments($log, $gate);
        $this->jobOrders = new JobOrders($log, $gate);
        $this->lifecycles = new Lifecycles($log, $gate);
    }

    public function runYear(CycleContext $ctx, string $archetype, int $year, int $yearIndex): void
    {
        $verify = new Verify($this->log, $ctx->companyId);

        // The company needs money before it can buy anything. Year one
        // only — after that it funds itself out of collections, which
        // is what makes the later years a real test of the cash cycle.
        if ($yearIndex === 0) {
            $this->injectCapital($ctx, $year);

            // Financing, payroll and a second currency are arranged in
            // the first year and then simply exist, like a real company.
            $this->treasury->setUp($ctx, $year);
            $this->assets->setUpPayroll($ctx, $archetype === Setup::ARCHETYPE_MANUFACTURING);
            $this->notes->enableForeignCurrency($ctx, $year);
        } else {
            // Every later year opens with a pay review.
            $this->assets->annualSalaryRevision($ctx, Carbon::create($year, 1, 1));
        }

        // One capital purchase a year, so the asset register builds a
        // real portfolio and depreciation runs over a long history.
        $this->assets->buyFixedAsset($ctx, Carbon::create($year, 2, 5), $yearIndex);

        for ($month = 1; $month <= 12; $month++) {
            $monthStart = Carbon::create($year, $month, 1);
            $this->log->enterPeriod($monthStart->format('M Y'));
            $this->log->note($monthStart->format('F Y'));

            $this->purchaseCycle($ctx, $archetype, $monthStart, $year, $month);

            if ($archetype === Setup::ARCHETYPE_MANUFACTURING) {
                $this->productionCycle($ctx, $monthStart, $month);
            }

            $this->salesCycle($ctx, $archetype, $monthStart, $month);
            $this->collectionCycle($ctx, $monthStart);
            $this->supplierPaymentCycle($ctx, $monthStart);
            $this->expenseCycle($ctx, $monthStart);

            // Financing activity, depreciation and payroll happen every
            // month in every real company, so they happen here too.
            $this->treasury->monthly($ctx, $monthStart, $month);
            $this->assets->postDepreciation($ctx, $monthStart);
            $this->assets->runPayroll($ctx, $monthStart);

            // Corrections and irregulars, spread across the year.
            $this->notes->accrueExpense($ctx, $monthStart);

            if ($month % 4 === 0) {
                $this->notes->customerCreditNote($ctx, $monthStart);
                $this->notes->customerDebitNote($ctx, $monthStart);
                $this->notes->supplierDebitNote($ctx, $monthStart);
            }

            if ($month % 5 === 0) {
                $this->notes->stockAdjustment($ctx, $monthStart);
            }

            if ($month % 6 === 2) {
                $this->notes->foreignCurrencySale($ctx, $monthStart);
            }

            if ($month === 1) {
                $this->notes->prepaidBill($ctx, $monthStart);
            }

            if ($month % 3 === 2) {
                $this->notes->employeeAdvance($ctx, $monthStart);
            }

            if ($month % 4 === 1) {
                $this->notes->collectByCheque($ctx, $monthStart);
            }

            if ($month % 6 === 4) {
                $this->notes->internalSettlement($ctx, $monthStart);
            }

            if ($month % 5 === 3) {
                $this->notes->payByCheque($ctx, $monthStart);
            }

            // Make-to-order manufacturing, alongside mass production.
            if ($archetype === Setup::ARCHETYPE_MANUFACTURING && $month % 4 === 3) {
                $this->jobOrders->run($ctx, $monthStart);
            }

            // The second half of every instrument opened above: cheques
            // clear or bounce, leases and prepayments amortise,
            // factored invoices settle, guarantees are released or
            // called in. This is where the money actually moves.
            $this->lifecycles->monthly($ctx, $monthStart, $month);

            // Manufacturing variance closing sweeps whatever standard
            // costing left in work in progress. Monthly, for a
            // manufacturer only — there is nothing to sweep otherwise.
            if ($archetype === Setup::ARCHETYPE_MANUFACTURING) {
                $this->closeManufacturingVariance($ctx, $monthStart);
            }

            // End-of-service provision, once a year.
            if ($month === 12) {
                $this->assets->runEosbProvision($ctx, $monthStart);
            }

            // HR runs quarterly — enough to exercise the workflow
            // across the history without dominating the run.
            if ($month % 3 === 0) {
                $this->hrCycle($ctx, $monthStart, $month);
            }

            // A transfer between the two warehouses, twice a year.
            if ($month === 6 || $month === 11) {
                $this->warehouseTransfer($ctx, $monthStart);
            }

            // The month must leave the books internally consistent.
            $verify->ledgerBalanced($monthStart->format('M Y').' month-end');
            $verify->noNegativeStock($monthStart->format('M Y').' month-end');
            $verify->inventoryReconcilesToLedger($monthStart->format('M Y').' month-end');
        }
    }


    /**
     * Compare the application against the cycle's own book, and — if
     * they have diverged — record it once and then re-base so the rest
     * of the run keeps testing new movements instead of re-reporting
     * the same historical break every month.
     *
     * The finding is always raised first. Nothing is hidden.
     */
    private function verifyInventory(CycleContext $ctx, Verify $verify, int $productId, string $operation): void
    {
        $matched = $verify->inventory(
            $productId,
            $ctx->book->quantity($productId),
            $ctx->book->value($productId),
            $ctx->book->wac($productId),
            $ctx->book->name($productId),
            $operation
        );

        if ($matched) {
            return;
        }

        $actual = $verify->inventoryState($productId);

        $this->log->note(
            '  (re-basing the cycle\'s book for '.$ctx->book->name($productId)
            .' onto the application\'s figures — the divergence above is recorded; '
            .'later months test new movements from here)'
        );

        $ctx->book->resyncTo($productId, $actual['quantity'], $actual['value'], $actual['wac']);
        $ctx->book->resyncLocation($productId, $ctx->masterLocationId, $actual['quantity']);
    }

    // ══════════════════════════════════════════════════════════════
    //  Capital
    // ══════════════════════════════════════════════════════════════

    private function injectCapital(CycleContext $ctx, int $year): void
    {
        $verify = new Verify($this->log, $ctx->companyId);
        $bank   = $ctx->bankAccounts[0];
        $amount = '5000000.00';

        $before = $verify->trueBalance((int) $bank->coa_account_id);

        $collection = $this->gate->submit(
            \App\Http\Requests\Accounting\OtherCollectionRequest::class,
            [
                'partner_id'                  => $ctx->shareholders[0]->id,
                'partner_type'                => 'shareholder',
                'collection_date'             => $year.'-01-02',
                'money_type'                  => 'bank_deposit',
                'currency_id'                 => $ctx->currencyId,
                'amount'                      => $amount,
                'destination_type'            => 'bank_account',
                'destination_bank_account_id' => $bank->id,
                'credit_account_id'           => $verify->accountId('311'),
            ],
            fn (array $data) => app(OtherCollectionService::class)->record($ctx->companyId, $data, $ctx->actor),
            'Shareholder capital injection',
            'capital injection'
        );

        if (! $collection) {
            return;
        }

        // The bank must have risen by exactly what was put in.
        $verify->money(
            bcadd($before, $amount, 2),
            $verify->trueBalance((int) $bank->coa_account_id),
            'Capital injection did not reach the bank account',
            'Shareholder capital injection',
            ['bank' => $bank->account_name],
            prefix: 'BNK'
        );

        $entry = $verify->journalEntryFor('other_collection', $collection->id, 'Shareholder capital injection');
        if ($entry) {
            $verify->entryBalances($entry, 'Shareholder capital injection');
            $verify->entryDated($entry, $year.'-01-02', 'Shareholder capital injection');
        }

        $this->log->note('Capital injected: '.number_format((float) $amount).' EGP.');
    }

    // ══════════════════════════════════════════════════════════════
    //  Purchase  →  receipt  →  bill
    // ══════════════════════════════════════════════════════════════

    private function purchaseCycle(CycleContext $ctx, string $archetype, Carbon $monthStart, int $year, int $month): void
    {
        $verify   = new Verify($this->log, $ctx->companyId);
        $supplier = $ctx->rotate($ctx->suppliers, $month);
        $date     = $monthStart->copy()->addDays(1)->toDateString();

        $products = $archetype === Setup::ARCHETYPE_MANUFACTURING
            ? $ctx->rawMaterials
            : $ctx->tradeGoods;

        // Costs step by 10% each year — enough to make the weighted
        // average genuinely move, in round figures.
        $yearFactor = 1 + (($year - (int) $monthStart->copy()->startOfYear()->format('Y')) * 0);
        $costStep   = $this->costStepFor($ctx, $monthStart->year);

        $poLines  = [];
        $expected = [];

        foreach ($products as $product) {
            $unitCost = bcmul($ctx->cost($product->id), $costStep, 2);
            $quantity = '100';

            $poLines[] = [
                'product_id'  => $product->id,
                'description' => $product->name_en,
                'quantity'    => $quantity,
                'uom_id'      => $product->primary_uom_id,
                'unit_price'  => $unitCost,
            ];

            $expected[$product->id] = ['qty' => $quantity, 'cost' => $unitCost];
        }

        $po = $this->gate->submit(
            \App\Http\Requests\Accounting\PurchaseOrderRequest::class,
            [
                'partner_id'      => $supplier->id,
                'subject'         => 'Monthly restock — '.$monthStart->format('F Y'),
                'currency_id'     => $ctx->currencyId,
                'exchange_rate'   => 1,
                'issuance_date'   => $date,
                'start_date'      => $date,
                'end_date'        => $monthStart->copy()->endOfMonth()->toDateString(),
                'payment_term_id' => $ctx->paymentTermId,
                'lines'           => $poLines,
            ],
            fn (array $data) => app(PurchaseOrderService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Purchase order — '.$monthStart->format('M Y'),
            'purchase order'
        );

        if (! $po) {
            return;
        }

        app(PurchaseOrderService::class)->activate($po, $ctx->actor);

        // ── Goods receipt ─────────────────────────────────────────
        $grnLines = [];
        foreach ($po->lines as $line) {
            $grnLines[] = [
                'purchase_order_line_id' => $line->id,
                'product_id'             => $line->product_id,
                'quantity'               => $line->quantity,
                'uom_id'                 => $line->uom_id,
                'unit_cost'              => $line->unit_price,
            ];
        }

        $grn = $this->gate->submit(
            \App\Http\Requests\Accounting\GoodsReceiptNoteRequest::class,
            [
                'purchase_order_id'     => $po->id,
                'partner_id'            => $supplier->id,
                'inventory_location_id' => $ctx->masterLocationId,
                'received_date'         => $date,
                'lines'                 => $grnLines,
            ],
            fn (array $data) => app(GoodsReceiptNoteService::class)->post(
                app(GoodsReceiptNoteService::class)->create($ctx->companyId, $data, $ctx->actor),
                $ctx->actor
            ),
            'Goods receipt — '.$monthStart->format('M Y'),
            'goods receipt note'
        );

        if (! $grn) {
            return;
        }

        // The cycle's own book moves first, then the application is
        // checked against it.
        $receiptValue = '0.00';
        foreach ($expected as $productId => $line) {
            $ctx->book->receive($productId, $line['qty'], $line['cost'], $ctx->masterLocationId);
            $receiptValue = bcadd($receiptValue, bcmul($line['qty'], $line['cost'], 2), 2);

            $this->verifyInventory($ctx, $verify, $productId, 'Goods receipt — '.$monthStart->format('M Y'));
        }

        $entry = $verify->journalEntryFor('goods_receipt_note', $grn->id, 'Goods receipt — '.$monthStart->format('M Y'));
        if ($entry) {
            $verify->entryBalances($entry, 'Goods receipt');
            $verify->entryDated($entry, $date, 'Goods receipt');
            // Receiving parks the liability in GRNI and must not touch
            // accounts payable — that is the whole point of 233.
            $verify->entryPosts($entry, '233', '0.00', $receiptValue, 'Goods receipt');
        }
        $verify->entryCountFor('goods_receipt_note', $grn->id, 1, 'Goods receipt');

        // ── Supplier bill ─────────────────────────────────────────
        $billLines = [];
        foreach ($grn->lines as $line) {
            $billLines[] = [
                'goods_receipt_note_line_id' => $line->id,
                'product_id'                 => $line->product_id,
                'description'                => $line->product?->name_en ?? 'Goods',
                'quantity'                   => $line->quantity,
                'uom_id'                     => $line->uom_id,
                'unit_price'                 => $line->unit_cost,
                'vat_rate'                   => self::VAT_RATE,
                'is_deductible'              => true,
                'withholding_tax_rate'       => self::WHT_RATE,
            ];
        }

        $grniBefore = $verify->trueBalanceByCode('233');

        $bill = $this->gate->submit(
            \App\Http\Requests\Accounting\SupplierBillRequest::class,
            [
                'bill_type'               => 'goods',
                'partner_id'              => $supplier->id,
                'purchase_order_id'       => $po->id,
                'supplier_invoice_number' => 'SUP-'.$monthStart->format('Ym').'-'.$ctx->companyId,
                'supplier_invoice_date'   => $date,
                'bill_date'               => $date,
                'currency_id'             => $ctx->currencyId,
                'payment_term_id'         => $ctx->paymentTermId,
                'lines'                   => $billLines,
            ],
            fn (array $data) => app(SupplierBillService::class)->post(
                app(SupplierBillService::class)->create($ctx->companyId, $data, $ctx->actor),
                $ctx->actor
            ),
            'Supplier bill — '.$monthStart->format('M Y'),
            'supplier bill'
        );

        if (! $bill) {
            return;
        }

        $ctx->openSupplierBills[] = $bill;

        // Billing must clear exactly what the receipt parked: GRNI
        // returns to where it was before this receipt.
        // The receipt credited 233 (a negative signed balance); billing
        // debits it back, so a correctly cleared GRNI ADDS the goods
        // value back onto where it stood before the bill.
        $verify->money(
            bcadd($grniBefore, $receiptValue, 2),
            $verify->trueBalanceByCode('233'),
            'GRNI did not clear when the goods were billed',
            'Supplier bill — '.$monthStart->format('M Y'),
            ['GRNI before billing' => $grniBefore, 'goods value' => $receiptValue],
            prefix: 'ACC'
        );

        // Freight on the import, twice a year, capitalised against
        // the receipt it arrived with.
        if (in_array((int) $monthStart->format('n'), [3, 9], true)) {
            $this->notes->landingCost($ctx, $monthStart, $grn);
        }

        $billEntry = $verify->journalEntryFor('supplier_bill', $bill->id, 'Supplier bill');
        if ($billEntry) {
            $verify->entryBalances($billEntry, 'Supplier bill');
            // VAT input at the stated rate, calculated independently.
            $verify->entryPosts($billEntry, '137', bcmul($receiptValue, '0.14', 2), '0.00', 'Supplier bill');
        }
    }

    /** Purchase costs step up over the years, in round multiples. */
    private function costStepFor(CycleContext $ctx, int $year): string
    {
        static $base = null;
        $base ??= $year;

        return match ($year - $base) {
            0       => '1.00',
            1       => '1.10',
            default => '1.20',
        };
    }

    // ══════════════════════════════════════════════════════════════
    //  Production
    // ══════════════════════════════════════════════════════════════

    private function productionCycle(CycleContext $ctx, Carbon $monthStart, int $month): void
    {
        $verify = new Verify($this->log, $ctx->companyId);
        $date   = $monthStart->copy()->addDays(8)->toDateString();

        $finishedGood = $ctx->rotate($ctx->finishedGoods, $month);
        $recipe       = $ctx->bomsByProduct[$finishedGood->id] ?? null;

        if (! $recipe) {
            return;
        }

        $quantity = '10';

        // Can the story actually happen? A real factory cannot consume
        // material it does not have — and pretending otherwise would
        // test nothing except the insufficient-stock guard, which the
        // negative scenarios cover deliberately.
        foreach ($recipe['recipe'] as [$rawIndex, $perUnit]) {
            $raw    = $ctx->rawMaterials[$rawIndex];
            $needed = bcmul((string) $perUnit, $quantity, 4);

            if (! $ctx->book->canIssue($raw->id, $needed, $ctx->masterLocationId)) {
                $this->log->note("Skipping production — not enough {$raw->name_en} on hand.");

                return;
            }
        }

        if (! self::$reportedBomFieldDivergence) {
            self::$reportedBomFieldDivergence = true;

            $this->log->finding(
                prefix: 'MFG',
                severity: Finding::SEVERITY_MEDIUM,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'The production order form requires a BOM the service refuses to use',
                operation: 'Production order — '.$monthStart->format('M Y'),
                expected: 'the form to ask only for what the operation actually needs',
                actual: 'bill_of_material_id is required, validated, and then discarded',
                source: 'ProductionOrderRequest::rules() vs ProductionOrderService::create()',
                rootCause: 'create() was changed to resolve the effective BOM from the order date via '
                    .'BillOfMaterialService::resolveEffective(), deliberately so nothing the client sends can '
                    .'override it. The required field in the Form Request was left behind. The visible effect '
                    .'is a user who picks BOM v2 on the form and silently gets v1 because v1 was the version '
                    .'effective on the order date — the form promises a choice the system does not honour.',
            );
        }

        $order = $this->gate->submit(
            \App\Http\Requests\Accounting\ProductionOrderRequest::class,
            [
                'product_id'               => $finishedGood->id,
                // The form still demands this even though the service
                // discards it and resolves the effective BOM from the
                // order date itself — recorded below as a form/service
                // divergence, supplied here so the order can be raised.
                'bill_of_material_id'      => $recipe['bom']->id,
                'quantity_to_produce'      => $quantity,
                'raw_material_location_id' => $ctx->masterLocationId,
                'output_location_id'       => $ctx->masterLocationId,
                'planned_start_date'       => $date,
            ],
            fn (array $data) => app(ProductionOrderService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Production order — '.$monthStart->format('M Y'),
            'production order'
        );

        if (! $order) {
            return;
        }

        app(ProductionOrderService::class)->start($order, $ctx->actor);

        // ── Consume ───────────────────────────────────────────────
        $materialCost = '0.00';

        foreach ($recipe['recipe'] as [$rawIndex, $perUnit]) {
            $raw    = $ctx->rawMaterials[$rawIndex];
            $needed = bcmul((string) $perUnit, $quantity, 4);

            // Book first: this is the value the application should
            // move out of inventory and into work in progress.
            $expectedRelief = $ctx->book->costOfIssuing($raw->id, $needed);

            try {
                app(ProductionOrderService::class)->consumeMaterial(
                    $order, $raw->id, $needed, $ctx->actor, $date
                );
                $this->log->operation('material consumption');
            } catch (\Throwable $e) {
                $this->log->finding(
                    prefix: 'MFG',
                    severity: Finding::SEVERITY_HIGH,
                    confidence: Finding::CONFIDENCE_CONFIRMED,
                    title: 'Consuming raw material failed during production',
                    operation: 'Production order — '.$monthStart->format('M Y'),
                    expected: "to consume {$needed} of {$raw->name_en}",
                    actual: get_class($e).': '.$e->getMessage(),
                );

                return;
            }

            $ctx->book->issue($raw->id, $needed, $ctx->masterLocationId);
            $materialCost = bcadd($materialCost, $expectedRelief, 2);

            $this->verifyInventory($ctx, $verify, $raw->id, 'Material consumption — '.$monthStart->format('M Y'));
        }

        // ── Labour and overhead, at the standard rates ────────────
        $laborHours   = bcmul($recipe['labor_hours'], $quantity, 2);
        $machineHours = bcmul($recipe['machine_hours'], $quantity, 2);

        try {
            app(ProductionOrderService::class)->recordLaborCost($order, $laborHours, $ctx->actor, $date);
            $this->log->operation('labour cost entry');

            $pool = OverheadPool::withoutGlobalScopes()->find($ctx->overheadPoolId);
            app(ProductionOrderService::class)->recordOverheadCost($order, $pool, $machineHours, $ctx->actor, $date);
            $this->log->operation('overhead cost entry');
        } catch (\Throwable $e) {
            $this->log->finding(
                prefix: 'MFG',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'Recording labour or overhead failed during production',
                operation: 'Production order — '.$monthStart->format('M Y'),
                expected: 'labour and overhead to post to work in progress',
                actual: get_class($e).': '.$e->getMessage(),
            );

            return;
        }

        // ── Complete ──────────────────────────────────────────────
        $order->refresh();

        // The finished goods value is the ORDER's own standard cost,
        // snapshotted when it was opened — not the actual material
        // relief. The difference is the manufacturing variance, which
        // the design deliberately leaves in work in progress.
        $standardUnit = bcadd(
            bcadd((string) $order->standard_material_cost_per_unit, (string) $order->standard_labor_cost_per_unit, 4),
            (string) $order->standard_overhead_cost_per_unit,
            4
        );
        $standardTotal = bcmul($standardUnit, $quantity, 2);

        $completed = $this->gate->submit(
            null,
            [],
            fn () => app(ProductionOrderService::class)->complete($order, $ctx->actor, $date),
            'Complete production — '.$monthStart->format('M Y'),
            'production completion'
        );

        if (! $completed) {
            return;
        }

        $ctx->book->receive($finishedGood->id, $quantity, $standardUnit, $ctx->masterLocationId);

        $this->verifyInventory($ctx, $verify, $finishedGood->id, 'Production completion — '.$monthStart->format('M Y'));

        $entry = $verify->journalEntryFor('production_order', $order->id, 'Production completion');
        if ($entry) {
            $verify->entryBalances($entry, 'Production completion');
            // WIP is relieved by exactly the standard cost.
            $verify->entryPosts($entry, '135002', '0.00', $standardTotal, 'Production completion');
        }

        $this->log->note("Produced {$quantity} × {$finishedGood->name_en} at standard {$standardUnit}/unit.");
    }

    // ══════════════════════════════════════════════════════════════
    //  Sales  →  delivery  →  invoice
    // ══════════════════════════════════════════════════════════════

    private function salesCycle(CycleContext $ctx, string $archetype, Carbon $monthStart, int $month): void
    {
        $verify   = new Verify($this->log, $ctx->companyId);
        $customer = $ctx->rotate($ctx->customers, $month);
        $soDate   = $monthStart->copy()->addDays(15)->toDateString();
        $dnDate   = $monthStart->copy()->addDays(18)->toDateString();

        $sellable = $ctx->sellableProducts();
        $lines    = [];
        $plan     = [];

        // A trader shifts most of what it buys; a manufacturer sells
        // most of what it built. Both leave a little stock behind, so
        // the weighted average has to carry forward between months.
        $quantity = $archetype === Setup::ARCHETYPE_MANUFACTURING ? '8' : '80';

        foreach ($sellable as $product) {

            // Only sell what the company actually owns. Selling air
            // would test the insufficient-stock guard, which the
            // negative scenarios do on purpose and by itself.
            // Location-specific: a delivery is refused when the
            // warehouse it draws from is short, even if the company
            // as a whole holds plenty.
            if (! $ctx->book->canIssue($product->id, $quantity, $ctx->masterLocationId)) {
                continue;
            }

            $lines[] = [
                'product_id'         => $product->id,
                'description'        => $product->name_en,
                'quantity'           => $quantity,
                'uom_id'             => $product->primary_uom_id,
                'unit_price'         => $ctx->price($product->id),
                'revenue_account_id' => $product->revenue_account_id,
            ];

            $plan[$product->id] = $quantity;
        }

        if ($lines === []) {
            $this->log->note('Nothing on hand to sell this month.');

            return;
        }

        $so = $this->gate->submit(
            \App\Http\Requests\Projects\SalesOrderRequest::class,
            [
                'partner_id'      => $customer->id,
                'subject'         => 'Monthly order — '.$monthStart->format('F Y'),
                'currency_id'     => $ctx->currencyId,
                'exchange_rate'   => 1,
                'issuance_date'   => $soDate,
                'start_date'      => $soDate,
                'end_date'        => $monthStart->copy()->endOfMonth()->toDateString(),
                'payment_term_id' => $ctx->paymentTermId,
                'lines'           => $lines,
            ],
            fn (array $data) => app(SalesOrderService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Sales order — '.$monthStart->format('M Y'),
            'sales order'
        );

        if (! $so) {
            return;
        }

        app(SalesOrderService::class)->activate($so, $ctx->actor);
        $so = $so->fresh('lines');

        // ── Delivery ──────────────────────────────────────────────
        $dnLines = [];
        foreach ($so->lines as $line) {
            $dnLines[] = [
                'sales_order_line_id' => $line->id,
                'location_id'         => $ctx->masterLocationId,
                'quantity'            => $line->quantity,
                'uom_id'              => $line->uom_id,
            ];
        }

        // The cost of sales this delivery should produce, computed
        // from the cycle's own averages before the application runs.
        $expectedCogs = '0.00';
        foreach ($plan as $productId => $quantity) {
            $expectedCogs = bcadd($expectedCogs, $ctx->book->costOfIssuing($productId, $quantity), 2);
        }

        $delivery = $this->gate->submit(
            \App\Http\Requests\Accounting\DeliveryNoteRequest::class,
            [
                'sales_order_id' => $so->id,
                'partner_id'     => $customer->id,
                'delivery_date'  => $dnDate,
                'lines'          => $dnLines,
            ],
            fn (array $data) => app(DeliveryNoteService::class)->post(
                app(DeliveryNoteService::class)->create($ctx->companyId, $data, $ctx->actor),
                $ctx->actor
            ),
            'Delivery note — '.$monthStart->format('M Y'),
            'delivery note'
        );

        if (! $delivery) {
            return;
        }

        $revenue = '0.00';
        foreach ($plan as $productId => $quantity) {
            $ctx->book->issue($productId, $quantity, $ctx->masterLocationId);
            $revenue = bcadd($revenue, bcmul($quantity, $ctx->price($productId), 2), 2);

            $this->verifyInventory($ctx, $verify, $productId, 'Delivery — '.$monthStart->format('M Y'));
        }

        // Revenue is recognised on DELIVERY in this system, into
        // accrued revenue (143), and the invoice only reclassifies it.
        $accruedBefore = $verify->trueBalanceByCode('143');

        // ── Invoice ───────────────────────────────────────────────
        $ciLines = [];
        foreach ($so->lines as $line) {
            $ciLines[] = [
                'sales_order_line_id' => $line->id,
                'product_id'          => $line->product_id,
                'description'         => $line->description,
                'quantity'            => $line->quantity,
                'uom_id'              => $line->uom_id,
                'unit_price'          => $line->unit_price,
                'vat_rate'            => self::VAT_RATE,
            ];
        }

        $invoice = $this->gate->submit(
            \App\Http\Requests\Accounting\CustomerInvoiceRequest::class,
            [
                'sales_order_id'  => $so->id,
                'partner_id'      => $customer->id,
                'invoice_date'    => $dnDate,
                'currency_id'     => $ctx->currencyId,
                'payment_term_id' => $ctx->paymentTermId,
                'lines'           => $ciLines,
            ],
            fn (array $data) => app(CustomerInvoiceService::class)->post(
                app(CustomerInvoiceService::class)->create($ctx->companyId, $data, $ctx->actor),
                $ctx->actor
            ),
            'Customer invoice — '.$monthStart->format('M Y'),
            'customer invoice'
        );

        if (! $invoice) {
            return;
        }

        $ctx->openCustomerInvoices[] = $invoice;

        // Invoicing must clear the accrued revenue the delivery
        // raised — leaving it behind would double-count revenue.
        $verify->money(
            bcsub($accruedBefore, $revenue, 2),
            $verify->trueBalanceByCode('143'),
            'Accrued revenue was not cleared when the delivery was invoiced',
            'Customer invoice — '.$monthStart->format('M Y'),
            ['accrued before invoicing' => $accruedBefore, 'order revenue' => $revenue],
            prefix: 'ACC'
        );

        $entry = $verify->journalEntryFor('customer_invoice', $invoice->id, 'Customer invoice');
        if ($entry) {
            $verify->entryBalances($entry, 'Customer invoice');
            $verify->entryPosts($entry, '226001', '0.00', bcmul($revenue, '0.14', 2), 'Customer invoice');
        }

        $this->log->note('Sold '.number_format((float) $revenue).' EGP; cost of sales '.number_format((float) $expectedCogs).' EGP.');
    }

    // ══════════════════════════════════════════════════════════════
    //  Collections and payments
    // ══════════════════════════════════════════════════════════════

    private function collectionCycle(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify = new Verify($this->log, $ctx->companyId);
        $bank   = $ctx->bankAccounts[0];
        $date   = $monthStart->copy()->addDays(25)->toDateString();
        $still  = [];

        foreach ($ctx->openCustomerInvoices as $invoice) {
            $invoice->refresh();
            $outstanding = (string) $invoice->amount_outstanding;

            if (bccomp($outstanding, '0', 2) <= 0) {
                continue;
            }

            // Never collect from the partner we also buy from — those
            // documents are held open deliberately so the internal
            // settlement (netting the two sides) has something to net.
            if ($ctx->dualRolePartner && (int) $invoice->partner_id === (int) $ctx->dualRolePartner->id) {
                $still[] = $invoice;

                continue;
            }

            // Collect a month after invoicing, like a real 30-day term.
            if ($invoice->invoice_date->gt($monthStart->copy()->subDays(20))) {
                $still[] = $invoice;

                continue;
            }

            $bankBefore = $verify->trueBalance((int) $bank->coa_account_id);

            // A genuine trap in this API, worth stating plainly:
            // amount_outstanding is stored in the FUNCTIONAL currency,
            // but settlement_amount must be given in the INVOICE's own
            // currency, and the service compares
            // settlement x invoice_rate against amount_outstanding.
            // Passing amount_outstanding directly — which is what the
            // error message quotes back at you — over-settles by the
            // exchange rate on any non-functional invoice.
            $rate            = (string) $invoice->exchange_rate;
            $settlementNative = bccomp($rate, '1', 6) === 0
                ? $outstanding
                : bcdiv($outstanding, $rate, 2);

            if ($this->reportedSettlementTrap === false && bccomp($rate, '1', 6) !== 0) {
                $this->reportedSettlementTrap = true;
                $this->log->finding(
                    prefix: 'FX',
                    severity: Finding::SEVERITY_MEDIUM,
                    confidence: Finding::CONFIDENCE_CONFIRMED,
                    title: 'Settling a foreign-currency invoice by its stated outstanding amount is refused',
                    operation: 'Customer collection — '.$monthStart->format('M Y'),
                    expected: 'passing the invoice\'s outstanding balance to settle it in full',
                    actual: 'refused — settlement_amount is read in the invoice\'s currency while '
                        .'amount_outstanding is stored in the functional currency',
                    source: 'CustomerPaymentService, settlement comparison',
                    rootCause: 'The two figures are in different currencies and the error message quotes the '
                        .'functional amount as though it were the value to pass, so the obvious correction '
                        .'(pass exactly what it printed) fails again.',
                    context: [
                        'invoice outstanding (functional)' => $outstanding,
                        'invoice exchange rate'            => $rate,
                        'amount that actually settles it'  => $settlementNative,
                    ],
                );
            }

            $collection = $this->gate->submit(
                \App\Http\Requests\Accounting\CustomerPaymentRequest::class,
                [
                    'partner_id'                  => $invoice->partner_id,
                    'collection_type'             => 'settlement',
                    'payment_date'                => $date,
                    'money_type'                  => 'bank_deposit',
                    'receive_currency_id'         => (int) $invoice->currency_id,
                    'exchange_rate'               => $rate,
                    'amount'                      => $settlementNative,
                    'destination_type'            => 'bank_account',
                    'destination_bank_account_id' => $bank->id,
                    'settlements'                 => [[
                        'customer_invoice_id' => $invoice->id,
                        'settlement_amount'   => $settlementNative,
                    ]],
                ],
                fn (array $data) => app(CustomerPaymentService::class)->record($ctx->companyId, $data, $ctx->actor),
                'Customer collection — '.$monthStart->format('M Y'),
                'customer collection'
            );

            if (! $collection) {
                $still[] = $invoice;

                continue;
            }

            // Money in: the bank rises by the collected amount
            // CONVERTED to the company's own currency.
            $collectedFunctional = bcmul($settlementNative, $rate, 2);

            $verify->money(
                bcadd($bankBefore, $collectedFunctional, 2),
                $verify->trueBalance((int) $bank->coa_account_id),
                'Collection did not reach the bank account',
                'Customer collection — '.$monthStart->format('M Y'),
                ['invoice' => (string) $invoice->id, 'collected' => $outstanding],
                prefix: 'BNK'
            );

            // And the invoice is genuinely settled, not just marked.
            $invoice->refresh();
            $verify->money(
                '0.00',
                (string) $invoice->amount_outstanding,
                'A fully collected invoice still shows an outstanding balance',
                'Customer collection — '.$monthStart->format('M Y'),
                prefix: 'ACC'
            );
        }

        $ctx->openCustomerInvoices = $still;
    }

    private function supplierPaymentCycle(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify = new Verify($this->log, $ctx->companyId);
        $bank   = $ctx->bankAccounts[0];
        $date   = $monthStart->copy()->addDays(27)->toDateString();
        $still  = [];

        foreach ($ctx->openSupplierBills as $bill) {
            $bill->refresh();
            $outstanding = (string) $bill->amount_outstanding;

            if (bccomp($outstanding, '0', 2) <= 0) {
                continue;
            }

            // Held open for netting — see the collection cycle.
            if ($ctx->dualRolePartner && (int) $bill->partner_id === (int) $ctx->dualRolePartner->id) {
                $still[] = $bill;

                continue;
            }

            if ($bill->bill_date->gt($monthStart->copy()->subDays(20))) {
                $still[] = $bill;

                continue;
            }

            $bankBefore = $verify->trueBalance((int) $bank->coa_account_id);

            $payment = $this->gate->submit(
                \App\Http\Requests\Accounting\SupplierPaymentRequest::class,
                [
                    'partner_id'             => $bill->partner_id,
                    'payment_type'           => 'settlement',
                    'payment_date'           => $date,
                    'money_type'             => 'outgoing_transfer',
                    'pay_currency_id'        => $ctx->currencyId,
                    'amount'                 => $outstanding,
                    'source_type'            => 'bank_account',
                    'source_bank_account_id' => $bank->id,
                    'settlements'            => [[
                        'supplier_bill_id'  => $bill->id,
                        'settlement_amount' => $outstanding,
                    ]],
                ],
                fn (array $data) => app(SupplierPaymentService::class)->record($ctx->companyId, $data, $ctx->actor),
                'Supplier payment — '.$monthStart->format('M Y'),
                'supplier payment'
            );

            if (! $payment) {
                $still[] = $bill;

                continue;
            }

            $verify->money(
                bcsub($bankBefore, $outstanding, 2),
                $verify->trueBalance((int) $bank->coa_account_id),
                'Supplier payment did not leave the bank account',
                'Supplier payment — '.$monthStart->format('M Y'),
                ['bill' => (string) $bill->id, 'paid' => $outstanding],
                prefix: 'BNK'
            );

            $bill->refresh();
            $verify->money(
                '0.00',
                (string) $bill->amount_outstanding,
                'A fully paid bill still shows an outstanding balance',
                'Supplier payment — '.$monthStart->format('M Y'),
                prefix: 'ACC'
            );
        }

        $ctx->openSupplierBills = $still;
    }

    // ══════════════════════════════════════════════════════════════
    //  Expenses
    // ══════════════════════════════════════════════════════════════

    private function expenseCycle(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify = new Verify($this->log, $ctx->companyId);
        $bank   = $ctx->bankAccounts[0];
        $rent   = '25000.00';
        $date   = $monthStart->copy()->addDays(3)->toDateString();

        $bankBefore    = $verify->trueBalance((int) $bank->coa_account_id);
        $expenseBefore = $verify->trueBalance($ctx->expenseAccounts['Office Rent']);

        $payment = $this->gate->submit(
            \App\Http\Requests\Accounting\CashExpensePaymentRequest::class,
            [
                'payee_description'      => 'Landlord — monthly office rent',
                'payment_date'           => $date,
                'money_type'             => 'outgoing_transfer',
                'currency_id'            => $ctx->currencyId,
                'source_type'            => 'bank_account',
                'source_bank_account_id' => $bank->id,
                'lines'                  => [[
                    'description'        => 'Office rent — '.$monthStart->format('F Y'),
                    'expense_account_id' => $ctx->expenseAccounts['Office Rent'],
                    'subtotal'           => $rent,
                ]],
            ],
            fn (array $data) => app(CashExpensePaymentService::class)->record($ctx->companyId, $data, $ctx->actor),
            'Office rent — '.$monthStart->format('M Y'),
            'cash expense payment'
        );

        if (! $payment) {
            return;
        }

        // Expense up, bank down, by the same amount.
        $verify->money(
            bcadd($expenseBefore, $rent, 2),
            $verify->trueBalance($ctx->expenseAccounts['Office Rent']),
            'Rent did not reach the expense account',
            'Office rent — '.$monthStart->format('M Y'),
            prefix: 'ACC'
        );
        $verify->money(
            bcsub($bankBefore, $rent, 2),
            $verify->trueBalance((int) $bank->coa_account_id),
            'Rent did not leave the bank account',
            'Office rent — '.$monthStart->format('M Y'),
            prefix: 'BNK'
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  Manufacturing variance closing
    // ══════════════════════════════════════════════════════════════

    /**
     * Sweeps the month's manufacturing variance out of work in
     * progress. Standard costing deliberately leaves the difference
     * between actual and standard sitting in WIP; this is the step
     * that clears it, and without it WIP grows for ever.
     */
    private function closeManufacturingVariance(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify = new Verify($this->log, $ctx->companyId);

        $closing = $this->gate->submit(
            null,
            [],
            fn () => app(\App\Services\Accounting\VarianceClosingService::class)->post(
                $ctx->companyId, $monthStart->year, $monthStart->month, $ctx->actor
            ),
            'Close the manufacturing variance — '.$monthStart->format('M Y'),
            'variance closing'
        );

        if (! $closing) {
            return;
        }

        $verify->ledgerBalanced('Variance closing — '.$monthStart->format('M Y'));

        // Variance absorption deliberately rewrites ProductStockBalance
        // values with NO stock movement behind them — that is the
        // documented design (VarianceClosingService, "not just a
        // GL-only plug with a stale subledger underneath it"). The
        // cycle's parallel book only knows about movements, so it
        // cannot follow that step.
        //
        // The check that still has teeth is the one that does not
        // depend on the book: the subsidiary ledger must still agree
        // with the general ledger after the absorption. Run that
        // first, THEN re-base the book — so a genuine break is caught
        // before the book is told to trust the application.
        $verify->inventoryReconcilesToLedger('Variance closing — '.$monthStart->format('M Y'));

        $this->rebaseBookAfterVarianceAbsorption($ctx, $verify, $monthStart);

        // Closing the same month twice must be refused, or the
        // variance would be swept out of WIP more than once.
        $this->gate->expectRefused(
            fn () => app(\App\Services\Accounting\VarianceClosingService::class)->post(
                $ctx->companyId, $monthStart->year, $monthStart->month, $ctx->actor
            ),
            '/already been closed/i',
            'Close the same manufacturing period twice — '.$monthStart->format('M Y'),
            'a period whose variance is already closed cannot be closed again'
        );
    }

    /**
     * Re-bases the cycle's parallel inventory book onto the
     * application's own balances after a variance absorption.
     *
     * Only values move here, never quantities — a quantity change
     * would mean stock appeared or vanished without a movement, which
     * IS a defect, so it is reported rather than absorbed.
     */
    private function rebaseBookAfterVarianceAbsorption(CycleContext $ctx, Verify $verify, Carbon $monthStart): void
    {
        $moved = 0;

        foreach ($ctx->book->productIds() as $productId) {
            $actual = $verify->inventoryState($productId);

            if (bccomp($actual['quantity'], $ctx->book->quantity($productId), 4) !== 0) {
                $verify->quantity(
                    $ctx->book->quantity($productId),
                    $actual['quantity'],
                    'Variance closing changed a stock QUANTITY, which it must never do',
                    'Variance closing — '.$monthStart->format('M Y'),
                    prefix: 'INV'
                );
            }

            if (bccomp($actual['value'], $ctx->book->value($productId), 2) !== 0) {
                $moved++;
                $ctx->book->resyncTo($productId, $actual['quantity'], $actual['value'], $actual['wac']);
            }
        }

        if ($moved > 0) {
            $this->log->note(
                "  (variance absorbed into {$moved} product cost(s) with no stock movement — by design; "
                .'the book is re-based onto the application here so later months test new movements)'
            );
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Warehouse transfer
    // ══════════════════════════════════════════════════════════════

    private function warehouseTransfer(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify  = new Verify($this->log, $ctx->companyId);
        $product = $ctx->sellableProducts()[0] ?? null;

        // June sends stock out to the second warehouse; November brings
        // it back. A one-way transfer would slowly strand stock where
        // no delivery can reach it — which is a scenario bug, not a
        // product one, and the cycle should not manufacture those.
        $sendingOut = (int) $monthStart->format('n') === 6;
        $from = $sendingOut ? $ctx->masterLocationId : $ctx->secondLocationId;
        $to   = $sendingOut ? $ctx->secondLocationId : $ctx->masterLocationId;

        if (! $product || ! $ctx->book->canIssue($product->id, '5', $from)) {
            return;
        }

        // Snapshot the APPLICATION, not the cycle's book. The
        // invariant under test is "a transfer changes nothing about
        // cost", which is a statement about the application's own
        // before and after — comparing against the book would fold in
        // any earlier divergence and report it here again.
        $stateBefore = $verify->inventoryState($product->id);
        $valueBefore = $stateBefore['value'];
        $wacBefore   = $stateBefore['wac'];

        try {
            app(\App\Services\Inventory\StockLedgerService::class)->transfer(
                companyId: $ctx->companyId,
                product: $product,
                fromLocationId: $from,
                toLocationId: $to,
                quantity: '5',
                actor: $ctx->actor,
                options: ['movement_date' => $monthStart->copy()->addDays(20)->toDateString()],
            );
            $ctx->book->transferBetween($product->id, $from, $to, '5');
            $this->log->operation('warehouse transfer');
        } catch (\Throwable $e) {
            $this->log->finding(
                prefix: 'INV',
                severity: Finding::SEVERITY_MEDIUM,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'Transferring stock between warehouses failed',
                operation: 'Warehouse transfer — '.$monthStart->format('M Y'),
                expected: 'stock to move between two of the company\'s own warehouses',
                actual: get_class($e).': '.$e->getMessage(),
            );

            return;
        }

        // Moving stock between your own warehouses changes neither the
        // company's holding nor its cost.
        $state = $verify->inventoryState($product->id);
        $verify->money($valueBefore, $state['value'], 'A warehouse transfer changed the company-wide stock value', 'Warehouse transfer', prefix: 'INV');
        $verify->unitCost($wacBefore, $state['wac'], 'A warehouse transfer moved the weighted average cost', 'Warehouse transfer');
    }

    // ══════════════════════════════════════════════════════════════
    //  HR — leave and expense claims
    // ══════════════════════════════════════════════════════════════

    private function hrCycle(CycleContext $ctx, Carbon $monthStart, int $month): void
    {
        $verify   = new Verify($this->log, $ctx->companyId);
        $employee = $ctx->rotate($ctx->employees, $month);

        // ── Leave: raise, submit, approve ─────────────────────────
        $from = $monthStart->copy()->addDays(10)->toDateString();
        $to   = $monthStart->copy()->addDays(12)->toDateString();

        $leave = $this->gate->submit(
            LeaveRequestRequest::class,
            [
                'partner_id'    => $employee->id,
                'leave_type_id' => $ctx->leaveTypes['annual']->id,
                'from_date'     => $from,
                'to_date'       => $to,
                'reason'        => 'Quarterly leave',
            ],
            fn (array $data) => app(LeaveRequestService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Leave request — '.$monthStart->format('M Y'),
            'leave request'
        );

        if ($leave) {
            $verify->quantity('3.0000', (string) $leave->days_count, 'Leave day count is wrong', 'Leave request', prefix: 'HR');

            $submitted = app(LeaveRequestService::class)->submit($leave, $ctx->actor);
            app(LeaveRequestService::class)->approve($submitted, $ctx->actor);
            $this->log->operation('leave approval');
        }

        // ── Expense claim: raise, submit, approve, pay ────────────
        $claimDate = $monthStart->copy()->endOfMonth()->toDateString();
        $amount    = '1200.00';

        $claim = $this->gate->submit(
            ExpenseClaimRequest::class,
            [
                'partner_id'    => $employee->id,
                'claim_date'    => $claimDate,
                'title'         => 'Quarterly travel',
                'currency_id'   => $ctx->currencyId,
                'exchange_rate' => 1,
                'lines'         => [[
                    'expense_account_id' => $verify->accountId('543'),
                    'expense_date'       => $monthStart->copy()->addDays(14)->toDateString(),
                    'description'        => 'Client visits',
                    'amount'             => $amount,
                ]],
            ],
            fn (array $data) => app(ExpenseClaimService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Expense claim — '.$monthStart->format('M Y'),
            'expense claim'
        );

        if (! $claim) {
            return;
        }

        $liabilityBefore = $verify->trueBalanceByCode(ExpenseClaim::LIABILITY_ACCOUNT_CODE);

        $approved = app(ExpenseClaimService::class)->approve(
            app(ExpenseClaimService::class)->submit($claim, $ctx->actor),
            $ctx->actor,
            $claimDate
        );
        $this->log->operation('expense claim approval');

        // Approval creates a real obligation to the employee.
        $verify->money(
            bcsub($liabilityBefore, $amount, 2),
            $verify->trueBalanceByCode(ExpenseClaim::LIABILITY_ACCOUNT_CODE),
            'Approving an expense claim did not raise the employee liability',
            'Expense claim — '.$monthStart->format('M Y'),
            prefix: 'HR'
        );

        $bank       = $ctx->bankAccounts[0];
        $bankBefore = $verify->trueBalance((int) $bank->coa_account_id);

        app(ExpenseClaimService::class)->markPaid($approved, [
            'payment_date'            => $claimDate,
            'payment_source_type'     => ExpenseClaim::SOURCE_BANK_ACCOUNT,
            'payment_bank_account_id' => $bank->id,
        ], $ctx->actor);
        $this->log->operation('expense claim payment');

        // And paying it clears the obligation completely.
        $verify->money(
            $liabilityBefore,
            $verify->trueBalanceByCode(ExpenseClaim::LIABILITY_ACCOUNT_CODE),
            'Paying an expense claim did not clear the employee liability',
            'Expense claim payment — '.$monthStart->format('M Y'),
            prefix: 'HR'
        );
        $verify->money(
            bcsub($bankBefore, $amount, 2),
            $verify->trueBalance((int) $bank->coa_account_id),
            'Expense claim payment did not leave the bank account',
            'Expense claim payment — '.$monthStart->format('M Y'),
            prefix: 'BNK'
        );
    }
}
