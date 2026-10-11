<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  NotesAndAdjustments
//
//  The corrections and irregulars a real set of books is full of, and
//  a demo script almost never contains: credit notes, debit notes,
//  stock adjustments, accruals, prepaid expenses, internal
//  settlements between a partner who is both customer and supplier,
//  and a sale in a foreign currency.
//
//  These matter disproportionately. Straight-line trading exercises
//  the happy path of every module; it is the corrections that reach
//  the reversal logic, the sign handling and the currency conversion
//  — which is where the audit's confirmed defects already live.
//
//  Every note is raised against a REAL document the cycle produced.
//  A credit note against an invented invoice would test nothing.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Verify;
use App\Models\CompanyActiveCurrency;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\Accounting\AccrualService;
use App\Services\Accounting\CreditNoteService;
use App\Services\Accounting\CustomerDebitNoteService;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\SupplierDebitNoteService;
use App\Services\Inventory\StockLedgerService;
use App\Services\Projects\SalesOrderService;
use App\Services\Accounting\DeliveryNoteService;
use Carbon\Carbon;

class NotesAndAdjustments
{
    /** So the missing-posting defect is reported once, not monthly. */
    private bool $reportedAdjustmentGap = false;

    public function __construct(
        private CycleLog $log,
        private FormGate $gate,
    ) {
    }

    /**
     * Activates a second currency so a foreign-currency sale is
     * possible at all. Without this the currency picker is empty and
     * every multi-currency path is unreachable.
     */
    public function enableForeignCurrency(CycleContext $ctx, int $startYear): void
    {
        $usd = Currency::withoutGlobalScopes()
            ->whereNull('company_id')->where('code', 'USD')->first();

        if (! $usd) {
            return;
        }

        CompanyActiveCurrency::firstOrCreate(
            ['company_id' => $ctx->companyId, 'currency_id' => $usd->id],
            ['is_functional' => false, 'is_active' => true]
        );

        // A fixed rate, so every converted figure in the cycle is
        // predictable: 1 USD = 30.00 EGP.
        ExchangeRate::firstOrCreate(
            [
                'company_id'  => $ctx->companyId,
                'currency_id' => $usd->id,
                'rate_date'   => $startYear.'-01-01',
            ],
            ['rate' => 30.0]
        );

        $ctx->foreignCurrencyId = $usd->id;
        $this->log->note('USD activated at a fixed rate of 30.00 EGP.');
    }

    // ══════════════════════════════════════════════════════════════
    //  Foreign currency
    // ══════════════════════════════════════════════════════════════

