<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditL27ServiceInvoiceTest
//
//  A customer invoice with no sales order posts a service: debit
//  receivable, credit revenue, equal amounts. A stock line without
//  an order is refused. An invoice that still has an order is still
//  stopped when it goes past the order's value.
//
//  Hand-worked service invoice: 2 × 50.00 = 100.00, no VAT.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\SOValueExceededException;
use App\Models\JournalEntryLine;
use App\Services\Accounting\CustomerInvoiceService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditL27ServiceInvoiceTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->bootstrap();
    }

    public function test_a_service_invoice_without_a_sales_order_posts_a_balanced_entry(): void
    {
        $revenueId = $this->scenario->products[0]->revenue_account_id;

        $invoice = app(CustomerInvoiceService::class)->create($this->scenario->companyId, [
            'partner_id'   => $this->scenario->customers[0]->id,
            'invoice_date' => '2026-02-01',
            'currency_id'  => $this->scenario->currencyId,
            'exchange_rate'=> 1,
            'lines'        => [[
                'description'        => 'Consulting',
                'quantity'           => 2,
                'unit_price'         => '50.00',
                'vat_rate'           => 0,
                'revenue_account_id' => $revenueId,
            ]],
        ], $this->actor);

        $posted = app(CustomerInvoiceService::class)->post($invoice, $this->actor);

        $this->assertNull($posted->sales_order_id);
        $this->assertLedgerBalanced($this->scenario->companyId);
        $this->assertMoneyEquals('100.00', (string) $posted->invoice_value);

        $lines = JournalEntryLine::where('journal_entry_id', $posted->journal_entry_id)->get();
        $this->assertMoneyEquals('100.00', (string) $lines->sum('debit'));
        $this->assertMoneyEquals('100.00', (string) $lines->where('chart_of_account_id', $revenueId)->sum('credit'));
    }

    public function test_a_goods_line_without_a_sales_order_is_refused(): void
    {
        $product = $this->scenario->products[0];
        $this->assertTrue((bool) $product->has_stock);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('goods line');

        app(CustomerInvoiceService::class)->create($this->scenario->companyId, [
            'partner_id'   => $this->scenario->customers[0]->id,
            'invoice_date' => '2026-02-01',
            'currency_id'  => $this->scenario->currencyId,
            'lines'        => [[
                'product_id'         => $product->id,
                'description'        => $product->name_en,
                'quantity'           => 1,
                'unit_price'         => '10.00',
                'revenue_account_id' => $product->revenue_account_id,
            ]],
        ], $this->actor);
    }

    public function test_an_invoice_with_a_sales_order_still_stops_at_the_order_value(): void
    {
        $product = $this->scenario->products[0];
        $so = $this->scenario->createSalesOrder([[$product, 1, 10]], '2026-02-01');
        $soLine = $so->lines->first();

        $invoice = app(CustomerInvoiceService::class)->create($this->scenario->companyId, [
            'sales_order_id' => $so->id,
            'partner_id'     => $this->scenario->customers[0]->id,
            'invoice_date'   => '2026-02-01',
            'currency_id'    => $this->scenario->currencyId,
            'exchange_rate'  => 1,
            'lines'          => [[
                'sales_order_line_id' => $soLine->id,
                'product_id'          => $product->id,
                'description'         => $product->name_en,
                'quantity'            => 1,
                'unit_price'          => '100.00',
                'revenue_account_id'  => $product->revenue_account_id,
            ]],
        ], $this->actor);

        $this->expectException(SOValueExceededException::class);
        app(CustomerInvoiceService::class)->post($invoice, $this->actor);
    }
}
