<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM9CustomerCreditTest
//
//  Audit M-9 (final part): a credit note larger than what its invoice
//  still owes (because the invoice was already paid) no longer fails.
//  The surplus becomes a Customer Credit Balance held in 235, which can
//  be applied to another invoice or refunded in cash — giving up the
//  proportional withholding tax (Cr 142).
//
//  The "already paid" state is simulated by zeroing the invoice's
//  outstanding amount and recording the tax the customer withheld, so
//  the test needs no payment flow of its own.
//
//  Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\CreditNote;
use App\Models\CustomerCreditBalance;
use App\Models\CustomerInvoice;
use App\Models\CustomerRefund;
use App\Models\User;
use App\Services\Accounting\CreditNoteService;
use App\Services\Accounting\CustomerCreditBalanceService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditM9CustomerCreditTest extends ErpTestCase
{
    private ErpScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = (new ErpScenario($this->actor))->bootstrap(1);
        $this->s->receiveStock([[$this->s->products[0], 50, 10.00]], '2026-01-10');
    }

    public function test_crediting_a_paid_invoice_creates_a_balance_in_235(): void
    {
        [$invoice, $balance] = $this->paidInvoiceWithCredit(30);

        $this->assertSame('30.00', (string) $balance->original_amount);
        $this->assertSame('30.00', (string) $balance->remaining_amount);
        $this->assertSame('3.00', (string) $balance->wht_portion, '30 of 100 → 30% of the 10.00 withheld.');
        $this->assertSame('-30.00', $this->glBalance($this->s->companyId, '235'), '235 carries what we owe the customer.');
        $this->assertSame('0.00', (string) $invoice->fresh()->amount_outstanding, 'The invoice must not go negative.');
    }

    public function test_the_balance_can_be_applied_and_undone(): void
    {
        [, $balance] = $this->paidInvoiceWithCredit(30);
        $next = $this->egpInvoice(2, 50.00, '2026-03-01');
        $svc  = app(CustomerCreditBalanceService::class);

        $app = $svc->apply($balance, ['customer_invoice_id' => $next->id, 'amount' => 20, 'application_date' => '2026-03-02'], $this->actor);
        $this->assertSame('80.00', (string) $next->fresh()->amount_outstanding);
        $this->assertSame('10.00', (string) $balance->fresh()->remaining_amount);
        $this->assertSame('-10.00', $this->glBalance($this->s->companyId, '235'));

        try {
            $svc->apply($balance->fresh(), ['customer_invoice_id' => $next->id, 'amount' => 11, 'application_date' => '2026-03-02'], $this->actor);
            $this->fail('More than the remaining balance must be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('left', $e->getMessage());
        }

        $svc->undoApplication($app, $this->actor);
        $this->assertSame('100.00', (string) $next->fresh()->amount_outstanding);
        $this->assertSame('30.00', (string) $balance->fresh()->remaining_amount);
        $this->assertSame('-30.00', $this->glBalance($this->s->companyId, '235'));
    }

    public function test_a_cash_refund_gives_up_the_proportional_withholding(): void
    {
        [, $balance] = $this->paidInvoiceWithCredit(30);
        $safe = $this->s->createCashSafe();
        $svc  = app(CustomerCreditBalanceService::class);

        $refund = $svc->refund($balance, ['amount' => 30, 'source_type' => 'cash_safe', 'safe_account_id' => $safe, 'refund_date' => '2026-03-01'], $this->actor);

        $this->assertSame('3.00', (string) $refund->wht_adjustment);
        $this->assertSame('27.00', (string) $refund->cash_amount);
        $this->assertSame('0.00', $this->glBalance($this->s->companyId, '235'));
        $this->assertSame('-3.00', $this->glBalance($this->s->companyId, '142'));
        $this->assertSame('-27.00', $this->glBalanceById($this->s->companyId, $safe));
        $this->assertSame(CustomerCreditBalance::STATUS_CLOSED, $balance->fresh()->status);

        $svc->reverseRefund($refund, $this->actor, 'Paid by mistake');
        $this->assertSame('-30.00', $this->glBalance($this->s->companyId, '235'));
        $this->assertSame('0.00', $this->glBalance($this->s->companyId, '142'));
        $this->assertSame(CustomerCreditBalance::STATUS_OPEN, $balance->fresh()->status);
        $this->assertSame(CustomerRefund::STATUS_REVERSED, $refund->fresh()->status);
    }

    public function test_the_withholding_override_is_bounded_and_cash_must_be_positive(): void
    {
        [, $balance] = $this->paidInvoiceWithCredit(30);
        $safe = $this->s->createCashSafe();
        $svc  = app(CustomerCreditBalanceService::class);
        $base = ['amount' => 30, 'source_type' => 'cash_safe', 'safe_account_id' => $safe, 'refund_date' => '2026-03-01'];

        try {
            $svc->refund($balance, $base + ['wht_adjustment' => 3.50], $this->actor);
            $this->fail('WHT above the stored share must be refused.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('between 0 and', $e->getMessage());
        }

        $refund = $svc->refund($balance, ['amount' => 10, 'wht_adjustment' => 0] + $base, $this->actor);
        $this->assertSame('10.00', (string) $refund->cash_amount);
    }

    public function test_the_person_who_posted_the_credit_note_cannot_refund_it(): void
    {
        [, $balance] = $this->paidInvoiceWithCredit(30);
        $safe  = $this->s->createCashSafe();
        $maker = User::withoutGlobalScopes()->create([
            'name' => 'Maker', 'email' => 'maker-m9@vero-cfo.test', 'password' => bcrypt('x'),
            'role' => User::ROLE_USER, 'company_id' => $this->s->companyId, 'lang_pref' => 'en',
            'timezone' => 'Africa/Cairo', 'is_active' => true,
        ]);
        CreditNote::withoutGlobalScopes()->where('id', $balance->credit_note_id)->update(['posted_by' => $maker->id]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('someone else');
        app(CustomerCreditBalanceService::class)->refund(
            $balance->fresh(),
            ['amount' => 30, 'source_type' => 'cash_safe', 'safe_account_id' => $safe],
            $maker
        );
    }

    public function test_the_credit_note_cannot_be_reversed_once_its_balance_is_used(): void
    {
        [, $balance] = $this->paidInvoiceWithCredit(30);
        $cnSvc = app(CreditNoteService::class);
        $safe  = $this->s->createCashSafe();

        $refund = app(CustomerCreditBalanceService::class)->refund(
            $balance, ['amount' => 10, 'source_type' => 'cash_safe', 'safe_account_id' => $safe, 'refund_date' => '2026-03-01'], $this->actor
        );

        try {
            $cnSvc->reverse(CreditNote::withoutGlobalScopes()->findOrFail($balance->credit_note_id), $this->actor, 'Raised in error');
            $this->fail('A used balance must block reversing its credit note.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('already been applied or refunded', $e->getMessage());
        }

        app(CustomerCreditBalanceService::class)->reverseRefund($refund, $this->actor, 'Undo before reversing');
        $cnSvc->reverse(CreditNote::withoutGlobalScopes()->findOrFail($balance->credit_note_id), $this->actor, 'Raised in error');
        $this->assertSame(CustomerCreditBalance::STATUS_REVERSED, $balance->fresh()->status);
        $this->assertSame('0.00', $this->glBalance($this->s->companyId, '235'));
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function egpInvoice(int $qty, float $price, string $date = '2026-02-05'): CustomerInvoice
    {
        $so = $this->s->createSalesOrder([[$this->s->products[0], $qty, $price]], $date);
        $this->s->deliver($so, $date);

        return $this->s->invoice($so, $date, 0);
    }

    /** @return array{0: CustomerInvoice, 1: CustomerCreditBalance} */
    private function paidInvoiceWithCredit(float $amount): array
    {
        $invoice = $this->egpInvoice(2, 50.00);
        $invoice->forceFill(['amount_outstanding' => '0.00', 'amount_wht_deducted' => '10.00'])->save();

        $svc = app(CreditNoteService::class);
        $cn  = $svc->create($this->s->companyId, [
            'customer_invoice_id' => $invoice->id, 'credit_date' => '2026-02-20', 'credit_reason' => 'Test',
            'lines' => [[
                'customer_invoice_line_id' => $invoice->lines()->first()->id, 'description' => 'Credit',
                'quantity' => 1, 'unit_price' => $amount, 'vat_rate' => 0,
            ]],
        ], $this->actor);
        $svc->post($cn, $this->actor);

        $balance = CustomerCreditBalance::withoutGlobalScopes()->where('credit_note_id', $cn->id)->firstOrFail();

        return [$invoice, $balance];
    }
}
