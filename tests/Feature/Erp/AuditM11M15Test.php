<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM11M15Test
//
//  Audit M-11 … M-15:
//   M-11  Sales Order invoiced amount is kept in the order's own currency.
//   M-12  Applying an advance: 235 is relieved cleanly, on a chosen date
//         that cannot precede the documents.
//   M-13  A receipt dated before a later issue is accepted. The issue
//         journal stays as posted; the cost difference is a new journal.
//   M-14  The material variance of a completed order is cleared out of
//         WIP by the monthly Variance Closing.
//   M-15  A split override needs a reason; a closing can be reversed.
//
//  Foreign-currency paths of M-11 / M-12 are exercised only through the
//  functional-currency case here (no FX fixtures exist in the harness).
//
//  Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\JobOrder;
use App\Models\JobOrderStockBalance;
use App\Models\ManufacturingVarianceClosing;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Services\Accounting\CustomerPaymentService;
use App\Services\Accounting\JobOrderService;
use App\Services\Accounting\VarianceClosingService;
use App\Services\ProductCategoryService;
use App\Services\ProductService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditM11M15Test extends ErpTestCase
{
    private ErpScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = (new ErpScenario($this->actor))->bootstrap(1);
        $this->s->receiveStock([[$this->s->products[0], 50, 10.00]], '2026-01-10');
    }

    // ── M-11 ──────────────────────────────────────────────────────

    public function test_invoicing_keeps_the_order_total_in_the_order_currency(): void
    {
        $so = $this->s->createSalesOrder([[$this->s->products[0], 2, 50.00]]);
        $this->s->deliver($so);
        $this->s->invoice($so);

        $this->assertSame('100.00', (string) SalesOrder::withoutGlobalScopes()->find($so->id)->invoiced_amount);
        $this->assertSame(
            '100.00',
            SalesOrder::withoutGlobalScopes()->find($so->id)->inOrderCurrency('100.00', '100.00', $this->s->currencyId)
        );
    }

    // ── M-12 ──────────────────────────────────────────────────────

    public function test_applying_an_advance_clears_235_and_respects_the_date(): void
    {
        $so = $this->s->createSalesOrder([[$this->s->products[0], 2, 50.00]], '2026-02-01');
        $this->s->deliver($so, '2026-02-05');
        $invoice = $this->s->invoice($so, '2026-02-05');

        $svc = app(CustomerPaymentService::class);
        $payment = $svc->record($this->s->companyId, [
            'partner_id' => $invoice->partner_id, 'collection_type' => 'settlement', 'payment_date' => '2026-02-10',
            'money_type' => 'cash_in_safe', 'safe_account_id' => $this->s->createCashSafe(),
            'receive_currency_id' => $this->s->currencyId, 'invoice_currency_id' => $this->s->currencyId,
            'amount' => '50', 'settlements' => [],
        ], $this->actor);
        $this->assertSame('-50.00', $this->glBalance($this->s->companyId, '235'));

        try {
            $svc->allocate($payment, $invoice, '50', $this->actor, '2026-02-01');
            $this->fail('An application date before the documents must be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('before', $e->getMessage());
        }

        $allocation = $svc->allocate($payment->fresh(), $invoice->fresh(), '50', $this->actor, '2026-02-12');
        $this->assertSame('0.00', $this->glBalance($this->s->companyId, '235'), 'Nothing is left behind in 235.');
        $this->assertSame('0.00', (string) $allocation->fx_gain_loss_functional);

        $svc->unallocate($allocation, $this->actor);
        $this->assertSame('-50.00', $this->glBalance($this->s->companyId, '235'));
    }

    // ── M-13 ──────────────────────────────────────────────────────

    public function test_a_back_dated_receipt_is_accepted_and_the_ledger_stays_balanced(): void
    {
        $so = $this->s->createSalesOrder([[$this->s->products[0], 5, 50.00]], '2026-02-01');
        $this->s->deliver($so, '2026-02-20');

        $this->s->receiveStock([[$this->s->products[0], 10, 12.00]], '2026-02-10');
        $this->assertLedgerBalanced($this->s->companyId, 'After a receipt dated before the issue.');

        $this->s->receiveStock([[$this->s->products[0], 10, 12.00]], '2026-02-20');
        $this->assertLedgerBalanced($this->s->companyId, 'After the same-day receipt.');
    }

    // ── M-14 / M-15 (job order with a material overrun) ───────────

    private function jobWithOverrun(): JobOrder
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
        $svc->consumeMaterial($job, $raw->id, '110', $this->actor, '2026-02-05'); // 100 over plan = 1,000 vs 1,100
        return $svc->complete($job->fresh(), $this->actor, '2026-02-10');
    }

    public function test_material_variance_is_cleared_out_of_wip_at_closing(): void
    {
        $job = $this->jobWithOverrun();
        $this->assertSame('100.00', $this->glBalance($this->s->companyId, '135002'), 'Standard 1,000 relieved of 1,100 actual.');

        $closing = app(VarianceClosingService::class)->post($this->s->companyId, 2026, 2, $this->actor);

        $this->assertSame('0.00', $this->glBalance($this->s->companyId, '135002'), 'WIP is empty after closing.');
        $segment = JobOrderStockBalance::withoutGlobalScopes()->where('job_order_id', $job->id)->firstOrFail();
        $this->assertSame('1100.00', (string) $segment->total_value, 'The overrun is carried into the job segment.');
        $this->assertSame($closing->id, (int) JobOrder::withoutGlobalScopes()->find($job->id)->material_variance_closing_id);
        $this->assertLedgerBalanced($this->s->companyId, 'After variance closing.');

        $this->expectException(\DomainException::class);
        app(VarianceClosingService::class)->post($this->s->companyId, 2026, 2, $this->actor);
    }

    public function test_an_override_needs_a_reason_and_must_stay_in_range(): void
    {
        $job = $this->jobWithOverrun();
        $svc = app(VarianceClosingService::class);

        try {
            $svc->post($this->s->companyId, 2026, 2, $this->actor, ['job_orders' => [$job->id => '40']]);
            $this->fail('An override without a reason must be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        try {
            $svc->post($this->s->companyId, 2026, 2, $this->actor, ['job_orders' => [$job->id => '500']], 'Because');
            $this->fail('An override above the row total must be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('outside', $e->getMessage());
        }

        $closing = $svc->post($this->s->companyId, 2026, 2, $this->actor, ['job_orders' => [$job->id => '40']], 'Part of the batch was sold');
        $this->assertSame('Part of the batch was sold', $closing->override_reason);
    }

    public function test_a_closing_can_be_reversed_and_posted_again(): void
    {
        $job = $this->jobWithOverrun();
        $svc = app(VarianceClosingService::class);
        $closing = $svc->post($this->s->companyId, 2026, 2, $this->actor);

        try {
            $svc->reverse($this->s->companyId, $closing, $this->actor, ' ');
            $this->fail('A reason is required.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        $svc->reverse($this->s->companyId, $closing, $this->actor, 'Posted too early');

        $this->assertSame('100.00', $this->glBalance($this->s->companyId, '135002'), 'WIP is back as it was.');
        $segment = JobOrderStockBalance::withoutGlobalScopes()->where('job_order_id', $job->id)->firstOrFail();
        $this->assertSame('1000.00', (string) $segment->total_value);
        $this->assertNull(JobOrder::withoutGlobalScopes()->find($job->id)->material_variance_closing_id);
        $this->assertTrue(ManufacturingVarianceClosing::withoutGlobalScopes()->find($closing->id)->isReversed());
        $this->assertLedgerBalanced($this->s->companyId, 'After reversing the closing.');

        $again = $svc->post($this->s->companyId, 2026, 2, $this->actor);
        $this->assertNotSame($closing->id, $again->id);
        $this->assertSame('0.00', $this->glBalance($this->s->companyId, '135002'));
    }

    public function test_a_cleared_order_cannot_be_set_back_to_draft(): void
    {
        $job = $this->jobWithOverrun();
        app(VarianceClosingService::class)->post($this->s->companyId, 2026, 2, $this->actor);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already cleared');
        app(JobOrderService::class)->setToDraft($job->fresh(), $this->actor);
    }
}
