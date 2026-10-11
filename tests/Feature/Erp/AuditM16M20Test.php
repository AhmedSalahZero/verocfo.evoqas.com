<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM16M20Test
//
//   M-16  Variance closing keeps the job-segment value and the stock
//         movements in step (so the value statements agree).
//   M-17  Changing a product's category moves its stock value to the new
//         inventory account; its primary unit is locked once stock has
//         moved; unit-of-measure lines keep their ids across a save.
//   M-18  Stock adjustments carry a reason and need a second person.
//   M-20  A cost corrected after depreciation posted is spread over the
//         remaining months; posted periods are left alone.
//
//  M-19 (prepaid amortisation dates) has no test here: building a
//  Prepaid Expense Bill needs fixtures the harness does not have.
//
//  Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\FixedAsset;
use App\Models\FixedAssetCostLine;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\JobOrder;
use App\Models\JobOrderStockBalance;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockAdjustmentRequest;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Accounting\FixedAssetService;
use App\Services\Accounting\JobOrderService;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\StockAdjustmentService;
use App\Services\Accounting\VarianceClosingService;
use App\Services\ChartOfAccountService;
use App\Services\ProductCategoryService;
use App\Services\ProductService;
use Illuminate\Support\Carbon;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditM16M20Test extends ErpTestCase
{
    private ErpScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = (new ErpScenario($this->actor))->bootstrap(1);
        $this->s->receiveStock([[$this->s->products[0], 50, 10.00]], '2026-01-10');
    }

    private function refused(callable $fn, string $contains): void
    {
        try {
            $fn();
            $this->fail('Expected the action to be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    // ── M-16 ──────────────────────────────────────────────────────

    public function test_variance_closing_leaves_a_movement_for_the_job_segment(): void
    {
        $category = app(ProductCategoryService::class)->create($this->s->companyId, ['name_en' => 'Mfg'], $this->actor);
        $raw = app(ProductService::class)->create($this->s->companyId, [
            'use_auto_code' => true, 'name_en' => 'Steel', 'category_id' => $category->id,
            'usage_types' => [Product::TYPE_RAW_MATERIAL], 'primary_uom_id' => $this->s->unitId,
        ], $this->actor);
        $fg = app(ProductService::class)->create($this->s->companyId, [
            'use_auto_code' => true, 'name_en' => 'Cabinet', 'category_id' => $category->id,
            'usage_types' => [Product::TYPE_SALEABLE], 'nature' => Product::NATURE_MANUFACTURED,
            'primary_uom_id' => $this->s->unitId,
        ], $this->actor);
        $this->s->receiveStock([[$raw, 1000, 10.00]], '2026-01-15');

        $svc = app(JobOrderService::class);
        $job = $svc->create($this->s->companyId, [
            'product_id' => $fg->id, 'quantity_to_produce' => '10',
            'raw_material_location_id' => $this->s->masterLocationId, 'output_location_id' => $this->s->masterLocationId,
            'planned_start_date' => '2026-02-01',
            'lines' => [['component_product_id' => $raw->id, 'quantity' => '100', 'uom_id' => $raw->primary_uom_id]],
        ], $this->actor);
        $svc->start($job, $this->actor);
        $svc->consumeMaterial($job, $raw->id, '110', $this->actor, '2026-02-05');
        $svc->complete($job->fresh(), $this->actor, '2026-02-10');

        app(VarianceClosingService::class)->post($this->s->companyId, 2026, 2, $this->actor);

        $segment = JobOrderStockBalance::withoutGlobalScopes()->where('job_order_id', $job->id)->firstOrFail();
        $in  = (string) StockMovement::withoutGlobalScopes()->where('job_order_id', $job->id)->whereIn('movement_type', StockMovement::INBOUND_TYPES)->sum('wac_total_value');
        $out = (string) StockMovement::withoutGlobalScopes()->where('job_order_id', $job->id)->whereIn('movement_type', StockMovement::OUTBOUND_TYPES)
            ->where('movement_type', '!=', StockMovement::TYPE_JOB_CONSUMPTION)->sum('wac_total_value');

        $this->assertSame('1100.00', (string) $segment->total_value);
        $this->assertSame('1100.00', bcsub($in, $out, 2), 'Movements and segment value agree.');
        $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('job_order_id', $job->id)
            ->where('movement_type', StockMovement::TYPE_VARIANCE_ABSORPTION_IN)->count());
    }

    // ── M-17 ──────────────────────────────────────────────────────

    private function productData(Product $p, array $over = []): array
    {
        return array_merge([
            'name_en' => $p->name_en, 'category_id' => $p->category_id, 'usage_types' => $p->usage_types,
            'primary_uom_id' => $p->primary_uom_id,
        ], $over);
    }

    public function test_changing_category_moves_the_stock_value_to_the_new_account(): void
    {
        $cid = $this->s->companyId;
        $p   = Product::withoutGlobalScopes()->find($this->s->products[0]->id);
        $old = (int) $p->inventory_account_id;
        $this->assertSame('500.00', $this->glBalanceById($cid, $old));

        $other = app(ProductCategoryService::class)->create($cid, ['name_en' => 'Second Category'], $this->actor);
        $updated = app(ProductService::class)->update($p, $this->productData($p, ['category_id' => $other->id]), $this->actor);

        $new = (int) $updated->inventory_account_id;
        $this->assertNotSame($old, $new, 'A different main category has its own inventory account.');
        $this->assertSame('0.00', $this->glBalanceById($cid, $old));
        $this->assertSame('500.00', $this->glBalanceById($cid, $new));
        $this->assertLedgerBalanced($cid, 'After the reclassification.');
    }

    public function test_primary_unit_is_locked_once_stock_has_moved(): void
    {
        $p = Product::withoutGlobalScopes()->find($this->s->products[0]->id);
        $otherUom = \App\Models\UnitOfMeasure::withoutGlobalScopes()->where('id', '!=', $p->primary_uom_id)->first();

        $this->refused(
            fn () => app(ProductService::class)->update($p, $this->productData($p, ['primary_uom_id' => $otherUom->id]), $this->actor),
            'primary unit'
        );
    }

    public function test_saving_a_product_keeps_its_unit_lines(): void
    {
        $p = Product::withoutGlobalScopes()->find($this->s->products[0]->id);
        $before = $p->uomLines()->pluck('id')->all();
        $this->assertNotEmpty($before);

        app(ProductService::class)->update($p, $this->productData($p, ['name_en' => 'Renamed']), $this->actor);

        $this->assertSame($before, $p->fresh()->uomLines()->pluck('id')->all(), 'Lines are updated in place, not recreated.');
    }

    // ── M-18 ──────────────────────────────────────────────────────

    private function maker(): User
    {
        return User::withoutGlobalScopes()->create([
            'name' => 'Maker', 'email' => 'maker-m18@vero-cfo.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_USER, 'company_id' => $this->s->companyId, 'lang_pref' => 'en',
            'timezone' => 'Africa/Cairo', 'is_active' => true,
        ]);
    }

    private function adjustmentData(array $over = []): array
    {
        return array_merge([
            'product_id' => $this->s->products[0]->id, 'inventory_location_id' => $this->s->masterLocationId,
            'direction' => 'out', 'quantity' => '5', 'movement_date' => '2026-02-01',
            'reason_code' => 'damaged', 'notes' => 'Water damage',
        ], $over);
    }

    public function test_an_adjustment_waits_for_a_second_person_before_posting(): void
    {
        $cid = $this->s->companyId;
        $svc = app(StockAdjustmentService::class);
        $maker = $this->maker();

        $request = $svc->submit($cid, $this->adjustmentData(), $maker);
        $this->assertSame(StockAdjustmentRequest::STATUS_PENDING, $request->status);
        $this->assertSame('0.00', $this->glBalance($cid, '516'), 'Nothing posts while pending.');

        $this->refused(fn () => $svc->approve($request->fresh(), $maker), 'someone else');

        $svc->approve($request->fresh(), $this->actor);
        $this->assertSame('50.00', $this->glBalance($cid, '516'), '5 units at 10.00.');
        $movement = StockMovement::withoutGlobalScopes()->find($request->fresh()->stock_movement_id);
        $this->assertSame('damaged', $movement->reason_code);
        $this->assertLedgerBalanced($cid, 'After the approved adjustment.');

        $this->refused(fn () => $svc->approve($request->fresh(), $this->actor), 'already been decided');
    }

    public function test_reason_rules_and_obsolescence_account(): void
    {
        $cid = $this->s->companyId;
        $svc = app(StockAdjustmentService::class);

        $this->refused(fn () => $svc->submit($cid, $this->adjustmentData(['reason_code' => null]), $this->actor), 'reason');
        $this->refused(fn () => $svc->submit($cid, $this->adjustmentData(['reason_code' => 'count_difference']), $this->actor), 'reference');

        $maker = $this->maker();
        $request = $svc->submit($cid, $this->adjustmentData(['reason_code' => 'obsolete', 'quantity' => '2']), $maker);
        $svc->approve($request, $this->actor);

        $this->assertSame('20.00', $this->glBalance($cid, '517'), 'Obsolescence goes to 517, not 516.');
        $this->assertSame('0.00', $this->glBalance($cid, '516'));

        $rejected = $svc->submit($cid, $this->adjustmentData(), $maker);
        $svc->reject($rejected, $this->actor, 'Not authorised');
        $this->assertSame(StockAdjustmentRequest::STATUS_REJECTED, $rejected->fresh()->status);
    }

    // ── M-20 ──────────────────────────────────────────────────────

    public function test_a_cost_corrected_after_depreciation_is_spread_forward(): void
    {
        $cid = $this->s->companyId;
        $coa = app(ChartOfAccountService::class);
        $assetAcc = $coa->create($cid, ['parent_id' => $this->s->coaId('111'), 'use_auto_code' => true, 'name_en' => 'Test Machinery'], $this->actor);
        $accDep   = $coa->create($cid, ['parent_id' => $this->s->coaId('113'), 'use_auto_code' => true, 'name_en' => 'Acc. Dep. Test Machinery'], $this->actor);

        $asset = FixedAsset::create([
            'company_id' => $cid, 'asset_code' => FixedAsset::nextCode($cid), 'name' => 'Test Machine',
            'fixed_asset_account_id' => $assetAcc->id, 'accumulated_depreciation_account_id' => $accDep->id,
            'currency_id' => $this->s->currencyId, 'inventory_location_id' => $this->s->masterLocationId,
            'cost_basis' => '12000', 'residual_value' => 0, 'useful_life_months' => 12, 'depreciation_method' => 'straight_line',
            'received_date' => '2026-01-01', 'depreciation_start_date' => '2026-01-01', 'accumulated_depreciation' => 0,
            'status' => FixedAsset::STATUS_IN_SERVICE, 'created_by' => $this->actor->id,
        ]);
        app(JournalEntryService::class)->post($cid, '2026-01-01', [
            ['chart_of_account_id' => $assetAcc->id, 'debit' => '12000', 'credit' => '0', 'description' => 'Asset bought'],
            ['chart_of_account_id' => $this->s->coaId('314'), 'debit' => '0', 'credit' => '12000', 'description' => 'Asset bought'],
        ], 'manual', null, 'Test asset', $this->actor);

        $svc = app(FixedAssetService::class);
        $svc->postMonthlyDepreciation($asset, $this->actor, Carbon::parse('2026-01-31'));

        $line = FixedAssetCostLine::create([
            'company_id' => $cid, 'fixed_asset_id' => $asset->id, 'cost_type' => FixedAssetCostLine::TYPE_ASSET_COST,
            'amount' => '12000', 'is_provisional' => true, 'description' => 'Provisional', 'created_by' => $this->actor->id,
        ]);
        $svc->confirmCostLine($line, '13200', $this->actor);

        $this->assertSame(0, FixedAssetDepreciationEntry::withoutGlobalScopes()->where('fixed_asset_id', $asset->id)
            ->where('status', FixedAssetDepreciationEntry::STATUS_REVERSED)->count(), 'January is not reversed.');
        $this->assertSame('1000.00', (string) $asset->fresh()->accumulated_depreciation);

        // (13,200 − 1,000 already posted) ÷ 11 months left = 1,109.09
        $feb = $svc->postMonthlyDepreciation($asset->fresh(), $this->actor, Carbon::parse('2026-02-28'));
        $this->assertSame('1109.09', (string) $feb->amount);
        $this->assertLedgerBalanced($cid, 'After the prospective change.');
    }
}
