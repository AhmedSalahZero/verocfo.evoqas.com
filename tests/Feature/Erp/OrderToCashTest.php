<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  OrderToCashTest
//
//  The complete sales chain, verified as one business flow rather
//  than as three isolated documents.
//
//  Vero CFO recognises revenue on DELIVERY, not on invoicing —
//  which is the correct treatment when control of the goods passes
//  on delivery, and which means the two documents must divide the
//  work between them without overlapping:
//
//    Delivery Note   DR Cost of Goods Sold
//                    CR Inventory                 (cost side)
//                    DR Accrued Revenue (143)
//                    CR Sales (411)               (revenue side)
//
//    Customer Invoice DR Accounts Receivable
//                     CR Accrued Revenue (143)    (reclassification)
//                     CR VAT Output (226001)
//
//  The single most important property is that Sales is credited
//  ONCE across the whole chain. If the invoice also credited Sales,
//  revenue would be doubled — the classic error this structure
//  exists to avoid. These tests assert that directly.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ProductStockBalance;
use Illuminate\Support\Facades\DB;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class OrderToCashTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->bootstrap(productCount: 1);
    }

    /**
     * Delivery recognises revenue and relieves inventory; it must not
     * touch Accounts Receivable, because no invoice exists yet.
     *
     * Independent expectation: 100 units bought at 20.00, 40 sold at
     * 75.00.
     *   COGS            40 * 20.00 =   800.00
     *   revenue         40 * 75.00 = 3,000.00
     *   accrued revenue              3,000.00 (debit, an asset)
     */
    public function test_delivery_recognises_revenue_and_relieves_inventory(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 100, 20.00]], '2026-01-01');
        $so = $this->scenario->createSalesOrder([[$product, 40, 75.00]], '2026-02-01');
        $this->scenario->deliver($so, '2026-02-05');

        $expectedCogs    = bcmul('40', '20.00', 2);
        $expectedRevenue = bcmul('40', '75.00', 2);

        $this->assertMoneyEquals(
            $expectedCogs,
            $this->glBalanceById($this->scenario->companyId, (int) $product->cogs_account_id),
            'COGS must be the units delivered at weighted-average cost.'
        );

        $this->assertMoneyEquals(
            bcmul($expectedRevenue, '-1', 2),
            $this->glBalanceById($this->scenario->companyId, (int) $product->revenue_account_id),
            'Revenue must be recognised in full on delivery.'
        );

        $this->assertMoneyEquals(
            $expectedRevenue,
            $this->glBalance($this->scenario->companyId, '143'),
            'Accrued Revenue carries the unbilled revenue as an asset.'
        );

        $this->assertMoneyEquals(
            '0.00',
            $this->glBalance($this->scenario->companyId, '133001'),
            'Accounts Receivable must not move before an invoice exists.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId);
    }

    /**
     * Invoicing reclassifies accrued revenue into a receivable and
     * adds output VAT. Accrued Revenue must clear to exactly zero.
     *
     *   revenue          3,000.00
     *   VAT at 14%         420.00
     *   receivable       3,420.00
     */
    public function test_invoicing_clears_accrued_revenue_and_raises_receivable(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 100, 20.00]], '2026-01-01');
        $so = $this->scenario->createSalesOrder([[$product, 40, 75.00]], '2026-02-01');
        $this->scenario->deliver($so, '2026-02-05');
        $this->scenario->invoice($so, '2026-02-05', vatRate: 14);

        $revenue = bcmul('40', '75.00', 2);       // 3000.00
        $vat     = bcmul($revenue, '0.14', 2);    //  420.00
        $ar      = bcadd($revenue, $vat, 2);      // 3420.00

        $this->assertMoneyEquals(
            '0.00',
            $this->glBalance($this->scenario->companyId, '143'),
            'Accrued Revenue must clear to zero once the invoice is issued.'
        );

        $this->assertMoneyEquals(
            $ar,
            $this->glBalance($this->scenario->companyId, '133001'),
            'Accounts Receivable must equal revenue plus output VAT.'
        );

        $this->assertMoneyEquals(
            bcmul($vat, '-1', 2),
            $this->glBalance($this->scenario->companyId, '226001'),
            'Output VAT must be recorded as a liability.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId);
    }

    /**
     * The central anti-double-count assertion: across the ENTIRE
     * chain, the revenue account is credited exactly once and by
     * exactly the order value.
     */
    public function test_revenue_is_recognised_exactly_once_across_the_whole_chain(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 100, 20.00]], '2026-01-01');
        $so = $this->scenario->createSalesOrder([[$product, 40, 75.00]], '2026-02-01');
        $this->scenario->deliver($so, '2026-02-05');
        $this->scenario->invoice($so, '2026-02-05');

        $expectedRevenue = bcmul('40', '75.00', 2);

        $this->assertMoneyEquals(
            bcmul($expectedRevenue, '-1', 2),
            $this->glBalanceById($this->scenario->companyId, (int) $product->revenue_account_id),
            'Revenue must total the order value, not twice it.'
        );

        // Exactly one credit line ever hit the revenue account.
        $creditLines = DB::selectOne(
            'select count(*) n
               from journal_entry_lines l
              where l.company_id = ? and l.chart_of_account_id = ? and l.credit > 0',
            [$this->scenario->companyId, (int) $product->revenue_account_id]
        )->n;

        $this->assertSame(
            1, (int) $creditLines,
            'Only the delivery note may credit revenue; the invoice must reclassify '.
            'accrued revenue instead of recognising it a second time.'
        );
    }

    /**
     * Gross profit computed from the ledger must equal gross profit
     * computed independently from the business facts.
     *
     *   revenue 3,000.00 - COGS 800.00 = 2,200.00
     */
    public function test_gross_profit_matches_an_independent_calculation(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 100, 20.00]], '2026-01-01');
        $so = $this->scenario->createSalesOrder([[$product, 40, 75.00]], '2026-02-01');
        $this->scenario->deliver($so, '2026-02-05');
        $this->scenario->invoice($so, '2026-02-05');

        $revenueGl = bcmul(
            $this->glBalanceById($this->scenario->companyId, (int) $product->revenue_account_id),
            '-1', 2
        );
        $cogsGl = $this->glBalanceById($this->scenario->companyId, (int) $product->cogs_account_id);

        $ledgerGrossProfit   = bcsub($revenueGl, $cogsGl, 2);
        $expectedGrossProfit = bcsub(bcmul('40', '75.00', 2), bcmul('40', '20.00', 2), 2);

        $this->assertMoneyEquals('2200.00', $expectedGrossProfit);
        $this->assertMoneyEquals($expectedGrossProfit, $ledgerGrossProfit);
    }

    /**
     * The chain must remain consistent when the average cost changed
     * between purchase and sale — the case where a naive
     * implementation relieves the wrong cost.
     *
     *   receipts: 100 @ 20.00 and 100 @ 30.00 => WAC 25.0000
     *   deliver 40 => COGS 40 * 25.00 = 1,000.00
     *   remaining: 160 units valued 4,000.00
     */
    public function test_cost_of_sales_uses_the_average_standing_at_delivery(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 100, 20.00]], '2026-01-01');
        $this->scenario->receiveStock([[$product, 100, 30.00]], '2026-01-15');

        $so = $this->scenario->createSalesOrder([[$product, 40, 75.00]], '2026-02-01');
        $this->scenario->deliver($so, '2026-02-05');

        $this->assertMoneyEquals(
            '1000.00',
            $this->glBalanceById($this->scenario->companyId, (int) $product->cogs_account_id),
            'COGS must use the weighted average at the moment of delivery (25.00), '.
            'not the cost of either individual receipt.'
        );

        $balance = ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $product->id)
            ->firstOrFail();

        $this->assertQuantityEquals('160', (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals('4000.00', (string) $balance->wac_total_value);
    }

    /**
     * Delivering more than the warehouse holds must be refused
     * outright — the stock ledger must never go negative.
     */
    public function test_delivering_more_than_is_on_hand_is_refused(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 10, 20.00]], '2026-01-01');
        $so = $this->scenario->createSalesOrder([[$product, 40, 75.00]], '2026-02-01');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/[Ii]nsufficient/');

        $this->scenario->deliver($so, '2026-02-05');
    }

    /**
     * A refused delivery must leave nothing behind — no partial
     * journal entry, no stock movement, no changed balance.
     */
    public function test_a_refused_delivery_leaves_no_partial_postings(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 10, 20.00]], '2026-01-01');
        $so = $this->scenario->createSalesOrder([[$product, 40, 75.00]], '2026-02-01');

        $entriesBefore = DB::table('journal_entries')
            ->where('company_id', $this->scenario->companyId)->count();
        $valueBefore = (string) ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $product->id)->value('wac_total_value');

        try {
            $this->scenario->deliver($so, '2026-02-05');
            $this->fail('The over-delivery should have been refused.');
        } catch (\DomainException) {
            // expected
        }

        $this->assertSame(
            $entriesBefore,
            DB::table('journal_entries')
                ->where('company_id', $this->scenario->companyId)->count(),
            'A failed delivery must not leave a journal entry behind.'
        );

        $this->assertMoneyEquals(
            $valueBefore,
            (string) ProductStockBalance::withoutGlobalScopes()
                ->where('company_id', $this->scenario->companyId)
                ->where('product_id', $product->id)->value('wac_total_value'),
            'A failed delivery must not change the stock ledger.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId);
    }
}
