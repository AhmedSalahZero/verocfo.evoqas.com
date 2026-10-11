<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  PurchaseToPayTest
//
//  The purchase chain, end to end, with every figure calculated
//  independently in the test rather than read back from the
//  application.
//
//  The accounting shape under test is the standard three-way match:
//
//    Goods Receipt Note  DR Inventory
//                        CR Goods Received Not Invoiced (233)
//    Supplier Bill       DR Goods Received Not Invoiced (233)
//                        DR VAT Input (137)
//                        CR Accounts Payable (221001)
//                        CR Withholding Tax Payable (226002)
//
//  The point of the GRNI account is that goods and invoice arrive
//  separately; the tests below check that it clears exactly, which
//  is what proves the receipt is not being expensed twice.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ProductStockBalance;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class PurchaseToPayTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->bootstrap(productCount: 1);
    }

    /**
     * Receiving goods debits inventory and parks the liability in
     * GRNI — it must NOT touch Accounts Payable, because no supplier
     * invoice exists yet.
     *
     * Independent expectation: 200 units at 35.00 = 7,000.00.
     */
    public function test_goods_receipt_debits_inventory_and_credits_grni_only(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 200, 35.00]], '2026-01-01');

        $expected = bcmul('200', '35.00', 2); // 7000.00

        $this->assertMoneyEquals(
            $expected,
            $this->glBalanceById($this->scenario->companyId, (int) $product->inventory_account_id),
            'Inventory must be debited with the received value.'
        );

        // GRNI is a liability: credit balance, so the signed
        // debit-minus-credit figure is negative.
        $this->assertMoneyEquals(
            bcmul($expected, '-1', 2),
            $this->glBalance($this->scenario->companyId, '233'),
            'GRNI must carry the full received value as a credit.'
        );

        $this->assertMoneyEquals(
            '0.00',
            $this->glBalance($this->scenario->companyId, '221001'),
            'Accounts Payable must be untouched until the bill arrives.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId);
    }

    /**
     * Billing the receipt clears GRNI to exactly zero and raises AP.
     * A non-zero GRNI residue here would mean the goods value and
     * the invoice value had diverged.
     *
     * Independent expectation, 200 @ 35.00 with 14% VAT and 1% WHT:
     *   goods                       7,000.00
     *   VAT input        7,000 * 14% = 980.00
     *   withholding tax  7,000 *  1% =  70.00
     *   accounts payable  7,000 + 980 - 70 = 7,910.00
     */
    public function test_billing_the_receipt_clears_grni_and_raises_payable(): void
    {
        $product = $this->scenario->products[0];

        $grn = $this->scenario->receiveStock([[$product, 200, 35.00]], '2026-01-01');
        $this->scenario->billGrn($grn, '2026-01-05', vatRate: 14, whtRate: 1);

        $goods = bcmul('200', '35.00', 2);            // 7000.00
        $vat   = bcmul($goods, '0.14', 2);            //  980.00
        $wht   = bcmul($goods, '0.01', 2);            //   70.00
        $ap    = bcsub(bcadd($goods, $vat, 2), $wht, 2); // 7910.00

        $this->assertMoneyEquals(
            '0.00',
            $this->glBalance($this->scenario->companyId, '233'),
            'GRNI must clear to exactly zero once the goods are billed.'
        );

        $this->assertMoneyEquals(
            $vat,
            $this->glBalance($this->scenario->companyId, '137'),
            'VAT Input must be debited at the stated rate.'
        );

        $this->assertMoneyEquals(
            bcmul($wht, '-1', 2),
            $this->glBalance($this->scenario->companyId, '226002'),
            'Withholding tax must be retained as a liability.'
        );

        $this->assertMoneyEquals(
            bcmul($ap, '-1', 2),
            $this->glBalance($this->scenario->companyId, '221001'),
            'Accounts Payable must equal goods + VAT - withholding tax.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId);
    }

    /**
     * Billing must not touch inventory a second time. This is the
     * specific double-count the GRNI account exists to prevent.
     */
    public function test_billing_does_not_move_inventory_again(): void
    {
        $product = $this->scenario->products[0];

        $grn = $this->scenario->receiveStock([[$product, 200, 35.00]], '2026-01-01');

        $inventoryAfterReceipt = $this->glBalanceById(
            $this->scenario->companyId, (int) $product->inventory_account_id
        );
        $stockAfterReceipt = (string) ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $product->id)
            ->value('wac_total_value');

        $this->scenario->billGrn($grn, '2026-01-05');

        $this->assertMoneyEquals(
            $inventoryAfterReceipt,
            $this->glBalanceById($this->scenario->companyId, (int) $product->inventory_account_id),
            'The supplier bill must not re-debit inventory.'
        );

        $this->assertMoneyEquals(
            $stockAfterReceipt,
            (string) ProductStockBalance::withoutGlobalScopes()
                ->where('company_id', $this->scenario->companyId)
                ->where('product_id', $product->id)
                ->value('wac_total_value'),
            'The supplier bill must not move the stock ledger at all.'
        );
    }

    /**
     * Two receipts of the same product on the same purchase order
     * must accumulate, not overwrite — a regression guard for the
     * running-balance arithmetic.
     */
    public function test_repeated_receipts_accumulate_rather_than_overwrite(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 200, 35.00]], '2026-01-01');
        $this->scenario->receiveStock([[$product, 100, 41.00]], '2026-01-02');

        $expectedValue = bcadd(bcmul('200', '35.00', 2), bcmul('100', '41.00', 2), 2); // 11100.00
        $expectedQty   = '300';
        $expectedWac   = bcdiv($expectedValue, $expectedQty, 4);                        // 37.0000

        $balance = ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertQuantityEquals($expectedQty, (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals($expectedValue, (string) $balance->wac_total_value);
        $this->assertUnitCostEquals($expectedWac, (string) $balance->wac_unit_cost);

        $this->assertMoneyEquals(
            $expectedValue,
            $this->glBalanceById($this->scenario->companyId, (int) $product->inventory_account_id),
            'The inventory GL must equal the accumulated stock value.'
        );
    }

    /**
     * Reopening a posted receipt must reverse it completely: the
     * stock ledger, the location balance and the general ledger all
     * return to their pre-receipt state.
     */
    public function test_reopening_a_posted_receipt_reverses_it_completely(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 200, 35.00]], '2026-01-01');
        $grn = $this->scenario->receiveStock([[$product, 100, 41.00]], '2026-01-02');

        app(\App\Services\Accounting\GoodsReceiptNoteService::class)
            ->setToDraft($grn, $this->actor);

        $balance = ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertQuantityEquals(
            '200', (string) $balance->quantity_on_hand,
            'Reversing the second receipt must leave only the first.'
        );
        $this->assertMoneyEquals(
            '7000.00', (string) $balance->wac_total_value,
            'Book value must return to the first receipt only.'
        );
        $this->assertUnitCostEquals(
            '35.0000', (string) $balance->wac_unit_cost,
            'The weighted average must return to its pre-receipt value.'
        );

        $this->assertMoneyEquals(
            '7000.00',
            $this->glBalanceById($this->scenario->companyId, (int) $product->inventory_account_id),
            'The general ledger must be reversed in step with the stock ledger.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId, 'After reopening a receipt.');
    }

    /**
     * A receipt whose stock has already been sold on cannot be
     * reopened — there are no cost batches, so the system cannot
     * prove which units left. It must refuse rather than let the
     * balance go negative.
     */
    public function test_a_receipt_whose_stock_has_moved_on_cannot_be_reopened(): void
    {
        $product = $this->scenario->products[0];

        $grn = $this->scenario->receiveStock([[$product, 100, 35.00]], '2026-01-01');

        $so = $this->scenario->createSalesOrder([[$product, 80, 90.00]], '2026-02-01');
        $this->scenario->deliver($so, '2026-02-05');

        $this->expectException(\DomainException::class);

        app(\App\Services\Accounting\GoodsReceiptNoteService::class)
            ->setToDraft($grn, $this->actor);
    }
}
