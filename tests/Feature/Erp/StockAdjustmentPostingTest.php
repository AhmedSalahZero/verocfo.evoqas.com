<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  StockAdjustmentPostingTest
//
//  REGRESSION GUARD — audit finding INV-007, fixed 2026-09.
//
//  A stock adjustment used to move the stock ledger and post nothing
//  at all to the general ledger. Inventory on the Balance Sheet was
//  therefore overstated by the cumulative value of every write-off
//  the company had ever made, permanently and invisibly — the ledger
//  still balanced, because nothing had been written to it.
//
//  Every figure below is calculated by hand from the inputs, never
//  read back from whatever the application happened to produce.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\JournalEntry;
use App\Models\ProductStockBalance;
use App\Services\Accounting\StockAdjustmentService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class StockAdjustmentPostingTest extends ErpTestCase
{
    private ErpScenario $scenario;

    /** The Cost of Goods Sold line INV-007 introduced. */
    private const ADJUSTMENT_CODE = '516';

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->bootstrap(productCount: 1);
    }

    private function balance(): ProductStockBalance
    {
        return ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $this->scenario->products[0]->id)
            ->firstOrFail();
    }

    /**
     * Independent calculation:
     *
     *   receive 100 @ 12.50            qty 100   value 1,250.00   WAC 12.5000
     *   write off 8 units at that WAC
     *       relief   = 8 x 12.50    =     100.00
     *       remaining qty            =      92
     *       remaining value          =   1,150.00
     *
     *   the entry that must accompany it:
     *       Dr 516 Inventory Adjustment      100.00
     *       Cr 135xxx Inventory                        100.00
     */
    public function test_a_write_off_posts_the_relief_to_the_adjustment_account(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 100, 12.50]], '2026-01-01');

        $adjustmentAccount = $this->scenario->coaId(self::ADJUSTMENT_CODE);
        $inventoryAccount  = (int) $product->inventory_account_id;

        $adjustmentBefore = $this->glBalanceById($this->scenario->companyId, $adjustmentAccount);
        $this->assertMoneyEquals('0.00', $adjustmentBefore, 'Precondition: nothing written off yet.');

        app(StockAdjustmentService::class)->adjust(
            $this->scenario->companyId, $product, $this->scenario->masterLocationId,
            '8', 'out', $this->actor,
            ['movement_date' => '2026-01-20', 'notes' => 'Count shortfall']
        );

        $balance = $this->balance();
        $this->assertQuantityEquals('92', (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals('1150.00', (string) $balance->wac_total_value);
        $this->assertUnitCostEquals(
            '12.5000', (string) $balance->wac_unit_cost,
            'A write-off relieves at the standing average and must not move it.'
        );

        $this->assertMoneyEquals(
            '100.00',
            $this->glBalanceById($this->scenario->companyId, $adjustmentAccount),
            'INV-007 fix: the 100.00 relieved from stock is debited to Inventory Adjustment. '.
            'Before the fix this account was never touched at all.'
        );

        $this->assertMoneyEquals(
            '1150.00',
            $this->glBalanceById($this->scenario->companyId, $inventoryAccount),
            'And the inventory account carries exactly what the stock ledger carries: '.
            '1,250.00 received less 100.00 written off.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId, 'After a stock write-off.');
    }

    /**
     * The reconciliation that INV-007 broke: the inventory subsidiary
     * ledger against its own control account.
     *
     * This is the assertion that actually matters, because it is the
     * one a finance team would run. Before the fix the two diverged
     * by the value of every adjustment ever made.
     */
    public function test_inventory_subledger_reconciles_to_the_general_ledger(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 200, 7.25]], '2026-01-01');   // 1,450.00
        $this->scenario->receiveStock([[$product, 100, 9.75]], '2026-01-05');   //   975.00
        //   qty 300, value 2,425.00, WAC 8.0833

        app(StockAdjustmentService::class)->adjust(
            $this->scenario->companyId, $product, $this->scenario->masterLocationId,
            '30', 'out', $this->actor, ['movement_date' => '2026-01-10', 'notes' => 'Damaged']
        );

        //   relief = 30 x 8.0833 = 242.499  -> rounds to 242.50 (INV-005)
        //   qty 270, value 2,182.50
        $balance = $this->balance();

        $this->assertQuantityEquals('270', (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals('2182.50', (string) $balance->wac_total_value);

        $this->assertMoneyEquals(
            (string) $balance->wac_total_value,
            $this->glBalanceById($this->scenario->companyId, (int) $product->inventory_account_id),
            'The inventory subledger and the GL control account must agree to the cent.'
        );

        $this->assertMoneyEquals(
            '242.50',
            $this->glBalanceById($this->scenario->companyId, $this->scenario->coaId(self::ADJUSTMENT_CODE)),
            'The write-off cost lands in Inventory Adjustment, separable from real cost of sales.'
        );
    }

    /**
     * A positive adjustment is the mirror image: a count that finds
     * MORE than the books showed credits the same account, reducing
     * the expense rather than creating a second one.
     *
     *   receive   50 @ 20.00      qty  50   value 1,000.00
     *   write on  10 @ 20.00      qty  60   value 1,200.00
     *       Dr 135xxx Inventory              200.00
     *       Cr 516 Inventory Adjustment              200.00
     */
    public function test_a_write_on_credits_the_adjustment_account(): void
    {
        $product = $this->scenario->products[0];

        $this->scenario->receiveStock([[$product, 50, 20.00]], '2026-01-01');

        app(StockAdjustmentService::class)->adjust(
            $this->scenario->companyId, $product, $this->scenario->masterLocationId,
            '10', 'in', $this->actor,
            ['unit_cost' => '20.00', 'movement_date' => '2026-01-15', 'notes' => 'Count surplus']
        );

        $balance = $this->balance();
        $this->assertQuantityEquals('60', (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals('1200.00', (string) $balance->wac_total_value);

        $this->assertMoneyEquals(
            '-200.00',
            $this->glBalanceById($this->scenario->companyId, $this->scenario->coaId(self::ADJUSTMENT_CODE)),
            'Debit-normal account, credited by a write-on: the balance goes negative, '.
            'which is a reduction of the adjustment expense — not a new one.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId, 'After a stock write-on.');
    }

    /**
     * Atomicity, the MFG-005 lesson applied here: the movement and
     * its entry are one operation. A product with no inventory
     * account cannot post, so it must not move stock either.
     */
    public function test_an_adjustment_that_cannot_post_moves_no_stock(): void
    {
        $product = $this->scenario->products[0];
        $this->scenario->receiveStock([[$product, 40, 5.00]], '2026-01-01');

        $product->update(['inventory_account_id' => null]);

        $before = $this->balance()->quantity_on_hand;

        try {
            app(StockAdjustmentService::class)->adjust(
                $this->scenario->companyId, $product->fresh(), $this->scenario->masterLocationId,
                '5', 'out', $this->actor, ['movement_date' => '2026-01-20', 'notes' => 'x']
            );
            $this->fail('An adjustment that cannot post its journal entry must be refused.');
        } catch (\DomainException $e) {
            $this->assertMatchesRegularExpression('/inventory account/i', $e->getMessage());
        }

        $this->assertQuantityEquals(
            (string) $before,
            (string) $this->balance()->quantity_on_hand,
            'INV-007 is posted inside the same transaction as the movement, so a failed '.
            'posting takes the stock movement with it.'
        );
    }

    /**
     * Structural guard: the account the fix depends on must exist and
     * be postable. Without it every adjustment throws — the ACC-006
     * and INV-008 failure mode, where a code change needed data that
     * was never seeded.
     */
    public function test_the_inventory_adjustment_account_is_seeded_and_postable(): void
    {
        $account = \App\Models\ChartOfAccount::findByCode(
            $this->scenario->companyId, self::ADJUSTMENT_CODE
        );

        $this->assertNotNull($account, 'Account 516 (Inventory Adjustment) must be seeded.');
        $this->assertTrue((bool) $account->is_postable, 'Account 516 must be postable.');
        $this->assertSame('Cost of Goods Sold', $account->account_type);
        $this->assertSame('debit', $account->normal_balance);
    }

    /** Every adjustment must leave a traceable entry, not just a balance. */
    public function test_the_entry_is_traceable_back_to_its_movement(): void
    {
        $product = $this->scenario->products[0];
        $this->scenario->receiveStock([[$product, 10, 33.00]], '2026-01-01');

        $movement = app(StockAdjustmentService::class)->adjust(
            $this->scenario->companyId, $product, $this->scenario->masterLocationId,
            '2', 'out', $this->actor, ['movement_date' => '2026-01-09', 'notes' => 'Scrapped']
        );

        $entry = JournalEntry::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('source_type', 'stock_adjustment')
            ->where('source_id', $movement->id)
            ->first();

        $this->assertNotNull($entry, 'The adjustment must be traceable from its stock movement.');
        $this->assertSame('2026-01-09', $entry->entry_date->toDateString(),
            'The entry carries the movement date, not today.');
    }
}