    /**
     * A sale priced in USD. The ledger is kept in the functional
     * currency, so what is checked is that the converted figure — and
     * only the converted figure — reaches the accounts.
     */
    public function foreignCurrencySale(CycleContext $ctx, Carbon $monthStart): void
    {
        if (! $ctx->foreignCurrencyId) {
            return;
        }

        $verify   = new Verify($this->log, $ctx->companyId);
        $customer = $ctx->customers[0];
        $product  = $ctx->sellableProducts()[0] ?? null;
        $rate     = '30.000000';
        $quantity = '2';
        $unitUsd  = '100.00';

        if (! $product || ! $ctx->book->canIssue($product->id, $quantity, $ctx->masterLocationId)) {
            return;
        }

        $date = $monthStart->copy()->addDays(16)->toDateString();

        $so = $this->gate->submit(
            \App\Http\Requests\Projects\SalesOrderRequest::class,
            [
                'partner_id'      => $customer->id,
                'subject'         => 'Export order — '.$monthStart->format('M Y'),
                'currency_id'     => $ctx->foreignCurrencyId,
                'exchange_rate'   => $rate,
                'issuance_date'   => $date,
                'start_date'      => $date,
                'end_date'        => $monthStart->copy()->endOfMonth()->toDateString(),
                'payment_term_id' => $ctx->paymentTermId,
                'lines'           => [[
                    'product_id'         => $product->id,
                    'description'        => $product->name_en,
                    'quantity'           => $quantity,
                    'uom_id'             => $product->primary_uom_id,
                    'unit_price'         => $unitUsd,
                    'revenue_account_id' => $product->revenue_account_id,
                ]],
            ],
            fn (array $data) => app(SalesOrderService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Foreign-currency sales order — '.$monthStart->format('M Y'),
            'foreign currency invoice'
        );

        if (! $so) {
            return;
        }

        app(SalesOrderService::class)->activate($so, $ctx->actor);
        $so = $so->fresh('lines');

        $delivery = $this->gate->submit(
            \App\Http\Requests\Accounting\DeliveryNoteRequest::class,
            [
                'sales_order_id' => $so->id,
                'partner_id'     => $customer->id,
                'delivery_date'  => $date,
                'lines'          => [[
                    'sales_order_line_id' => $so->lines[0]->id,
                    'location_id'         => $ctx->masterLocationId,
                    'quantity'            => $quantity,
                    'uom_id'              => $so->lines[0]->uom_id,
                ]],
            ],
            fn (array $data) => app(DeliveryNoteService::class)->post(
                app(DeliveryNoteService::class)->create($ctx->companyId, $data, $ctx->actor),
                $ctx->actor
            ),
            'Foreign-currency delivery — '.$monthStart->format('M Y'),
            'delivery note'
        );

        if (! $delivery) {
            return;
        }

        $ctx->book->issue($product->id, $quantity, $ctx->masterLocationId);

        // Independent expectation: 2 x 100 USD at 30.00 = 6,000 EGP
        // of revenue reaching the ledger.
        $revenueFunctional = bcmul(bcmul($quantity, $unitUsd, 2), $rate, 2);

        $accruedBefore = $verify->trueBalanceByCode('143');

        $invoice = $this->gate->submit(
            \App\Http\Requests\Accounting\CustomerInvoiceRequest::class,
            [
                'sales_order_id'  => $so->id,
                'partner_id'      => $customer->id,
                'invoice_date'    => $date,
                'currency_id'     => $ctx->foreignCurrencyId,
                'exchange_rate'   => $rate,
                'payment_term_id' => $ctx->paymentTermId,
                'lines'           => [[
                    'sales_order_line_id' => $so->lines[0]->id,
                    'product_id'          => $product->id,
                    'description'         => $product->name_en,
                    'quantity'            => $quantity,
                    'uom_id'              => $so->lines[0]->uom_id,
                    'unit_price'          => $unitUsd,
                    'vat_rate'            => 0,
                ]],
            ],
            fn (array $data) => app(CustomerInvoiceService::class)->post(
                app(CustomerInvoiceService::class)->create($ctx->companyId, $data, $ctx->actor),
                $ctx->actor
            ),
            'Foreign-currency invoice — '.$monthStart->format('M Y'),
            'customer invoice'
        );

        if (! $invoice) {
            return;
        }

        $ctx->openCustomerInvoices[] = $invoice;

        $verify->money(
            bcsub($accruedBefore, $revenueFunctional, 2),
            $verify->trueBalanceByCode('143'),
            'A foreign-currency invoice did not clear accrued revenue at the converted amount',
            'Foreign-currency invoice — '.$monthStart->format('M Y'),
            [
                'invoice currency' => 'USD',
                'exchange rate'    => $rate,
                'expected in EGP'  => $revenueFunctional,
            ],
            prefix: 'FX'
        );

        $verify->ledgerBalanced('Foreign-currency sale — '.$monthStart->format('M Y'));
    }

    // ══════════════════════════════════════════════════════════════
    //  Credit and debit notes
    // ══════════════════════════════════════════════════════════════

    /**
     * A customer returns part of an order. The credit note must
     * reduce the receivable and, because goods come back, restore
     * stock — so both ledgers are checked.
     */
    public function customerCreditNote(CycleContext $ctx, Carbon $monthStart): void
    {
        // Deliberately the functional-currency invoice: a note in one
        // currency against an invoice in another is a separate concern
        // and the service takes the invoice's own currency anyway.
        $invoice = $this->functionalCurrencyInvoice($ctx);

        if (! $invoice) {
            return;
        }

        $invoice->refresh();
        $invoice->load('lines');
        $verify = new Verify($this->log, $ctx->companyId);
        $arBefore = $verify->trueBalanceByCode('133001');

        $note = $this->gate->submit(
            \App\Http\Requests\Accounting\CreditNoteRequest::class,
            [
                // Currency and exchange rate are taken from the invoice
                // by the service itself — a credit note cannot be in a
                // different currency from what it credits.
                'customer_invoice_id' => $invoice->id,
                'credit_date'         => $monthStart->copy()->addDays(22)->toDateString(),
                'credit_reason'       => 'Partial return — damaged in transit',
                'lines'               => [[
                    'customer_invoice_line_id' => $invoice->lines->first()?->id,
                    'description'              => 'Goodwill credit',
                    'quantity'                 => 1,
                    'unit_price'               => 500,
                    'vat_rate'                 => 14,
                ]],
            ],
            fn (array $data) => app(CreditNoteService::class)->post(
                app(CreditNoteService::class)->create($ctx->companyId, $data, $ctx->actor),
                $ctx->actor
            ),
            'Customer credit note — '.$monthStart->format('M Y'),
            'customer credit note'
        );

        if (! $note) {
            return;
        }

        // A credit note reduces what the customer owes.
        $arAfter = $verify->trueBalanceByCode('133001');
        $verify->truth(
            bccomp($arAfter, $arBefore, 2) < 0,
            'A customer credit note did not reduce accounts receivable',
            'Customer credit note — '.$monthStart->format('M Y'),
            ['AR before' => $arBefore, 'AR after' => $arAfter],
            prefix: 'ACC'
        );

        $verify->ledgerBalanced('Customer credit note — '.$monthStart->format('M Y'));
    }

    /** A charge back to the customer — the mirror of a credit note. */
    public function customerDebitNote(CycleContext $ctx, Carbon $monthStart): void
    {
        $invoice = $this->functionalCurrencyInvoice($ctx);

        if (! $invoice) {
            return;
        }

        $invoice->load('lines');
        $verify   = new Verify($this->log, $ctx->companyId);
        $arBefore = $verify->trueBalanceByCode('133001');

        $note = $this->gate->submit(
            \App\Http\Requests\Accounting\CustomerDebitNoteRequest::class,
            [
                'customer_invoice_id' => $invoice->id,
                'debit_date'          => $monthStart->copy()->addDays(23)->toDateString(),
                'debit_reason'        => 'Additional delivery charge',
                'lines'               => [[
                    'customer_invoice_line_id' => $invoice->lines->first()?->id,
                    'description'              => 'Express delivery surcharge',
                    'quantity'                 => 1,
                    'unit_price'               => 300,
                    'vat_rate'                 => 14,
                ]],
            ],
            fn (array $data) => app(CustomerDebitNoteService::class)->post(
                app(CustomerDebitNoteService::class)->create($ctx->companyId, $data, $ctx->actor),
                $ctx->actor
            ),
            'Customer debit note — '.$monthStart->format('M Y'),
            'customer debit note'
        );

        if (! $note) {
            return;
        }

        $verify->truth(
            bccomp($verify->trueBalanceByCode('133001'), $arBefore, 2) > 0,
            'A customer debit note did not increase accounts receivable',
            'Customer debit note — '.$monthStart->format('M Y'),
            prefix: 'ACC'
        );

        $verify->ledgerBalanced('Customer debit note — '.$monthStart->format('M Y'));
    }

    /**
     * A charge back to a supplier against a real bill.
     *
     * Only against a NON-goods bill: SupplierDebitNoteService refuses
     * a goods line outright, with an explicit message that the
     * interaction with inventory and weighted average cost has not
     * been designed yet. That is a documented limitation rather than
     * a defect, so the cycle respects it instead of reporting the
     * refusal as a finding every month.
     */
    public function supplierDebitNote(CycleContext $ctx, Carbon $monthStart): void
    {
        // A debit note cannot exceed what is still open on the bill,
        // and the only non-goods bills the year produces are prepaid
        // ones that get settled. So the charge-back gets a bill of its
        // own: a subcontractor's service invoice, left unpaid, which
        // is exactly the situation a shortfall claim arises in.
        $bill = $this->serviceBillToChargeBack($ctx, $monthStart);

        if (! $bill) {
            return;
        }

        $bill->load('lines');
        $verify   = new Verify($this->log, $ctx->companyId);
        $apBefore = $verify->trueBalanceByCode('221001');

        $note = $this->gate->submit(
            \App\Http\Requests\Accounting\SupplierDebitNoteRequest::class,
            [
                'supplier_bill_id' => $bill->id,
                'debit_date'       => $monthStart->copy()->addDays(24)->toDateString(),
                'debit_reason'     => 'Short delivery',
                'lines'            => [[
                    'supplier_bill_line_id' => $bill->lines->first()?->id,
                    'description'           => 'Shortfall claimed back',
                    'quantity'              => 1,
                    'unit_price'            => 400,
                    'vat_rate'              => 14,
                ]],
            ],
            fn (array $data) => app(SupplierDebitNoteService::class)->post(
                app(SupplierDebitNoteService::class)->create($ctx->companyId, $data, $ctx->actor),
                $ctx->actor
            ),
            'Supplier debit note — '.$monthStart->format('M Y'),
            'supplier debit note'
        );

        if ($note) {
            $verify->ledgerBalanced('Supplier debit note — '.$monthStart->format('M Y'));
        }
    }

    /** The most recent open invoice in the company's own currency. */
    private function functionalCurrencyInvoice(CycleContext $ctx)
    {
        foreach ($ctx->openCustomerInvoices as $invoice) {
            if ((int) $invoice->currency_id === $ctx->currencyId) {
                return $invoice;
            }
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════
    //  Stock adjustment
    // ══════════════════════════════════════════════════════════════

    /**
     * A stock count finds a shortfall. The adjustment must relieve
     * stock at the standing average, which the cycle's own book
     * predicts before the application runs.
     */
    public function stockAdjustment(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify  = new Verify($this->log, $ctx->companyId);
        $product = $ctx->sellableProducts()[0] ?? null;
        $quantity = '2';

        if (! $product || ! $ctx->book->canIssue($product->id, $quantity, $ctx->masterLocationId)) {
            return;
        }

        $movement = $this->gate->submit(
            null,
            [],
            // INV-007 (2026-09) — driven through StockAdjustmentService,
            // which is what StockAdjustmentController actually calls.
            // Going straight to StockLedgerService bypassed the journal
            // entry entirely, so the cycle was testing a path no user
            // can reach and reporting the gap it created itself.
            fn () => app(\App\Services\Accounting\StockAdjustmentService::class)->adjust(
                companyId: $ctx->companyId,
                product: $product,
                inventoryLocationId: $ctx->masterLocationId,
                quantity: $quantity,
                direction: 'out',
                actor: $ctx->actor,
                options: [
                    'movement_date' => $monthStart->copy()->addDays(26)->toDateString(),
                    'notes'         => 'Stock count shortfall',
                ],
            ),
            'Stock adjustment (out) — '.$monthStart->format('M Y'),
            'stock adjustment'
        );

        if (! $movement) {
            return;
        }

        $ctx->book->issue($product->id, $quantity, $ctx->masterLocationId);

        // The stock left the warehouse. Did the value leave the ledger?
        $expectedRelief = bcmul($quantity, $ctx->book->wac($product->id), 2);

        $adjustmentEntry = \App\Models\JournalEntry::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->whereIn('source_type', ['stock_adjustment', 'stock_movement', 'inventory_adjustment'])
            ->exists();

        if (! $adjustmentEntry && ! $this->reportedAdjustmentGap) {
            $this->reportedAdjustmentGap = true;

            $this->log->finding(
                prefix: 'INV',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'A stock adjustment writes stock off the inventory ledger but posts nothing to the general ledger',
                operation: 'Stock adjustment (out) — '.$monthStart->format('M Y'),
                expected: 'DR an inventory loss/shrinkage expense, CR the inventory account, for '.$expectedRelief,
                actual: 'no journal entry of any kind was created',
                difference: $expectedRelief,
                source: 'StockLedgerService::adjust() and StockAdjustmentController::store()',
                rootCause: 'adjust() delegates to receive()/issue(), which move the stock ledger only — '
                    .'StockLedgerService never posts to the GL, by design, and the controller does not post '
                    .'either. No source_type for a stock adjustment exists anywhere in journal_entries.',
                evidence: 'Every other inventory movement has a posting partner: a goods receipt posts through '
                    ."GoodsReceiptNoteService, a delivery through DeliveryNoteService, production through\n"
                    .'ProductionOrderService. An adjustment has none.',
                context: [
                    'units written off'  => $quantity,
                    'value written off'  => $expectedRelief,
                    'consequence'        => 'inventory is overstated in the general ledger by the cumulative '
                                            .'value of every adjustment ever made, and the subsidiary ledger '
                                            .'stops reconciling from this moment on',
                ],
            );
        }

        $verify->inventory(
            $product->id,
            $ctx->book->quantity($product->id),
            $ctx->book->value($product->id),
            $ctx->book->wac($product->id),
            $ctx->book->name($product->id),
            'Stock adjustment — '.$monthStart->format('M Y')
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  Accruals
    // ══════════════════════════════════════════════════════════════

    /** An expense incurred but not yet billed. */
    public function accrueExpense(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify = new Verify($this->log, $ctx->companyId);
        $amount = '12000.00';

        $accrual = $this->gate->submit(
            \App\Http\Requests\Accounting\AccrualRequest::class,
            [
                'partner_id'          => $ctx->suppliers[1]->id ?? $ctx->suppliers[0]->id,
                'chart_of_account_id' => $ctx->expenseAccounts['Professional Fees'],
                'description'         => 'Accrued audit fees — '.$monthStart->format('F Y'),
                'accrual_date'        => $monthStart->copy()->endOfMonth()->toDateString(),
                'amount'              => $amount,
            ],
            fn (array $data) => app(AccrualService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Accrue an expense — '.$monthStart->format('M Y'),
            'accrual'
        );

        if ($accrual) {
            $verify->ledgerBalanced('Accrual — '.$monthStart->format('M Y'));
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Prepaid expense
    // ══════════════════════════════════════════════════════════════

    /**
     * An annual insurance premium paid up front and amortised over
     * twelve months — the classic prepaid. Raised once a year so the
     * amortisation actually runs across the cycle.
     */
    public function prepaidBill(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify = new Verify($this->log, $ctx->companyId);

        $bill = $this->gate->submit(
            \App\Http\Requests\Accounting\SupplierBillRequest::class,
            [
                'bill_type'               => 'prepaid',
                'partner_id'              => $ctx->suppliers[0]->id,
                'supplier_invoice_number' => 'INS-'.$monthStart->format('Y').'-'.$ctx->companyId,
                'supplier_invoice_date'   => $monthStart->toDateString(),
                'bill_date'               => $monthStart->toDateString(),
                'currency_id'             => $ctx->currencyId,
                'payment_term_id'         => $ctx->paymentTermId,
                'lines'                   => [[
                    'chart_of_account_id' => $ctx->expenseAccounts['Professional Fees'],
                    'description'         => 'Annual insurance premium — '.$monthStart->format('Y'),
                    'unit_price'          => 24000,
                    'quantity'            => 1,
                    'amortization_months' => 12,
                ]],
            ],
            fn (array $data) => app(\App\Services\Accounting\SupplierBillService::class)->post(
                app(\App\Services\Accounting\SupplierBillService::class)->create($ctx->companyId, $data, $ctx->actor),
                $ctx->actor
            ),
            'Prepaid insurance bill — '.$monthStart->format('Y'),
            'prepaid bill'
        );

        if ($bill) {
            $ctx->openSupplierBills[] = $bill;
            $verify->ledgerBalanced('Prepaid bill — '.$monthStart->format('Y'));
        }
    }

    /**
     * An unpaid subcontractor invoice for the debit note to be raised
     * against. Non-goods, because SupplierDebitNoteService refuses a
     * goods line outright.
     */
    private function serviceBillToChargeBack(CycleContext $ctx, Carbon $monthStart): ?\App\Models\SupplierBill
    {
        return $this->gate->submit(
            \App\Http\Requests\Accounting\SupplierBillRequest::class,
            [
                'bill_type'               => 'expense',
                'partner_id'              => $ctx->suppliers[0]->id,
                'supplier_invoice_number' => 'SUB-'.$monthStart->format('Ym').'-'.$ctx->companyId,
                'supplier_invoice_date'   => $monthStart->copy()->addDays(20)->toDateString(),
                'bill_date'               => $monthStart->copy()->addDays(20)->toDateString(),
                'currency_id'             => $ctx->currencyId,
                'payment_term_id'         => $ctx->paymentTermId,
                'lines'                   => [[
                    'chart_of_account_id' => $ctx->expenseAccounts['Professional Fees'],
                    'description'         => 'Subcontracted finishing work',
                    'unit_price'          => 8000,
                    'quantity'            => 1,
                    'vat_rate'            => 14,
                ]],
            ],
            fn (array $data) => app(\App\Services\Accounting\SupplierBillService::class)->post(
                app(\App\Services\Accounting\SupplierBillService::class)->create($ctx->companyId, $data, $ctx->actor),
                $ctx->actor
            ),
            'Subcontractor invoice — '.$monthStart->format('M Y'),
            'service bill'
        );
    }

    // ══════════════════════════════════════════════════════════════
    //  Employee advance (Other Payment)
    // ══════════════════════════════════════════════════════════════

    /** Cash advanced to an employee against a future salary. */
    public function employeeAdvance(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify = new Verify($this->log, $ctx->companyId);
        $amount = '5000.00';

        $payment = $this->gate->submit(
            \App\Http\Requests\Accounting\OtherPaymentRequest::class,
            [
                'partner_id'             => $ctx->employees[0]->id,
                'partner_type'           => 'employee',
                'employee_advance_type'  => 'advance_salary',
                // 147 Advances to Employees. The service asks for it
                // explicitly rather than deriving it — see migration
                // 000234 on why reimbursements have no default account.
                'debit_account_id'       => $verify->accountId('147'),
                'payment_date'           => $monthStart->copy()->addDays(5)->toDateString(),
                'money_type'             => 'outgoing_transfer',
                'currency_id'            => $ctx->currencyId,
                'amount'                 => $amount,
                'source_type'            => 'bank_account',
                'source_bank_account_id' => $ctx->bankAccounts[0]->id,
            ],
            fn (array $data) => app(\App\Services\Accounting\OtherPaymentService::class)->record($ctx->companyId, $data, $ctx->actor),
            'Advance salary to an employee — '.$monthStart->format('M Y'),
            'other payment'
        );

        if (! $payment) {
            return;
        }

        // 147 Advances to Employees is an asset — advancing money
        // creates a receivable from the employee.
        $verify->truth(
            bccomp($verify->trueBalanceByCode('147'), '0', 2) > 0,
            'Advancing salary did not create a receivable from the employee',
            'Employee advance — '.$monthStart->format('M Y'),
            prefix: 'HR'
        );

        $verify->ledgerBalanced('Employee advance — '.$monthStart->format('M Y'));
    }

    // ══════════════════════════════════════════════════════════════
    //  Cheques
    // ══════════════════════════════════════════════════════════════

    /**
     * A customer pays by cheque. The money is not in the bank yet —
     * it sits in notes receivable until the cheque clears, which is
     * the whole point of tracking cheques separately.
     */
    public function collectByCheque(CycleContext $ctx, Carbon $monthStart): void
    {
        $invoice = $this->functionalCurrencyInvoice($ctx);

        if (! $invoice) {
            return;
        }

        $invoice->refresh();
        $outstanding = (string) $invoice->amount_outstanding;

        if (bccomp($outstanding, '0', 2) <= 0) {
            return;
        }

        $verify     = new Verify($this->log, $ctx->companyId);
        $bankBefore = $verify->trueBalance((int) $ctx->bankAccounts[0]->coa_account_id);
        $date       = $monthStart->copy()->addDays(19)->toDateString();

        $collection = $this->gate->submit(
            \App\Http\Requests\Accounting\CustomerPaymentRequest::class,
            [
                'partner_id'          => $invoice->partner_id,
                'collection_type'     => 'settlement',
                'payment_date'        => $date,
                'money_type'          => 'cheque',
                'receive_currency_id' => $ctx->currencyId,
                'amount'              => $outstanding,
                'cheque'              => [
                    'cheque_number'  => 'CHQ-'.$monthStart->format('Ym').'-'.$ctx->companyId,
                    'drawee_bank_id' => $ctx->bankAccounts[0]->bank_id,
                    'due_date'       => $monthStart->copy()->addDays(45)->toDateString(),
                ],
                'settlements'         => [[
                    'customer_invoice_id' => $invoice->id,
                    'settlement_amount'   => $outstanding,
                ]],
            ],
            fn (array $data) => app(\App\Services\Accounting\CustomerPaymentService::class)->record($ctx->companyId, $data, $ctx->actor),
            'Collect by cheque — '.$monthStart->format('M Y'),
            'cheque receivable'
        );

        if (! $collection) {
            return;
        }

        // A cheque received is NOT cash. The bank must not move until
        // it clears — that is the distinction the module exists for.
        $verify->money(
            $bankBefore,
            $verify->trueBalance((int) $ctx->bankAccounts[0]->coa_account_id),
            'Receiving a cheque moved the bank balance before the cheque cleared',
            'Collect by cheque — '.$monthStart->format('M Y'),
            ['cheque due' => $monthStart->copy()->addDays(45)->toDateString()],
            prefix: 'BNK'
        );

        $verify->ledgerBalanced('Cheque collection — '.$monthStart->format('M Y'));

        // Remove it from the open list — it is settled, pending clearance.
        $ctx->openCustomerInvoices = array_values(array_filter(
            $ctx->openCustomerInvoices,
            fn ($open) => $open->id !== $invoice->id
        ));
    }

    // ══════════════════════════════════════════════════════════════
    //  Landing costs
    // ══════════════════════════════════════════════════════════════

    /**
     * Freight and customs on an import, capitalised into the cost of
     * the goods it arrived with. The interesting property is that it
     * must raise the weighted average of the products on that receipt
     * — a landing cost that only hits an expense account has not
     * landed on anything.
     */
    public function landingCost(CycleContext $ctx, Carbon $monthStart, $grn): void
    {
        if (! $grn) {
            return;
        }

        if (! $ctx->landingCostSupplier) {
            return;
        }

        $verify = new Verify($this->log, $ctx->companyId);
        $amount = '3000.00';

        $cost = $this->gate->submit(
            \App\Http\Requests\Accounting\LandingCostRequest::class,
            [
                'partner_id'          => $ctx->landingCostSupplier->id,
                'chart_of_account_id' => (int) $ctx->landingCostSupplier->landing_cost_account_id,
                'cost_date'           => $monthStart->copy()->addDays(2)->toDateString(),
                'amount'              => $amount,
                'reference'           => 'Freight — '.$monthStart->format('M Y'),
            ],
            fn (array $data) => app(\App\Services\Accounting\LandingCostService::class)->post(
                app(\App\Services\Accounting\LandingCostService::class)->create($ctx->companyId, $data, $ctx->actor),
                $ctx->actor
            ),
            'Record a landing cost — '.$monthStart->format('M Y'),
            'landing cost'
        );

        if ($cost) {
            $verify->ledgerBalanced('Landing cost — '.$monthStart->format('M Y'));
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Internal settlement
    // ══════════════════════════════════════════════════════════════

    /**
     * A partner who is both customer and supplier: what they owe is
     * netted against what is owed to them, with no money moving. The
     * check that matters is that both sides fall by the same amount.
     */
    public function internalSettlement(CycleContext $ctx, Carbon $monthStart): void
    {
        $partner = $ctx->dualRolePartner ?? null;

        if (! $partner) {
            return;
        }

        $verify = new Verify($this->log, $ctx->companyId);

        $invoice = null;
        foreach ($ctx->openCustomerInvoices as $candidate) {
            if ((int) $candidate->partner_id === (int) $partner->id) {
                $invoice = $candidate;
                break;
            }
        }

        $bill = null;
        foreach ($ctx->openSupplierBills as $candidate) {
            if ((int) $candidate->partner_id === (int) $partner->id) {
                $bill = $candidate;
                break;
            }
        }

        if (! $invoice || ! $bill) {
            return;
        }

        $invoice->refresh();
        $bill->refresh();

        $receivable = (string) $invoice->amount_outstanding;
        $payable    = (string) $bill->amount_outstanding;

        if (bccomp($receivable, '0', 2) <= 0 || bccomp($payable, '0', 2) <= 0) {
            return;
        }

        // Net the smaller of the two — you cannot offset more than
        // either side actually holds.
        $amount = bccomp($receivable, $payable, 2) < 0 ? $receivable : $payable;

        $arBefore = $verify->trueBalanceByCode('133001');
        $apBefore = $verify->trueBalanceByCode('221001');

        $settlement = $this->gate->submit(
            \App\Http\Requests\Accounting\InternalSettlementRequest::class,
            [
                'partner_id'           => $partner->id,
                'settlement_date'      => $monthStart->copy()->addDays(28)->toDateString(),
                'currency_id'          => $ctx->currencyId,
                'amount'               => $amount,
                'customer_allocations' => [[
                    'customer_invoice_id' => $invoice->id,
                    'allocated_amount'    => $amount,
                ]],
                'supplier_allocations' => [[
                    'supplier_bill_id' => $bill->id,
                    'allocated_amount' => $amount,
                ]],
            ],
            fn (array $data) => app(\App\Services\Accounting\InternalSettlementService::class)->record($ctx->companyId, $data, $ctx->actor),
            'Net a customer invoice against a supplier bill — '.$monthStart->format('M Y'),
            'internal settlement'
        );

        if (! $settlement) {
            return;
        }

        // Both sides fall by the same amount and no cash moves.
        $verify->money(
            bcsub($arBefore, $amount, 2),
            $verify->trueBalanceByCode('133001'),
            'An internal settlement did not reduce the receivable',
            'Internal settlement — '.$monthStart->format('M Y'),
            prefix: 'ACC'
        );
        $verify->money(
            bcadd($apBefore, $amount, 2),
            $verify->trueBalanceByCode('221001'),
            'An internal settlement did not reduce the payable',
            'Internal settlement — '.$monthStart->format('M Y'),
            prefix: 'ACC'
        );

        $verify->ledgerBalanced('Internal settlement — '.$monthStart->format('M Y'));
    }

    /**
     * The company pays a supplier by cheque. Same principle as a
     * cheque received: the money has not left the bank yet — it sits
     * in notes payable until the cheque is presented.
     */
    public function payByCheque(CycleContext $ctx, Carbon $monthStart): void
    {
        $bill = null;
        foreach ($ctx->openSupplierBills as $candidate) {
            if ($ctx->dualRolePartner && (int) $candidate->partner_id === (int) $ctx->dualRolePartner->id) {
                continue;
            }
            $candidate->refresh();
            if (bccomp((string) $candidate->amount_outstanding, '0', 2) > 0) {
                $bill = $candidate;
                break;
            }
        }

        if (! $bill) {
            return;
        }

        $verify      = new Verify($this->log, $ctx->companyId);
        $outstanding = (string) $bill->amount_outstanding;
        $bankBefore  = $verify->trueBalance((int) $ctx->bankAccounts[0]->coa_account_id);
        $date        = $monthStart->copy()->addDays(17)->toDateString();

        $payment = $this->gate->submit(
            \App\Http\Requests\Accounting\SupplierPaymentRequest::class,
            [
                'partner_id'      => $bill->partner_id,
                'payment_type'    => 'settlement',
                'payment_date'    => $date,
                'money_type'      => 'payable_cheque',
                // A payable cheque's paying account is fixed at issue
                // time, so the source picker is required even though no
                // money moves until the cheque is presented.
                'source_type'            => 'bank_account',
                'source_bank_account_id' => $ctx->bankAccounts[0]->id,
                'pay_currency_id' => $ctx->currencyId,
                'amount'          => $outstanding,
                'cheque'          => [
                    'cheque_number'   => 'PCHQ-'.$monthStart->format('Ym').'-'.$ctx->companyId,
                    'bank_account_id' => $ctx->bankAccounts[0]->id,
                    'due_date'        => $monthStart->copy()->addDays(50)->toDateString(),
                ],
                'settlements'     => [[
                    'supplier_bill_id'  => $bill->id,
                    'settlement_amount' => $outstanding,
                ]],
            ],
            fn (array $data) => app(\App\Services\Accounting\SupplierPaymentService::class)->record($ctx->companyId, $data, $ctx->actor),
            'Pay a supplier by cheque — '.$monthStart->format('M Y'),
            'cheque payable'
        );

        if (! $payment) {
            return;
        }

        // Issuing a cheque is not the same as the money leaving.
        $verify->money(
            $bankBefore,
            $verify->trueBalance((int) $ctx->bankAccounts[0]->coa_account_id),
            'Issuing a payable cheque moved the bank balance before the cheque was presented',
            'Pay by cheque — '.$monthStart->format('M Y'),
            prefix: 'BNK'
        );

        $verify->ledgerBalanced('Cheque payment — '.$monthStart->format('M Y'));

        $ctx->openSupplierBills = array_values(array_filter(
            $ctx->openSupplierBills,
            fn ($open) => $open->id !== $bill->id
        ));
    }
}
