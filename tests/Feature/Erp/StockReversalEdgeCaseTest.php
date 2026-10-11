<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  StockReversalEdgeCaseTest
//
//  Reversing a receipt in a pooled weighted-average system is
//  inherently ambiguous once other stock has moved, because there
//  are no cost batches to put back. reverseReceipt() subtracts the
//  ORIGINAL receipt's quantity and value back out of the running
//  pool — which is exact only while nothing has been relieved from
//  that pool in between.
//
//  It used to guard quantity alone and clamp value at zero, which
//  let an older receipt be reversed after an issue: quantity reached
//  zero while book value did not, and the residue was then absorbed
//  into the next receipt's average (audit finding INV-006).
//
//  FIXED 2026-09: the reversal is now allowed only in strict reverse
//  chronological order. options['original_movement_id'] identifies
//  the exact stock_movements row the receipt created, and the
//  reversal is refused if anything outbound has touched that product
//  since. These tests pin both the refusal and the cases that must
//  still be allowed.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ProductStockBalance;
use App\Models\StockMovement;
use App\Services\Inventory\StockLedgerService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class StockReversalEdgeCaseTest extends ErpTestCase
{
    private ErpScenario $scenario;

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
     * Control: reversing the most recent receipt with nothing issued
     * in between restores the pool exactly.
     */
    public function test_reversing_an_untouched_receipt_restores_the_pool_exactly(): void
    {
        $product = $this->scenario->products[0];
        $ledger  = app(StockLedgerService::class);

        $ledger->receive($this->scenario->companyId, $product, $this->scenario->masterLocationId, '100', '10.00', $this->actor);
        $second = $ledger->receive($this->scenario->companyId, $product, $this->scenario->masterLocationId, '100', '20.00', $this->actor);

        $ledger->reverseReceipt(
            $this->scenario->companyId, $product, $this->scenario->masterLocationId,
            '100', '2000.00', $this->actor,
            ['original_movement_id' => $second->id]
        );

        $balance = $this->balance();
        $this->assertQuantityEquals('100', (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals('1000.00', (string) $balance->wac_total_value);
        $this->assertUnitCostEquals('10.0000', (string) $balance->wac_unit_cost);
    }

    /**
     * REGRESSION GUARD — audit finding INV-006, FIXED 2026-09.
     *
     * Reversing an OLDER receipt after stock has already been issued
     * at the blended average used to leave quantity at zero while
     * book value stayed positive — an internally inconsistent
     * balance row that then silently re-priced the next receipt.
     *
     * What it used to do:
     *   receipt 1  100 @ 10.00  -> qty 100, value 1,000.00
     *   receipt 2  100 @ 20.00  -> qty 200, value 3,000.00, WAC 15.00
     *   issue      100 @ 15.00  -> qty 100, value 1,500.00
     *   reverse receipt 1 (quantity 100, original value 1,000.00):
     *              the quantity guard passed, 100 >= 100
     *              qty   = 100 - 100 =     0
     *              value = 1,500.00 - 1,000.00 = 500.00
     *              -> zero units carrying 500.00 of book value
     *
     * It is now refused instead: 100 units were issued after that
     * receipt, so the issue must be reversed first. The refusal
     * names what has to be undone rather than reporting a bare
     * "insufficient stock".
     */
    public function test_reversing_an_older_receipt_is_refused_once_stock_has_been_issued(): void
    {
        $product = $this->scenario->products[0];
        $ledger  = app(StockLedgerService::class);

        $first = $ledger->receive($this->scenario->companyId, $product, $this->scenario->masterLocationId, '100', '10.00', $this->actor);
        $ledger->receive($this->scenario->companyId, $product, $this->scenario->masterLocationId, '100', '20.00', $this->actor);
        $ledger->issue($this->scenario->companyId, $product, $this->scenario->masterLocationId, '100', $this->actor);

        $mid = $this->balance();
        $this->assertQuantityEquals('100', (string) $mid->quantity_on_hand, 'Precondition.');
        $this->assertMoneyEquals('1500.00', (string) $mid->wac_total_value, 'Precondition.');

        $movementsBefore = StockMovement::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)->count();

        try {
            $ledger->reverseReceipt(
                $this->scenario->companyId, $product, $this->scenario->masterLocationId,
                '100', '1000.00', $this->actor,
                ['original_movement_id' => $first->id]
            );
            $this->fail('Reversing a receipt that has since been issued from must be refused.');
        } catch (\DomainException $e) {
            $this->assertMatchesRegularExpression(
                '/reverse those transactions first/i',
                $e->getMessage(),
                'INV-006 fix: the refusal must say what has to be undone first, not '.
                'merely that something is wrong.'
            );
        }

        $balance = $this->balance();

        $this->assertQuantityEquals(
            '100', (string) $balance->quantity_on_hand,
            'INV-006 fix: a refused reversal leaves the pool exactly as it was.'
        );
        $this->assertMoneyEquals('1500.00', (string) $balance->wac_total_value);
        $this->assertUnitCostEquals('15.0000', (string) $balance->wac_unit_cost);

        $this->assertSame(
            $movementsBefore,
            StockMovement::withoutGlobalScopes()
                ->where('company_id', $this->scenario->companyId)->count(),
            'A refused reversal must not write a movement.'
        );
    }

    /**
     * The consequence INV-006 used to have — 500.00 stranded against
     * zero units, absorbed into the next receipt so that stock
     * bought at 10.00 was carried at 15.0000 — is unreachable now,
     * because the reversal that created it is refused.
     *
     * Undoing the issue FIRST, then the receipt, is the supported
     * route, and it lands on exactly the right numbers.
     */
    public function test_undoing_in_reverse_order_restores_the_pool_exactly(): void
    {
        $product = $this->scenario->products[0];
        $ledger  = app(StockLedgerService::class);

        $first  = $ledger->receive($this->scenario->companyId, $product, $this->scenario->masterLocationId, '100', '10.00', $this->actor);
        $second = $ledger->receive($this->scenario->companyId, $product, $this->scenario->masterLocationId, '100', '20.00', $this->actor);

        // Undo the newest receipt first — nothing has been issued, so
        // this is exact.
        $ledger->reverseReceipt(
            $this->scenario->companyId, $product, $this->scenario->masterLocationId,
            '100', '2000.00', $this->actor,
            ['original_movement_id' => $second->id]
        );

        // ...then the older one.
        $ledger->reverseReceipt(
            $this->scenario->companyId, $product, $this->scenario->masterLocationId,
            '100', '1000.00', $this->actor,
            ['original_movement_id' => $first->id]
        );

        $balance = $this->balance();

        $this->assertQuantityEquals('0', (string) $balance->quantity_on_hand);
        $this->assertMoneyEquals(
            '0.00',
            (string) $balance->wac_total_value,
            'INV-006 fix: quantity and value reach zero together — no book value '.
            'is left behind with nothing under it.'
        );

        // And a fresh purchase is priced honestly, with nothing
        // stranded to absorb into it.
        $ledger->receive($this->scenario->companyId, $product, $this->scenario->masterLocationId, '100', '10.00', $this->actor);

        $after = $this->balance();
        $this->assertQuantityEquals('100', (string) $after->quantity_on_hand);
        $this->assertUnitCostEquals(
            '10.0000',
            (string) $after->wac_unit_cost,
            'Stock bought at 10.00 is carried at 10.0000 — under INV-006 this same '.
            'sequence produced 15.0000.'
        );
    }

    /**
     * A reversal with no link back to the movement it created cannot
     * be verified, so it is refused rather than guessed at. This is
     * what a Goods Receipt Note posted before migration 000279 looks
     * like if its backfill did not resolve (000283).
     */
    public function test_a_reversal_with_no_original_movement_is_refused(): void
    {
        $product = $this->scenario->products[0];
        $ledger  = app(StockLedgerService::class);

        $ledger->receive($this->scenario->companyId, $product, $this->scenario->masterLocationId, '100', '10.00', $this->actor);

        try {
            $ledger->reverseReceipt(
                $this->scenario->companyId, $product, $this->scenario->masterLocationId,
                '100', '1000.00', $this->actor
            );
            $this->fail('An unverifiable reversal must be refused.');
        } catch (\DomainException $e) {
            $this->assertMatchesRegularExpression('/stock adjustment/i', $e->getMessage());
        }

        $this->assertMoneyEquals('1000.00', (string) $this->balance()->wac_total_value);
    }

    /**
     * The reversal is refused outright when it would push quantity
     * negative — the guard that does work.
     */
    public function test_reversing_more_than_remains_is_refused(): void
    {
        $product = $this->scenario->products[0];
        $ledger  = app(StockLedgerService::class);

        $receipt = $ledger->receive($this->scenario->companyId, $product, $this->scenario->masterLocationId, '100', '10.00', $this->actor);
        $ledger->issue($this->scenario->companyId, $product, $this->scenario->masterLocationId, '60', $this->actor);

        $movementsBefore = StockMovement::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)->count();

        try {
            $ledger->reverseReceipt(
                $this->scenario->companyId, $product, $this->scenario->masterLocationId,
                '100', '1000.00', $this->actor,
                ['original_movement_id' => $receipt->id]
            );
            $this->fail('Reversing more than remains should be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsStringIgnoringCase('already', $e->getMessage());
        }

        $this->assertSame(
            $movementsBefore,
            StockMovement::withoutGlobalScopes()
                ->where('company_id', $this->scenario->companyId)->count(),
            'A refused reversal must not write a movement.'
        );
    }
}
