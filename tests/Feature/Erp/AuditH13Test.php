<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditH13Test
//
//  Regression tests for Audit H13 (Sept 2026) — Sales Analytics:
//    1. amounts are in the FUNCTIONAL currency (a USD invoice is
//       converted at its own rate, never added to EGP as-is);
//    2. posted credit notes are SUBTRACTED (net sales, not gross);
//    3. two customers with the same name are NOT merged.
//
//  Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\CompanyActiveCurrency;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\Accounting\CreditNoteService;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Analytics\SalesAnalysisService;
use App\Services\PartnerService;
use App\Services\Projects\SalesOrderService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditH13Test extends ErpTestCase
{
    private ErpScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = (new ErpScenario($this->actor))->bootstrap(1);
        $this->s->receiveStock([[$this->s->products[0], 50, 10.00]], '2026-01-10');
    }

    public function test_usd_invoice_is_reported_in_functional_currency(): void
    {
        $this->egpInvoice(2, 50.00);                 // EGP 100
        $this->usdInvoice(4, 20.00, 50);             // USD 80 × 50 = EGP 4,000

        $kpis = app(SalesAnalysisService::class)->dashboardKpis($this->s->companyId, '2026-01-01', '2026-12-31');

        $this->assertEqualsWithDelta(4100.0, $kpis['net_sales'], 0.001,
            'H13: USD 80 at 50 is EGP 4,000 — not 80 added to EGP 100.');
    }

    public function test_credit_note_is_subtracted_from_net_sales(): void
    {
        $invoice = $this->egpInvoice(2, 50.00);      // EGP 100
        $line    = $invoice->lines()->first();

        $cn = app(CreditNoteService::class)->create($this->s->companyId, [
            'customer_invoice_id' => $invoice->id, 'credit_date' => '2026-02-20', 'credit_reason' => 'Price reduction',
            'lines' => [[
                'customer_invoice_line_id' => $line->id, 'description' => 'Discount',
                'quantity' => 1, 'unit_price' => 20, 'vat_rate' => 0,
            ]],
        ], $this->actor);
        app(CreditNoteService::class)->post($cn, $this->actor);

        $svc  = app(SalesAnalysisService::class);
        $kpis = $svc->dashboardKpis($this->s->companyId, '2026-01-01', '2026-12-31');
        $this->assertEqualsWithDelta(80.0, $kpis['net_sales'], 0.001, 'H13: net sales = 100 invoiced − 20 credited.');
        $this->assertSame(1, $kpis['invoice_count'], 'H13: a credit note is not counted as an invoice.');

        $byCustomer = $svc->singleDimension($this->s->companyId, [
            'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'dimension1' => 'customer', 'metric' => 'quantity',
        ]);
        $this->assertEqualsWithDelta(2.0, $byCustomer['total'], 0.0001, 'H13: a value-only credit note does not change units invoiced.');
    }

    public function test_two_customers_with_the_same_name_are_not_merged(): void
    {
        $this->egpInvoice(2, 50.00);

        $twin = app(PartnerService::class)->create($this->s->companyId, [
            'name_en' => $this->s->customers[0]->name_en, 'types' => ['customer'],
            'credit_limit' => 10_000_000, 'collection_term_id' => $this->s->paymentTermId,
        ], $this->actor);
        $this->s->customers[0] = $twin;
        $this->egpInvoice(3, 50.00);

        $report = app(SalesAnalysisService::class)->singleDimension($this->s->companyId, [
            'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'dimension1' => 'customer', 'metric' => 'net_amount',
        ]);

        $this->assertCount(2, $report['rows'], 'H13: same-name customers stay two rows.');
        $this->assertNotSame($report['rows'][0]['label'], $report['rows'][1]['label'], 'H13: labels carry the customer code.');

        $nature = app(SalesAnalysisService::class)->customerNature($this->s->companyId, ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']);
        $this->assertSame(2, $nature['buckets']['new']['count'], 'H13: Customer Nature counts customers by ID, not by name.');
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function egpInvoice(int $qty, float $price): object
    {
        $so = $this->s->createSalesOrder([[$this->s->products[0], $qty, $price]], '2026-02-01');
        $this->s->deliver($so, '2026-02-05');

        return $this->s->invoice($so, '2026-02-05', 0);
    }

    private function usdInvoice(int $qty, float $price, float $rate): object
    {
        $cid     = $this->s->companyId;
        $usd     = (int) Currency::withoutGlobalScopes()->whereNull('company_id')->where('code', 'USD')->value('id');
        CompanyActiveCurrency::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $cid, 'currency_id' => $usd],
            ['is_functional' => false, 'is_active' => true]
        );
        ExchangeRate::create([
            'company_id' => $cid, 'currency_id' => $usd, 'rate' => (string) $rate,
            'rate_date' => '2026-01-01', 'source' => ExchangeRate::SOURCE_MANUAL,
            'created_by' => $this->actor->id, 'created_at' => now(),
        ]);
        $product = $this->s->products[0];

        $so = app(SalesOrderService::class)->create($cid, [
            'partner_id' => $this->s->customers[0]->id, 'subject' => 'USD order',
            'currency_id' => $usd, 'exchange_rate' => $rate,
            'issuance_date' => '2026-03-01', 'start_date' => '2026-03-01', 'end_date' => '2026-12-31',
            'payment_term_id' => $this->s->paymentTermId,
            'lines' => [[
                'product_id' => $product->id, 'description' => $product->name_en, 'quantity' => $qty,
                'uom_id' => $product->primary_uom_id, 'unit_price' => $price, 'revenue_account_id' => $product->revenue_account_id,
            ]],
        ], $this->actor);
        app(SalesOrderService::class)->activate($so, $this->actor);
        $so = $so->fresh('lines');
        $soLine = $so->lines->first();

        $this->s->deliver($so, '2026-03-05');

        $invoice = app(CustomerInvoiceService::class)->create($cid, [
            'sales_order_id' => $so->id, 'partner_id' => $so->partner_id, 'invoice_date' => '2026-03-05',
            'currency_id' => $usd, 'exchange_rate' => $rate, 'payment_term_id' => $this->s->paymentTermId,
            'lines' => [[
                'sales_order_line_id' => $soLine->id, 'product_id' => $product->id, 'description' => $product->name_en,
                'quantity' => $qty, 'uom_id' => $soLine->uom_id, 'unit_price' => $price, 'vat_rate' => 0,
            ]],
        ], $this->actor);

        return app(CustomerInvoiceService::class)->post($invoice, $this->actor, overrideSoLimit: true);
    }
}
