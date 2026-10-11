<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditL7DocumentNumberTest
//
//  Sales orders, purchase orders, and goods receipts take their
//  number from a locked monthly sequence. Two documents in one
//  month are 00001 and 00002. Deleting the first does not hand
//  00001 out again.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Services\Accounting\GoodsReceiptNoteService;
use App\Services\Accounting\PurchaseOrderService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditL7DocumentNumberTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->bootstrap();
    }

    public function test_sales_order_numbers_do_not_reuse_a_deleted_code(): void
    {
        $product = $this->scenario->products[0];
        $first = $this->scenario->createSalesOrder([[$product, 1, 10]], '2026-04-01');
        $second = $this->scenario->createSalesOrder([[$product, 1, 10]], '2026-04-15');

        $this->assertSame('SO/2026/04/00001', $first->so_code);
        $this->assertSame('SO/2026/04/00002', $second->so_code);

        $first->lines()->delete();
        $first->delete();

        $third = $this->scenario->createSalesOrder([[$product, 1, 10]], '2026-04-20');
        $this->assertSame('SO/2026/04/00003', $third->so_code);
    }

    public function test_purchase_order_numbers_do_not_reuse_a_deleted_code(): void
    {
        $first = $this->purchaseOrder('2026-04-01');
        $second = $this->purchaseOrder('2026-04-15');

        $this->assertSame('PO/2026/04/00001', $first->po_code);
        $this->assertSame('PO/2026/04/00002', $second->po_code);

        $first->lines()->delete();
        $first->delete();

        $this->assertSame('PO/2026/04/00003', $this->purchaseOrder('2026-04-20')->po_code);
    }

    public function test_goods_receipt_numbers_do_not_reuse_a_deleted_code(): void
    {
        $po = $this->purchaseOrder('2026-04-01');
        $first = $this->receipt($po, '2026-04-02');
        $second = $this->receipt($po, '2026-04-03');

        $this->assertSame('GRN/2026/04/00001', $first->grn_code);
        $this->assertSame('GRN/2026/04/00002', $second->grn_code);

        $first->lines()->delete();
        $first->delete();

        $this->assertSame('GRN/2026/04/00003', $this->receipt($po, '2026-04-04')->grn_code);
    }

    private function purchaseOrder(string $date): object
    {
        $product = $this->scenario->products[0];

        return app(PurchaseOrderService::class)->create($this->scenario->companyId, [
            'partner_id'      => $this->scenario->suppliers[0]->id,
            'subject'         => 'Numbering test',
            'currency_id'     => $this->scenario->currencyId,
            'exchange_rate'   => 1,
            'issuance_date'   => $date,
            'start_date'      => $date,
            'end_date'        => $date,
            'payment_term_id' => $this->scenario->paymentTermId,
            'lines'           => [[
                'product_id'  => $product->id,
                'description' => $product->name_en,
                'quantity'    => 1,
                'uom_id'      => $product->primary_uom_id,
                'unit_price'  => 10,
            ]],
        ], $this->actor);
    }

    private function receipt(object $po, string $date): object
    {
        $line = $po->lines->first();

        return app(GoodsReceiptNoteService::class)->create($this->scenario->companyId, [
            'purchase_order_id'     => $po->id,
            'partner_id'            => $po->partner_id,
            'inventory_location_id' => $this->scenario->masterLocationId,
            'received_date'         => $date,
            'lines'                 => [[
                'purchase_order_line_id' => $line->id,
                'product_id'             => $line->product_id,
                'quantity'               => 1,
                'uom_id'                 => $line->uom_id,
                'unit_cost'              => $line->unit_price,
            ]],
        ], $this->actor);
    }
}
