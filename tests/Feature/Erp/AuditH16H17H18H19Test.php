<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditH16H17H18H19Test
//
//  Regression tests for four High audit findings fixed in
//  September 2026:
//
//    H16 — Sales and Purchase Returns. A customer return puts the
//          goods back at their original delivery cost and reverses the
//          revenue the right way (accrual when not yet invoiced; an
//          automatic Credit Note, or re-deferral for a replacement,
//          when invoiced). A supplier return takes goods out at their
//          receipt cost, re-opens GRNI, and raises a Debit Note for
//          the billed part. Reopen / cancel guards hold.
//    H17 — Fixed asset disposal: a full month of depreciation in the
//          month of sale, gain to 424, asset and accumulated
//          depreciation cleared; cancel restores it.
//    H18 — An annual maintenance invoice is spread month by month
//          through 236 Deferred Revenue.
//    H19 — P&L shows Operating Profit, Profit Before Tax, Income Tax
//          and Profit After Tax; the tax provision sits in 226003.
//
//  Every step goes through the real services (see ErpScenario).
//  Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\CreditNote;
use App\Models\CustomerInvoice;
use App\Models\FixedAsset;
use App\Models\Product;
use App\Models\ProductStockBalance;
use App\Models\PurchaseOrderLine;
use App\Models\RevenueScheduleLine;
use App\Models\SalesOrderLine;
use App\Models\SalesReturn;
use App\Models\SupplierBill;
use App\Models\SupplierDebitNote;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\DeliveryNoteService;
use App\Services\Accounting\FixedAssetDisposalService;
use App\Services\Accounting\FixedAssetService;
use App\Services\Accounting\IncomeStatementService;
use App\Services\Accounting\IncomeTaxService;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\PurchaseReturnService;
use App\Services\Accounting\RevenueScheduleService;
use App\Services\Accounting\SalesReturnService;
use App\Services\Accounting\SupplierDebitNoteService;
use App\Services\ChartOfAccountService;
use App\Services\ProductService;
use App\Services\Projects\SalesOrderService;
use Illuminate\Support\Carbon;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditH16H17H18H19Test extends ErpTestCase
{
    private ErpScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = (new ErpScenario($this->actor))->bootstrap(1);
    }

    // ══ H16 — Sales Returns ══════════════════════════════════════

    /**
     * 100 bought at 20, 40 delivered at 75, 10 come back BEFORE any
     * invoice. Expect: stock 70, COGS 30 × 20 = 600, revenue and
     * accrued revenue 30 × 75 = 2,250.
     */
    public function test_uninvoiced_sales_return_goes_back_at_delivery_cost_and_reverses_the_accrual(): void
    {
        [$product, $so, $dn] = $this->soldAndDelivered();

        $return = $this->salesReturn($dn, 10, SalesReturn::TREATMENT_CREDIT);

        $cid = $this->s->companyId;
        $this->assertQuantityEquals('70', (string) ProductStockBalance::where('company_id', $cid)->where('product_id', $product->id)->value('quantity_on_hand'));
        $this->assertMoneyEquals('1400.00', $this->glBalanceById($cid, (int) $product->inventory_account_id), 'H16: 60 left + 10 back, all at 20.');
        $this->assertMoneyEquals('600.00', $this->glBalanceById($cid, (int) $product->cogs_account_id), 'H16: COGS for 30 units only.');
        $this->assertMoneyEquals('-2250.00', $this->glBalanceById($cid, (int) $product->revenue_account_id));
        $this->assertMoneyEquals('2250.00', $this->glBalance($cid, '143'), 'H16: accrued revenue reduced by 10 × 75.');
        $this->assertQuantityEquals('30', (string) SalesOrderLine::find($so->lines[0]->id)->delivered_quantity);
        $this->assertEmpty($return->credit_note_ids, 'H16: nothing invoiced, so no credit note.');
        $this->assertLedgerBalanced($cid);

        // Delivery can no longer be reopened while the return stands.
        $this->expectDomain(fn () => app(DeliveryNoteService::class)->setToDraft($dn->fresh(), $this->actor));

        // Cancel puts everything back.
        app(SalesReturnService::class)->cancel($cid, $return, 'Typed the wrong delivery', $this->actor);
        $this->assertMoneyEquals('1200.00', $this->glBalanceById($cid, (int) $product->inventory_account_id));
        $this->assertMoneyEquals('800.00', $this->glBalanceById($cid, (int) $product->cogs_account_id));
        $this->assertMoneyEquals('3000.00', $this->glBalance($cid, '143'));
        $this->assertQuantityEquals('40', (string) SalesOrderLine::find($so->lines[0]->id)->delivered_quantity);
        $this->assertLedgerBalanced($cid);
    }

    /**
     * Same sale, invoiced at 14% VAT (AR 3,420). 10 come back and the
     * customer is credited: an automatic credit note of 750 + 105 VAT.
     */
    public function test_invoiced_sales_return_raises_and_posts_a_credit_note(): void
    {
        [$product, $so, $dn] = $this->soldAndDelivered();
        $invoice = $this->s->invoice($so, '2026-02-05', vatRate: 14);
        $cid = $this->s->companyId;

        $return = $this->salesReturn($dn, 10, SalesReturn::TREATMENT_CREDIT);

        $this->assertCount(1, $return->credit_note_ids ?? []);
        $this->assertSame(CreditNote::STATUS_POSTED, CreditNote::find($return->credit_note_ids[0])->status);
        $this->assertMoneyEquals('2565.00', $this->glBalance($cid, '133001'), 'H16: AR 3,420 − 855.');
        $this->assertMoneyEquals('-315.00', $this->glBalance($cid, '226001'), 'H16: VAT 420 − 105.');
        $this->assertMoneyEquals('-2250.00', $this->glBalanceById($cid, (int) $product->revenue_account_id));
        $this->assertMoneyEquals('600.00', $this->glBalanceById($cid, (int) $product->cogs_account_id));
        $this->assertMoneyEquals('0.00', $this->glBalance($cid, '143'));
        $this->assertMoneyEquals('2565.00', (string) CustomerInvoice::find($invoice->id)->amount_outstanding);
        $this->assertQuantityEquals('30', (string) SalesOrderLine::find($so->lines[0]->id)->invoiced_quantity);
        $this->assertLedgerBalanced($cid);

        // A credit note cannot be undone, so neither can the return — nor the invoice.
        $this->expectDomain(fn () => app(SalesReturnService::class)->cancel($cid, $return, 'x', $this->actor));
        $this->expectDomain(fn () => app(CustomerInvoiceService::class)->setToDraft(CustomerInvoice::find($invoice->id), $this->actor));
    }

    /**
     * Invoiced, then 10 come back for REPLACEMENT: revenue for them is
     * parked in 236 (750). The replacement delivery releases it — so
     * revenue ends at the full 3,000 and 236 at zero.
     */
    public function test_replacement_return_parks_revenue_in_deferred_until_redelivered(): void
    {
        [$product, $so, $dn] = $this->soldAndDelivered();
        $this->s->invoice($so, '2026-02-05', vatRate: 14);
        $cid = $this->s->companyId;

        $return = $this->salesReturn($dn, 10, SalesReturn::TREATMENT_REPLACE);

        $this->assertEmpty($return->credit_note_ids);
        $this->assertMoneyEquals('-750.00', $this->glBalance($cid, '236'), 'H16: billed but not with the customer — deferred.');
        $this->assertMoneyEquals('-2250.00', $this->glBalanceById($cid, (int) $product->revenue_account_id));
        $this->assertMoneyEquals('3420.00', $this->glBalance($cid, '133001'), 'H16: the customer is still billed.');

        // Replacement delivery of the 10.
        $dnSvc = app(DeliveryNoteService::class);
        $replacement = $dnSvc->create($cid, [
            'sales_order_id' => $so->id, 'partner_id' => $so->partner_id, 'delivery_date' => '2026-02-20',
            'lines' => [['sales_order_line_id' => $so->lines[0]->id, 'location_id' => $this->masterLocation(), 'quantity' => 10, 'uom_id' => $so->lines[0]->uom_id]],
        ], $this->actor);
        $dnSvc->post($replacement, $this->actor);

        $this->assertMoneyEquals('0.00', $this->glBalance($cid, '236'));
        $this->assertMoneyEquals('-3000.00', $this->glBalanceById($cid, (int) $product->revenue_account_id));
        $this->assertMoneyEquals('800.00', $this->glBalanceById($cid, (int) $product->cogs_account_id));
        $this->assertLedgerBalanced($cid);

        // The replacement relies on the return — it can no longer be cancelled.
        $this->expectDomain(fn () => app(SalesReturnService::class)->cancel($cid, $return, 'x', $this->actor));
    }

    public function test_cannot_return_more_than_was_delivered(): void
    {
        [, , $dn] = $this->soldAndDelivered();
        $this->expectDomain(fn () => $this->salesReturn($dn, 41, SalesReturn::TREATMENT_CREDIT));
        $this->assertSame(0, SalesReturn::count(), 'H16: a refused return leaves nothing behind.');
    }

    // ══ H16 — Purchase Returns ═══════════════════════════════════

    /** 200 received at 35, 50 sent back before billing. */
    public function test_unbilled_purchase_return_reduces_stock_and_grni(): void
    {
        $product = $this->s->products[0];
        $grn = $this->s->receiveStock([[$product, 200, 35.00]], '2026-01-01');
        $cid = $this->s->companyId;

        $return = $this->purchaseReturn($grn, 50);

        $this->assertMoneyEquals('5250.00', $this->glBalanceById($cid, (int) $product->inventory_account_id));
        $this->assertMoneyEquals('-5250.00', $this->glBalance($cid, '233'), 'H16: GRNI now for the 150 kept.');
        $this->assertQuantityEquals('150', (string) ProductStockBalance::where('company_id', $cid)->where('product_id', $product->id)->value('quantity_on_hand'));
        $this->assertQuantityEquals('150', (string) PurchaseOrderLine::find($grn->lines[0]->purchase_order_line_id)->received_quantity);
        $this->assertEmpty($return->debit_note_ids);
        $this->assertLedgerBalanced($cid);

        // Cancel restores it all.
        app(PurchaseReturnService::class)->cancel($cid, $return, 'Supplier refused the return', $this->actor);
        $this->assertMoneyEquals('7000.00', $this->glBalanceById($cid, (int) $product->inventory_account_id));
        $this->assertMoneyEquals('-7000.00', $this->glBalance($cid, '233'));
        $this->assertLedgerBalanced($cid);
    }

    /**
     * 200 at 35 billed at 14% (AP 7,980). 50 go back: a debit note of
     * 1,750 + 245 VAT is posted automatically; GRNI stays at zero.
     */
    public function test_billed_purchase_return_raises_a_debit_note(): void
    {
        $product = $this->s->products[0];
        $grn = $this->s->receiveStock([[$product, 200, 35.00]], '2026-01-01');
        $bill = $this->s->billGrn($grn, '2026-01-05', vatRate: 14, whtRate: 0);
        $cid = $this->s->companyId;

        $return = $this->purchaseReturn($grn, 50);

        $this->assertCount(1, $return->debit_note_ids ?? []);
        $this->assertSame(SupplierDebitNote::STATUS_POSTED, SupplierDebitNote::find($return->debit_note_ids[0])->status);
        $this->assertMoneyEquals('0.00', $this->glBalance($cid, '233'), 'H16: re-opened by the return, cleared by the debit note.');
        $this->assertMoneyEquals('-5985.00', $this->glBalance($cid, '221001'), 'H16: AP 7,980 − 1,995.');
        $this->assertMoneyEquals('735.00', $this->glBalance($cid, '137'), 'H16: VAT input 980 − 245.');
        $this->assertMoneyEquals('5250.00', $this->glBalanceById($cid, (int) $product->inventory_account_id));
        $this->assertMoneyEquals('5985.00', (string) SupplierBill::find($bill->id)->amount_outstanding);
        $this->assertLedgerBalanced($cid);

        $this->expectDomain(fn () => app(PurchaseReturnService::class)->cancel($cid, $return, 'x', $this->actor));
        $this->expectDomain(fn () => app(\App\Services\Accounting\SupplierBillService::class)->setToDraft(SupplierBill::find($bill->id), $this->actor));
    }

    public function test_debit_note_on_goods_still_in_stock_is_refused(): void
    {
        $product = $this->s->products[0];
        $grn = $this->s->receiveStock([[$product, 200, 35.00]], '2026-01-01');
        $bill = $this->s->billGrn($grn, '2026-01-05');
        $billLine = $bill->lines()->first();

        $this->expectDomain(fn () => app(SupplierDebitNoteService::class)->create($this->s->companyId, [
            'supplier_bill_id' => $bill->id, 'debit_date' => '2026-01-10', 'debit_reason' => 'Price reduction',
            'lines' => [['supplier_bill_line_id' => $billLine->id, 'description' => 'x', 'quantity' => 10, 'unit_price' => 35, 'vat_rate' => 14]],
        ], $this->actor));
    }

    // ══ H17 — Fixed asset disposal ═══════════════════════════════

    /**
     * Asset 12,000, 12 months, from January. January depreciation is
     * posted; sold on 15 February for 11,000. February gets a FULL month
     * (1,000) → accumulated 2,000, NBV 10,000, gain 1,000 to 424.
     */
    public function test_disposal_posts_full_month_depreciation_and_gain(): void
    {
        $cid = $this->s->companyId;
        $asset = $this->fixedAsset('12000', 12, '2026-01-01');
        app(FixedAssetService::class)->postMonthlyDepreciation($asset, $this->actor, Carbon::parse('2026-01-31'));

        $safe = $this->s->createCashSafe();
        $svc = app(FixedAssetDisposalService::class);
        $preview = $svc->preview($cid, $asset->fresh(), '2026-02-15');
        $this->assertMoneyEquals('1000.00', $preview['month_depreciation']);
        $this->assertMoneyEquals('10000.00', $preview['net_book_value']);

        $disposal = $svc->dispose($cid, $asset->fresh(), [
            'disposal_date' => '2026-02-15', 'disposal_type' => 'sale', 'proceeds' => '11000', 'proceeds_account_id' => $safe,
        ], $this->actor);

        $this->assertMoneyEquals('1000.00', (string) $disposal->gain_loss);
        $this->assertMoneyEquals('-1000.00', $this->glBalance($cid, '424'), 'H17: gain on disposal.');
        $this->assertMoneyEquals('2000.00', $this->glBalance($cid, '561'), 'H17: January + a full February.');
        $this->assertMoneyEquals('0.00', $this->glBalanceById($cid, (int) $asset->fixed_asset_account_id), 'H17: cost removed.');
        $this->assertMoneyEquals('0.00', $this->glBalanceById($cid, (int) $asset->accumulated_depreciation_account_id), 'H17: accumulated depreciation removed.');
        $this->assertMoneyEquals('11000.00', $this->glBalanceById($cid, $safe));
        $this->assertSame(FixedAsset::STATUS_DISPOSED, $asset->fresh()->status);
        $this->assertLedgerBalanced($cid);

        $svc->cancelDisposal($cid, $disposal, 'Buyer backed out', $this->actor);
        $this->assertMoneyEquals('0.00', $this->glBalance($cid, '424'));
        $this->assertMoneyEquals('12000.00', $this->glBalanceById($cid, (int) $asset->fixed_asset_account_id));
        $this->assertSame(FixedAsset::STATUS_IN_SERVICE, $asset->fresh()->status);
        $this->assertLedgerBalanced($cid);
    }

    // ══ H18 — Annual maintenance invoice spread over the contract ═

    /**
     * 12,000 for a maintenance contract 1 Jan – 31 Dec 2026, invoiced
     * in January. January's share (1,000) is recognised; 11,000 waits
     * in 236 and is released month by month.
     */
    public function test_annual_service_invoice_is_spread_monthly(): void
    {
        $cid = $this->s->companyId;
        $service = app(ProductService::class)->create($cid, [
            'use_auto_code' => true, 'name_en' => 'Annual Maintenance', 'category_id' => $this->s->products[0]->category_id,
            'usage_types' => [Product::TYPE_SALEABLE], 'nature' => Product::NATURE_SERVICE, 'primary_uom_id' => $this->s->products[0]->primary_uom_id,
        ], $this->actor);
        $this->assertFalse((bool) $service->has_stock);

        $so = $this->s->createSalesOrder([[$service, 1, 12000.00]], '2026-01-01');
        $line = $so->lines[0];
        $invSvc = app(CustomerInvoiceService::class);
        $invoice = $invSvc->create($cid, [
            'sales_order_id' => $so->id, 'partner_id' => $so->partner_id, 'invoice_date' => '2026-01-10',
            'currency_id' => $so->currency_id, 'payment_term_id' => $so->payment_term_id,
            'lines' => [[
                'sales_order_line_id' => $line->id, 'product_id' => $service->id, 'description' => 'Maintenance 2026',
                'quantity' => 1, 'uom_id' => $line->uom_id, 'unit_price' => 12000, 'vat_rate' => 0,
                'service_period_start' => '2026-01-01', 'service_period_end' => '2026-12-31',
            ]],
        ], $this->actor);
        $invoice = $invSvc->post($invoice, $this->actor);

        $this->assertMoneyEquals('12000.00', $this->glBalance($cid, '133001'));
        $revenue = (int) $line->revenue_account_id;
        $sliceTotal = (string) RevenueScheduleLine::sum('amount');
        $this->assertMoneyEquals('12000.00', $sliceTotal, 'H18: the slices add back to the invoice exactly.');
        $this->assertSame(12, RevenueScheduleLine::count());

        // January (already over at the invoice month-end) is released with the posting run.
        app(RevenueScheduleService::class)->releaseUpTo($cid, '2026-03-31', $this->actor);
        $released = bcmul($this->glBalanceById($cid, $revenue), '-1', 2);
        $deferred = bcmul($this->glBalance($cid, '236'), '-1', 2);
        $this->assertMoneyEquals('12000.00', bcadd($released, $deferred, 2), 'H18: recognised + deferred = invoice.');
        $this->assertTrue(bccomp($released, '2900', 2) > 0 && bccomp($released, '3000', 2) <= 0, "H18: about three months recognised by 31 March (got {$released}).");
        $this->assertLedgerBalanced($cid);

        // Reopening the invoice reverses the released months and cancels the schedule.
        $invSvc->setToDraft(CustomerInvoice::find($invoice->id), $this->actor);
        $this->assertMoneyEquals('0.00', $this->glBalanceById($cid, $revenue), 'H18: released revenue reversed.');
        $this->assertMoneyEquals('0.00', $this->glBalance($cid, '236'), 'H18: nothing left deferred.');
        $this->assertSame(0, RevenueScheduleLine::where('status', RevenueScheduleLine::STATUS_PENDING)->count());
        $this->assertLedgerBalanced($cid);
    }

    // ══ H19 — P&L subtotals and income tax ═══════════════════════

    public function test_income_statement_shows_tax_below_profit_before_tax(): void
    {
        [$product, $so] = $this->soldAndDelivered();
        $cid = $this->s->companyId;

        app(IncomeTaxService::class)->recordProvision($cid, ['entry_date' => '2026-02-28', 'current_tax' => '495'], $this->actor);

        $pl = app(IncomeStatementService::class)->build($cid, [[
            'key' => 'p', 'label' => 'Feb', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ]]);

        // Revenue 3,000 − COGS 800 = 2,200 → PBT 2,200 → tax 495 → PAT 1,705.
        $this->assertMoneyEquals('2200.00', $pl['operating_profit']['p']);
        $this->assertMoneyEquals('2200.00', $pl['profit_before_tax']['p']);
        $this->assertMoneyEquals('495.00', $pl['income_tax']['totals']['p']);
        $this->assertMoneyEquals('1705.00', $pl['profit_after_tax']['p']);
        $this->assertMoneyEquals('-495.00', $this->glBalance($cid, '226003'), 'H19: the tax owed is a liability.');

        // No Debit WHT yet → nothing to set off.
        $this->expectDomain(fn () => app(IncomeTaxService::class)->offsetWithholdingTax($cid, ['entry_date' => '2026-02-28', 'amount' => '10'], $this->actor));
        $this->assertLedgerBalanced($cid);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /** 100 bought at 20; 40 sold at 75 and delivered on 5 Feb. */
    private function soldAndDelivered(): array
    {
        $product = $this->s->products[0];
        $this->s->receiveStock([[$product, 100, 20.00]], '2026-01-01');
        $so = $this->s->createSalesOrder([[$product, 40, 75.00]], '2026-02-01');
        $dn = $this->s->deliver($so, '2026-02-05');

        return [$product, $so, $dn];
    }

    private function salesReturn(object $dn, int $qty, string $treatment): SalesReturn
    {
        $line = $dn->lines()->first();

        return app(SalesReturnService::class)->create($this->s->companyId, [
            'delivery_note_id' => $dn->id, 'return_date' => '2026-02-10', 'invoiced_treatment' => $treatment,
            'lines' => [['delivery_note_line_id' => $line->id, 'quantity' => $qty]],
        ], $this->actor);
    }

    private function purchaseReturn(object $grn, int $qty): \App\Models\PurchaseReturn
    {
        return app(PurchaseReturnService::class)->create($this->s->companyId, [
            'goods_receipt_note_id' => $grn->id, 'return_date' => '2026-01-20',
            'lines' => [['goods_receipt_note_line_id' => $grn->lines()->first()->id, 'quantity' => $qty]],
        ], $this->actor);
    }

    private function fixedAsset(string $cost, int $months, string $start): FixedAsset
    {
        $cid = $this->s->companyId;
        $coa = app(ChartOfAccountService::class);
        $assetAcc = $coa->create($cid, ['parent_id' => $this->s->coaId('111'), 'use_auto_code' => true, 'name_en' => 'Test Machinery'], $this->actor);
        $accDep   = $coa->create($cid, ['parent_id' => $this->s->coaId('113'), 'use_auto_code' => true, 'name_en' => 'Acc. Dep. Test Machinery'], $this->actor);

        $asset = FixedAsset::create([
            'company_id' => $cid, 'asset_code' => FixedAsset::nextCode($cid), 'name' => 'Test Machine',
            'fixed_asset_account_id' => $assetAcc->id, 'accumulated_depreciation_account_id' => $accDep->id,
            'currency_id' => $this->s->currencyId, 'inventory_location_id' => $this->masterLocation(),
            'cost_basis' => $cost, 'residual_value' => 0, 'useful_life_months' => $months, 'depreciation_method' => 'straight_line',
            'received_date' => $start, 'depreciation_start_date' => $start, 'accumulated_depreciation' => 0,
            'status' => FixedAsset::STATUS_IN_SERVICE, 'created_by' => $this->actor->id,
        ]);

        // Paid for with equity, to keep the test's ledger balanced.
        app(JournalEntryService::class)->post($cid, $start, [
            ['chart_of_account_id' => $assetAcc->id, 'debit' => $cost, 'credit' => '0', 'description' => 'Asset bought'],
            ['chart_of_account_id' => $this->s->coaId('314'), 'debit' => '0', 'credit' => $cost, 'description' => 'Asset bought'],
        ], 'manual', null, 'Test asset', $this->actor);

        return $asset;
    }

    private function masterLocation(): int
    {
        return (int) $this->s->masterLocationId;
    }

    private function expectDomain(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected the action to be refused.');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }
    }
}
