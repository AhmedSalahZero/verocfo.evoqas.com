<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditH3H4H5H7H9Test
//
//  Regression tests for the High audit findings fixed in Sept 2026:
//    H3 — manual journal entries wait for a second person; control
//         accounts refuse manual postings; nobody approves their own.
//    H4 — only a Company Admin reopens a closed month, with a reason.
//    H5 — a posted customer invoice reopens only after a DIFFERENT
//         approver approves a written request.
//    H7 — a collection cannot settle another customer's invoice.
//    H9 — TenantContext::runAs() makes the company filter apply with
//         no logged-in user (jobs and commands).
//
//  Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\AccountingPeriod;
use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\ManualJournalRequest;
use App\Models\Partner;
use App\Models\User;
use App\Services\Accounting\CustomerPaymentService;
use App\Services\Accounting\DocumentReopenService;
use App\Services\Accounting\ManualJournalApprovalService;
use App\Services\FiscalYearService;
use App\Services\PartnerService;
use App\Support\TenantContext;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditH3H4H5H7H9Test extends ErpTestCase
{
    private ErpScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = (new ErpScenario($this->actor))->bootstrap(1);
    }

    // ── H3 ────────────────────────────────────────────────────────

    public function test_manual_journal_waits_for_a_second_person(): void
    {
        $cid   = $this->s->companyId;
        $maker = $this->companyUser(User::ROLE_USER);
        $checker = $this->companyUser(User::ROLE_COMPANY_ADMIN);

        $svc = app(ManualJournalApprovalService::class);
        $request = $svc->submit($cid, $this->manualJe('543', '229'), $maker);

        $this->assertSame(ManualJournalRequest::STATUS_PENDING, $request->status);
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('company_id', $cid)->where('source_type', JournalEntry::SOURCE_MANUAL)->count(),
            'H3: nothing may reach the ledger before approval.');

        try {
            $svc->approve($request, $maker);
            $this->fail('H3: the maker must not be able to approve his own journal.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('someone else', $e->getMessage());
        }

        $approved = $svc->approve($request->fresh(), $checker);
        $this->assertSame(ManualJournalRequest::STATUS_APPROVED, $approved->status);
        $this->assertNotNull($approved->journal_entry_id);
        $this->assertMoneyEquals('1500.00', $this->glBalance($cid, '543'));
        $this->assertLedgerBalanced($cid);
    }

    public function test_manual_journal_cannot_touch_a_control_account(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/control account/i');

        app(ManualJournalApprovalService::class)->submit($this->s->companyId, $this->manualJe('133001', '229'), $this->actor);
    }

    // ── H4 ────────────────────────────────────────────────────────

    public function test_only_an_admin_with_a_reason_reopens_a_closed_month(): void
    {
        $period = AccountingPeriod::withoutGlobalScopes()->where('company_id', $this->s->companyId)->orderByDesc('start_date')->firstOrFail();
        // The newest month is used because months must be reopened
        // newest-first; closing it leaves no later open month in the way.
        $period->update(['status' => AccountingPeriod::STATUS_CLOSED]);
        $fy = app(FiscalYearService::class);

        try {
            $fy->reopenPeriod($period->fresh(), $this->companyUser(User::ROLE_USER), 'Late supplier invoice found');
            $this->fail('H4: an ordinary user must not reopen a closed month.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Company Admin', $e->getMessage());
        }

        try {
            $fy->reopenPeriod($period->fresh(), $this->actor, 'short');
            $this->fail('H4: a reason is mandatory.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('reason', $e->getMessage());
        }

        $reopened = $fy->reopenPeriod($period->fresh(), $this->actor, 'Late supplier invoice found in audit');
        $this->assertSame(AccountingPeriod::STATUS_OPEN, $reopened->status);
        $this->assertSame('Late supplier invoice found in audit', $reopened->reopen_reason);
    }

    // ── H5 ────────────────────────────────────────────────────────

    public function test_posted_invoice_reopens_only_after_a_different_approver(): void
    {
        $product = $this->s->products[0];
        $this->s->receiveStock([[$product, 10, 10.00]], '2026-01-10');
        $so = $this->s->createSalesOrder([[$product, 2, 25.00]], '2026-02-01');
        $this->s->deliver($so, '2026-02-05');
        $invoice = $this->s->invoice($so, '2026-02-05');

        $maker   = $this->companyUser(User::ROLE_USER);
        $checker = $this->companyUser(User::ROLE_COMPANY_ADMIN);
        $svc     = app(DocumentReopenService::class);

        $request = $svc->requestReopen($invoice, $maker, 'Wrong VAT rate applied on line 1');
        $this->assertSame('posted', $invoice->fresh()->status, 'H5: requesting alone must not reopen the invoice.');

        try {
            $svc->approve($request, $maker);
            $this->fail('H5: the requester must not approve his own reopen.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('someone else', $e->getMessage());
        }

        $svc->approve($request->fresh(), $checker);
        $this->assertSame(CustomerInvoice::STATUS_DRAFT, $invoice->fresh()->status);
        $this->assertLedgerBalanced($this->s->companyId);
    }

    // ── H7 ────────────────────────────────────────────────────────

    public function test_a_collection_cannot_settle_another_customers_invoice(): void
    {
        $product = $this->s->products[0];
        $this->s->receiveStock([[$product, 10, 10.00]], '2026-01-10');
        $so = $this->s->createSalesOrder([[$product, 2, 25.00]], '2026-02-01');
        $this->s->deliver($so, '2026-02-05');
        $invoiceOfA = $this->s->invoice($so, '2026-02-05', 0);

        $customerB = app(PartnerService::class)->create($this->s->companyId, [
            'name_en' => 'Customer B', 'types' => ['customer'], 'collection_term_id' => $this->s->paymentTermId,
        ], $this->actor);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/different customer/i');

        app(CustomerPaymentService::class)->record($this->s->companyId, [
            'partner_id' => $customerB->id, 'collection_type' => 'settlement', 'payment_date' => '2026-02-10',
            'money_type' => 'cash_in_safe', 'safe_account_id' => $this->s->createCashSafe(),
            'receive_currency_id' => $this->s->currencyId, 'invoice_currency_id' => $this->s->currencyId,
            'amount' => '50',
            'settlements' => [['customer_invoice_id' => $invoiceOfA->id, 'settlement_amount' => '50']],
        ], $this->actor);
    }

    // ── H9 ────────────────────────────────────────────────────────

    public function test_run_as_filters_queries_without_a_logged_in_user(): void
    {
        $other = (new ErpScenario($this->actor))->bootstrap(1);

        $this->assertFalse(auth()->check(), 'Precondition: no logged-in user, like a job.');

        $seen = TenantContext::runAs($this->s->companyId, fn () => Partner::pluck('company_id')->unique()->values()->all());
        $this->assertSame([$this->s->companyId], array_map('intval', $seen), 'H9: only this company\'s partners are visible.');

        $this->assertNotContains($other->companyId, $seen);
        $this->assertFalse(app()->bound('currentCompanyId'), 'H9: the binding is removed again afterwards.');
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function manualJe(string $debitCode, string $creditCode): array
    {
        return [
            'entry_date'   => '2026-03-31',
            'narration'    => 'Test adjustment',
            'debit_lines'  => [['chart_of_account_id' => $this->s->coaId($debitCode), 'amount' => '1500']],
            'credit_lines' => [['chart_of_account_id' => $this->s->coaId($creditCode), 'amount' => '1500']],
        ];
    }

    private function companyUser(string $role): User
    {
        return User::withoutGlobalScopes()->create([
            'name'       => 'User '.bin2hex(random_bytes(3)),
            'email'      => 'u-'.bin2hex(random_bytes(4)).'@vero-cfo.test',
            'password'   => bcrypt('irrelevant'),
            'role'       => $role,
            'company_id' => $this->s->companyId,
            'lang_pref'  => 'en',
            'timezone'   => 'Africa/Cairo',
            'is_active'  => true,
        ]);
    }
}
