<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  JobOrderSegmentTest
//
//  Manufacturing Variance Closing Design v1.0, Section 6 introduced a
//  second, parallel inventory cost pool: a completed Job Order's
//  output is recorded in job_order_stock_balances and deliberately
//  NOT averaged into the company-wide ProductStockBalance, so one
//  job's build never blends into the product's general average.
//
//  These tests establish, with real postings, what that design
//  actually does to the two pooled balances and to the general
//  ledger — because a second pool is only safe if every consumer of
//  inventory knows it exists.
//
//  Reference: StockLedgerService::receive()'s job_order_id branch,
//  JobOrderStockBalanceService, JobOrderService::complete().
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\JobOrder;
use App\Models\JobOrderStockBalance;
use App\Models\Product;
use App\Models\ProductLocationStock;
use App\Models\ProductStockBalance;
use App\Models\StockMovement;
use App\Services\Accounting\JobOrderService;
use App\Services\ProductCategoryService;
use App\Services\ProductService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class JobOrderSegmentTest extends ErpTestCase
{
    private ErpScenario $scenario;
    private Product $rawMaterial;
    private Product $finishedGood;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scenario = (new ErpScenario($this->actor))
            ->createCompany(['company_type' => ['Manufacturing']])
            ->createPartners();

        $category = app(ProductCategoryService::class)->create(
            $this->scenario->companyId, ['name_en' => 'Manufacturing'], $this->actor
        );

        $this->rawMaterial = app(ProductService::class)->create($this->scenario->companyId, [
            'use_auto_code'  => true,
            'name_en'        => 'Steel Sheet',
            'category_id'    => $category->id,
            'usage_types'    => [Product::TYPE_RAW_MATERIAL],
            'primary_uom_id' => $this->scenario->unitId,
        ], $this->actor);

        $this->finishedGood = app(ProductService::class)->create($this->scenario->companyId, [
            'use_auto_code'  => true,
            'name_en'        => 'Steel Cabinet',
            'category_id'    => $category->id,
            'usage_types'    => [Product::TYPE_SALEABLE],
            // A Manufacturing company has three valid natures, so the
            // server cannot infer one — the product form supplies it.
            // Without it the product silently gets no inventory
            // account (audit finding PRD-001).
            'nature'         => Product::NATURE_MANUFACTURED,
            'primary_uom_id' => $this->scenario->unitId,
        ], $this->actor);

        // Raw material on hand so the job has something to consume.
        $this->scenario->products = [$this->rawMaterial];
        $this->scenario->receiveStock([[$this->rawMaterial, 1000, 10.00]], '2026-01-01');
    }

    private function runJobOrder(string $qtyToProduce, string $consumeQty, ?int $salesOrderId = null): JobOrder
    {
        $service = app(JobOrderService::class);

        $job = $service->create($this->scenario->companyId, [
            'product_id'               => $this->finishedGood->id,
            'quantity_to_produce'      => $qtyToProduce,
            'raw_material_location_id' => $this->scenario->masterLocationId,
            'output_location_id'       => $this->scenario->masterLocationId,
            'sales_order_id'           => $salesOrderId,
            'planned_start_date'       => '2026-02-01',
            'lines'                    => [[
                'component_product_id' => $this->rawMaterial->id,
                'quantity'             => $consumeQty,
                'uom_id'               => $this->rawMaterial->primary_uom_id,
            ]],
        ], $this->actor);

        $service->start($job, $this->actor);
        $service->consumeMaterial($job, $this->rawMaterial->id, $consumeQty, $this->actor);

        return $service->complete($job->fresh(), $this->actor, '2026-02-10');
    }

    /**
     * Baseline: the job posts, the ledger stays balanced, and the
     * job's own segment holds the output at its own standard cost.
     *
     * Independent expectation: consuming 100 units of raw material
     * at WAC 10.00 puts 1,000.00 into WIP; completing 10 finished
     * units moves that 1,000.00 out of WIP into Finished Goods, so
     * the segment holds 10 units valued at 1,000.00 (100.00 each).
     */
    public function test_completing_a_job_order_records_output_in_its_own_segment(): void
    {
        $job = $this->runJobOrder('10', '100');

        $segment = JobOrderStockBalance::withoutGlobalScopes()
            ->where('job_order_id', $job->id)
            ->where('product_id', $this->finishedGood->id)
            ->firstOrFail();

        $this->assertQuantityEquals('10', (string) $segment->quantity_on_hand);
        $this->assertMoneyEquals('1000.00', (string) $segment->total_value);
        $this->assertUnitCostEquals('100.0000', (string) $segment->unit_cost);

        $this->assertLedgerBalanced($this->scenario->companyId, 'After job order completion.');
    }

    /**
     * The general ledger IS debited for the finished-goods value on
     * completion. Establishing this is what makes the next test's
     * divergence meaningful: the GL knows about the output.
     */
    public function test_general_ledger_records_the_finished_goods_value(): void
    {
        $this->runJobOrder('10', '100');

        $inventoryGl = $this->glBalanceById(
            $this->scenario->companyId,
            (int) $this->finishedGood->inventory_account_id
        );

        $this->assertMoneyEquals(
            '1000.00',
            $inventoryGl,
            'Job order completion must debit the finished good inventory account.'
        );
    }

    /**
     * REGRESSION GUARD — audit finding MFG-001.
     *
     * StockLedgerService::receive()'s header states that when a
     * receipt carries a job_order_id "physical quantity/location
     * tracking is completely unaffected ... only the pooled COST
     * balance is skipped."
     *
     * The implementation skips the whole ProductStockBalance update,
     * quantity_on_hand included. This test records the CURRENT
     * behaviour so the divergence is visible and cannot change
     * unnoticed: the location holds the units, the company-wide
     * pooled balance does not.
     *
     * If this test starts failing because pooled quantity now equals
     * located quantity, the documented rule and the code have been
     * brought into agreement and this test should be inverted.
     */
    public function test_job_order_output_diverges_between_pooled_and_located_quantity(): void
    {
        $this->runJobOrder('10', '100');

        $located = ProductLocationStock::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $this->finishedGood->id)
            ->get()
            ->reduce(fn ($c, $r) => bcadd($c, (string) $r->quantity_on_hand, 4), '0');

        $pooled = ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $this->finishedGood->id)
            ->value('quantity_on_hand');

        $this->assertQuantityEquals(
            '10', $located,
            'The warehouse physically holds the job order output.'
        );

        $this->assertQuantityEquals(
            '0', (string) ($pooled ?? '0'),
            'CURRENT BEHAVIOUR (audit finding MFG-001): the company-wide pooled '.
            'quantity is skipped along with the cost, contradicting '.
            'StockLedgerService::receive()\'s documented rule that only the '.
            'pooled COST balance is skipped.'
        );
    }

    /**
     * REGRESSION GUARD — audit finding MFG-002, the business
     * consequence of MFG-001.
     *
     * Because the pooled quantity never rises, stock that physically
     * exists in the warehouse cannot be issued through any ordinary
     * path: issue() checks the pooled balance and refuses. The units
     * are strandedno ordinary delivery, transfer or adjustment can
     * move them.
     */
    public function test_job_order_output_cannot_be_issued_through_an_ordinary_delivery(): void
    {
        $this->runJobOrder('10', '100');

        // A sales order unrelated to any job order — the ordinary path.
        $this->scenario->products = [$this->finishedGood];
        $so = $this->scenario->createSalesOrder([[$this->finishedGood, 5, 250.00]], '2026-03-01');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/[Ii]nsufficient/');

        $this->scenario->deliver($so, '2026-03-05');
    }

    /**
     * Cross-module consistency: the two inventory reports must agree.
     *
     * categoryValueStatement() (Accounting Dashboard) sums
     * stock_movements, which includes job_receipt rows. The Stock
     * Balance page reads ProductStockBalance, which does not. This
     * test measures the gap directly.
     */
    public function test_movement_derived_value_and_pooled_balance_disagree_after_a_job_order(): void
    {
        $this->runJobOrder('10', '100');

        $movementValue = StockMovement::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $this->finishedGood->id)
            ->whereIn('movement_type', StockMovement::INBOUND_TYPES)
            ->get()
            ->reduce(fn ($c, $m) => bcadd($c, (string) $m->wac_total_value, 2), '0.00');

        $pooledValue = (string) (ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('product_id', $this->finishedGood->id)
            ->value('wac_total_value') ?? '0.00');

        $this->assertMoneyEquals('1000.00', $movementValue, 'The movement ledger sees the output.');
        $this->assertMoneyEquals('0.00', $pooledValue, 'The pooled balance does not.');

        $this->assertSame(
            1,
            bccomp($movementValue, $pooledValue, 2),
            'CURRENT BEHAVIOUR (audit finding MFG-003): the Accounting Dashboard '.
            'inventory figure, derived from stock_movements, exceeds the Stock '.
            'Balance page figure, derived from ProductStockBalance, by the value '.
            'of undelivered job order output.'
        );
    }

    /**
     * REGRESSION GUARD — audit finding MFG-004, FIXED 2026-09.
     *
     * JobOrderService::complete() deliberately does not net WIP to
     * zero — whatever remains is that job's variance, swept out
     * later by the Variance Closing step. That is the documented
     * design, and it is only sound while the residue is economic.
     *
     * It was not. complete() derived the standard total by
     * multiplying the 4dp per-unit snapshot back out by the
     * quantity, which cannot always reproduce the total that went
     * in — a quantity of 3 against 1,000.00 of material left 0.01
     * behind with nothing economic about it, reported as a
     * manufacturing variance all the same.
     *
     * The fix stores the exact material total at job creation
     * (estimated_material_cost_total, migration 000281) and uses it
     * directly, so nothing is lost to division:
     *   consumed 100 units @ WAC 10.00
     *     -> WIP debited                                  1,000.00
     *   estimated_material_cost_total, snapshotted whole            
     *     -> WIP credited                                 1,000.00
     *   residue left in WIP                                   0.00
     *
     * This is the non-divisible case — 1,000.00 over 3 units — which
     * is precisely the case that used to fail. The evenly-divisible
     * control below never produced a residue either way, so it is
     * the test above that proves the fix.
     */
    public function test_a_non_divisible_job_order_leaves_no_rounding_residue_in_wip(): void
    {
        $job = $this->runJobOrder('3', '100');

        $wipAccountId = $this->scenario->coaId('135002');
        $wipBalance   = $this->glBalanceById($this->scenario->companyId, $wipAccountId);

        $glFinishedGoods = $this->glBalanceById(
            $this->scenario->companyId,
            (int) $this->finishedGood->inventory_account_id
        );

        $movementValue = (string) StockMovement::withoutGlobalScopes()
            ->where('company_id', $this->scenario->companyId)
            ->where('job_order_id', $job->id)
            ->where('movement_type', StockMovement::TYPE_JOB_RECEIPT)
            ->value('wac_total_value');

        // The general ledger and the stock movement ledger DO agree
        // with each other — the standard cost is applied consistently.
        $this->assertMoneyEquals(
            $glFinishedGoods,
            $movementValue,
            'Finished goods value must agree between the GL and the stock ledger.'
        );
        $this->assertMoneyEquals(
            '1000.00',
            $glFinishedGoods,
            'MFG-004 fix: finished goods carry the whole 1,000.00 that went into '.
            'the job, not 999.99 — the exact total is used, never multiplied back '.
            'out of a 4dp per-unit rate.'
        );

        // ...and WIP clears completely, so nothing is reported as a
        // variance that is not one.
        $this->assertMoneyEquals(
            '0.00',
            $wipBalance,
            'MFG-004 fix: no rounding residue is left in Manufacturing WIP, so a '.
            'reported variance now always means a real cost difference.'
        );

        $this->assertLedgerBalanced($this->scenario->companyId, 'After a non-divisible job order.');
    }

    /**
     * The control case: an evenly-divisible quantity, which cleared
     * WIP to zero before the MFG-004 fix as well. Kept so the two
     * cases stay side by side — a regression that reintroduced the
     * multiply-back calculation would break the test above and leave
     * this one passing, which is exactly how the defect hid.
     */
    public function test_wip_clears_exactly_when_the_quantity_divides_evenly(): void
    {
        $this->runJobOrder('10', '100');

        $wipBalance = $this->glBalanceById(
            $this->scenario->companyId,
            $this->scenario->coaId('135002')
        );

        $this->assertMoneyEquals(
            '0.00',
            $wipBalance,
            'With an evenly divisible quantity, WIP must clear completely.'
        );
    }
}
