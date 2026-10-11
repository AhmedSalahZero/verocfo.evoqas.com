<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  InventoryCostingTest
//
//  The weighted-average-cost engine, verified against independently
//  calculated expectations rather than against whatever the
//  application happens to produce.
//
//  The rule under test is the one StockLedgerService states in its
//  own header, sourced to Application Scope §5.3:
//
//      New WAC = (current stock value + new receipt value)
//                ÷ (current stock qty + new receipt qty)
//
//  and its companion rule: only receipts move the WAC; issues
//  relieve stock at whatever WAC already exists and never change it.
//
//  Every expected figure below is computed by hand in the test body
//  from the inputs, so a change in the costing formula fails these
//  tests instead of silently redefining "correct".
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ProductLocationStock;
use App\Models\ProductStockBalance;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class InventoryCostingTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->bootstrap(productCount: 2);
    }

    private function balance(int $productId): ProductStockBalance
    {
        return ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $productId)
            ->firstOrFail();
    }

    /**
     * A single receipt sets the WAC to that receipt's unit cost.
     * Independent expectation: 100 units at 10.00 => qty 100,
     * value 1,000.00, WAC 10.0000.
     */
    public function test_first_receipt_establishes_the_weighted_average_cost(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 100, 10.00]], '2026-01-01');

        $balance = $this->balance($product->id);

        $this->assertQuantityEquals('100', (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals('1000.00', (string) $balance->wac_total_value);
        $this->assertSame(0, bccomp('10.0000', (string) $balance->wac_unit_cost, 4));

        $this->assertLedgerBalanced($this->scenario->companyId, 'After first receipt.');
    }

    /**
     * The formula itself, on two receipts at different unit costs.
     *
     *   receipt 1: 100 @ 10.00 => value 1,000.00
     *   receipt 2:  50 @ 16.00 => value   800.00
     *   expected WAC = (1000 + 800) / (100 + 50)
     *                = 1800 / 150 = 12.0000
     */
    public function test_second_receipt_at_a_different_cost_reaverages(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 100, 10.00]], '2026-01-01');
        $this->scenario->receiveStock([[$product, 50, 16.00]], '2026-01-02');

        $expectedQty   = bcadd('100', '50', 4);
        $expectedValue = bcadd(bcmul('100', '10.00', 2), bcmul('50', '16.00', 2), 2);
        $expectedWac   = bcdiv($expectedValue, $expectedQty, 4);

        $balance = $this->balance($product->id);

        $this->assertQuantityEquals($expectedQty, (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals($expectedValue, (string) $balance->wac_total_value);
        $this->assertSame(
            0,
            bccomp($expectedWac, (string) $balance->wac_unit_cost, 4),
            "Expected WAC {$expectedWac}, got {$balance->wac_unit_cost}."
        );
        $this->assertUnitCostEquals('12.0000', (string) $balance->wac_unit_cost);
    }

    /**
     * A receipt that produces a non-terminating average must not let
     * the stored book VALUE drift — only the derived unit cost may be
     * rounded.
     *
     * Note the inputs are whole cents on purpose: purchase_order_lines
     * .unit_price and goods_receipt_note_lines.unit_cost are both
     * decimal(15,2), so a sub-cent purchase price is rounded by the
     * database before the costing engine ever sees it (documented as
     * audit finding INV-004). The weighted average itself is
     * decimal(15,4), so the repeating average has to come from the
     * quantities instead:
     *
     *   receipt 1:  7 @ 10.00 =>  70.00
     *   receipt 2:  6 @ 20.00 => 120.00
     *   value = 190.00 exactly ; qty = 13
     *   190.00 / 13 = 14.615384... => 14.6154 at 4dp
     *
     * The VALUE is what the general ledger and balance sheet rely on,
     * so it must be exact; the unit cost is a derived display/relief
     * figure and may round.
     */
    public function test_weighted_average_rounding_keeps_total_value_exact(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 7, 10.00]], '2026-01-01');
        $this->scenario->receiveStock([[$product, 6, 20.00]], '2026-01-02');

        $balance = $this->balance($product->id);

        $expectedValue = bcadd(bcmul('7', '10.00', 2), bcmul('6', '20.00', 2), 2); // 190.00
        $expectedWac   = bcdiv($expectedValue, '13', 4);                            // 14.6153

        $this->assertQuantityEquals('13', (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals(
            $expectedValue,
            (string) $balance->wac_total_value,
            'Book value must stay exact even when the average does not terminate.'
        );
        $this->assertSame(
            0,
            bccomp($expectedWac, (string) $balance->wac_unit_cost, 4),
            "Expected WAC {$expectedWac}, got {$balance->wac_unit_cost}."
        );

        // The rounded unit cost multiplied back out does NOT reproduce
        // the book value — which is precisely why the engine stores
        // value independently rather than deriving it from unit cost.
        $this->assertSame(
            -1,
            bccomp(bcmul('13', (string) $balance->wac_unit_cost, 2), $expectedValue, 2),
            'Sanity check on the chosen figures: this case must actually exercise rounding.'
        );
    }

    /**
     * Issues relieve stock at the standing WAC and must NOT move it.
     *
     *   receipts: 100 @ 10 and 50 @ 16 => WAC 12.0000, value 1,800.00
     *   deliver 60 units => relief 60 * 12 = 720.00
     *   remaining: qty 90, value 1,080.00, WAC still 12.0000
     */
    public function test_issuing_stock_relieves_value_at_wac_without_changing_it(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 100, 10.00]], '2026-01-01');
        $this->scenario->receiveStock([[$product, 50, 16.00]], '2026-01-02');

        $so = $this->scenario->createSalesOrder([[$product, 60, 25.00]], '2026-02-01');
        $this->scenario->deliver($so, '2026-02-05');

        $balance = $this->balance($product->id);

        $this->assertQuantityEquals('90', (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals('1080.00', (string) $balance->wac_total_value);
        $this->assertUnitCostEquals(
            '12.0000',
            (string) $balance->wac_unit_cost,
            'An issue must never move the weighted average cost.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId, 'After delivery.');
    }

    /**
     * Cross-module: the value relieved from the stock ledger on a
     * delivery must equal the amount debited to Cost of Goods Sold.
     * This is the check that catches an inventory engine and an
     * accounting engine drifting apart.
     */
    public function test_cogs_posted_equals_inventory_value_relieved(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 100, 10.00]], '2026-01-01');
        $this->scenario->receiveStock([[$product, 50, 16.00]], '2026-01-02');

        $valueBefore = (string) $this->balance($product->id)->wac_total_value;

        $so = $this->scenario->createSalesOrder([[$product, 60, 25.00]], '2026-02-01');
        $this->scenario->deliver($so, '2026-02-05');

        $valueAfter   = (string) $this->balance($product->id)->wac_total_value;
        $valueRelieved = bcsub($valueBefore, $valueAfter, 2);

        $cogsPosted = $this->glBalanceById(
            $this->scenario->companyId,
            (int) $product->cogs_account_id
        );

        $this->assertMoneyEquals(
            $valueRelieved,
            $cogsPosted,
            'COGS debited must equal the inventory value relieved from the stock ledger.'
        );

        // ...and the inventory account must have fallen by the same amount.
        $inventoryGl = $this->glBalanceById(
            $this->scenario->companyId,
            (int) $product->inventory_account_id
        );
        $this->assertMoneyEquals(
            $valueAfter,
            $inventoryGl,
            'The inventory GL account must equal the stock ledger book value.'
        );
    }

    /**
     * The subsidiary ledger must reconcile to the general ledger for
     * every inventory account — the single most important
     * cross-module invariant in the inventory engine.
     */
    public function test_stock_ledger_reconciles_to_general_ledger(): void
    {
        [$a, $b] = $this->scenario->products;

        $this->scenario->receiveStock([[$a, 100, 10.00], [$b, 40, 25.00]], '2026-01-01');
        $this->scenario->receiveStock([[$a, 50, 16.00]], '2026-01-02');

        $so = $this->scenario->createSalesOrder([[$a, 60, 30.00], [$b, 10, 60.00]], '2026-02-01');
        $this->scenario->deliver($so, '2026-02-05');

        $bookValue = ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->get()
            ->reduce(fn ($carry, $row) => bcadd($carry, (string) $row->wac_total_value, 2), '0.00');

        $glValue = $this->glBalanceById(
            $this->scenario->companyId,
            (int) $a->inventory_account_id
        );

        $this->assertMoneyEquals(
            $bookValue,
            $glValue,
            'Sum of product stock balances must equal the inventory GL balance.'
        );
    }

    /**
     * Location quantity and company-wide pooled quantity must agree
     * for ordinary (non-job-order) stock. This is the control case
     * for the job-order divergence documented separately.
     */
    public function test_location_quantity_matches_pooled_quantity(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 100, 10.00]], '2026-01-01');

        $so = $this->scenario->createSalesOrder([[$product, 30, 25.00]], '2026-02-01');
        $this->scenario->deliver($so, '2026-02-05');

        $pooled = (string) $this->balance($product->id)->quantity_on_hand;

        $located = ProductLocationStock::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $product->id)
            ->get()
            ->reduce(fn ($c, $r) => bcadd($c, (string) $r->quantity_on_hand, 4), '0');

        $this->assertQuantityEquals('70', $pooled);
        $this->assertQuantityEquals(
            $pooled,
            $located,
            'Location-level quantity must sum to the company-wide pooled quantity.'
        );
    }

    /**
     * The stricter form of "issues never move the WAC".
     *
     * Relieving stock at exactly the standing average leaves the
     * average arithmetically unchanged, so a scenario with a clean
     * average cannot tell the difference between "never recalculated"
     * and "recalculated and happened to match". This scenario can:
     * the average carries rounding, so recalculating it after an
     * issue would visibly shift it.
     *
     *   receipts: 7 @ 10.00 and 6 @ 20.00 => value 190.00, qty 13
     *             WAC = 190.00 / 13 = 14.6153 (rounded down at 4dp)
     *   issue 3 units:
     *             relief    = 3 * 14.6153 = 43.8459, rounded half up
     *                         at 2dp to 43.85 (INV-005 fix — bcmath
     *                         truncates on its own, so the engine
     *                         rounds explicitly before recording)
     *             new value = 190.00 - 43.85 = 146.15
     *             new qty   = 10
     *   If the WAC were recalculated it would become
     *             146.15 / 10 = 14.6150  -- different from 14.6153.
     *
     * The documented rule says only receipts move the WAC, so it must
     * still read 14.6153.
     */
    public function test_issues_never_recalculate_a_rounded_weighted_average(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 7, 10.00]], '2026-01-01');
        $this->scenario->receiveStock([[$product, 6, 20.00]], '2026-01-02');

        $wacBefore = (string) $this->balance($product->id)->wac_unit_cost;
        $this->assertUnitCostEquals('14.6153', $wacBefore, 'Precondition: the average carries rounding.');

        $so = $this->scenario->createSalesOrder([[$product, 3, 50.00]], '2026-02-01');
        $this->scenario->deliver($so, '2026-02-05');

        $balance = $this->balance($product->id);

        $this->assertQuantityEquals('10', (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals('146.15', (string) $balance->wac_total_value);

        $this->assertUnitCostEquals(
            '14.6153',
            (string) $balance->wac_unit_cost,
            'The weighted average must be untouched by an issue. Recalculating it '.
            'here would produce 14.6150.'
        );

        // Guard the guard: prove the two candidate values really differ,
        // so this test cannot silently stop discriminating.
        $this->assertNotSame(
            0,
            bccomp('14.6153', bcdiv('146.15', '10', 4), 4),
            'This scenario must actually distinguish recalculation from preservation.'
        );
    }
}
