<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditH12H14H15Test
//
//  Regression tests for three High audit findings fixed in
//  September 2026:
//
//    H12 — month-end foreign-currency revaluation (IAS 21): a USD
//          bank account and an open USD customer invoice are restated
//          at the month-end rate, the gain goes to 432, the entry is
//          reversed on the 1st of the next month, the USD balance
//          itself never moves, a month-end cannot be run twice, and
//          cancelling reverses both entries.
//    H14 — Contract Dashboard: draft invoices are ignored; a USD
//          contract shows its collections in USD (not EGP under a USD
//          heading) and the main-currency collected figure is not
//          multiplied by the rate a second time.
//    H15 — treasury dashboards: a USD bank shows USD 1,000 under USD
//          with its EGP equivalent beside it; USD cheques and USD
//          invoices are aged under USD at their USD amounts.
//
//  Every step goes through the real services (see ErpScenario), except
//  where noted. Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\CompanyActiveCurrency;
use App\Models\Currency;
use App\Models\CustomerInvoice;
use App\Models\ExchangeRate;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FxRevaluationRun;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\ForeignCurrencyLedgerService;
use App\Services\Accounting\FxRevaluationService;
use App\Services\Accounting\LedgerStatementService;
use App\Services\ChartOfAccountService;
use App\Services\ContractDashboardService;
use App\Services\FinancialInstitutions\BankService;
use App\Services\FinancialInstitutions\CashStatusDashboardService;
use App\Services\FinancialInstitutions\ChequeAgingService;
use App\Services\FinancialInstitutions\InvoiceAgingService;
use App\Services\OpeningBalanceService;
use App\Services\Projects\ProjectService;
use App\Services\Projects\SalesOrderService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditH12H14H15Test extends ErpTestCase
{
    private ErpScenario $s;
    private OpeningBalanceService $ob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->s  = (new ErpScenario($this->actor))->bootstrap(1);
        $this->ob = app(OpeningBalanceService::class);
    }

    // ── H12 + H15: USD bank account ───────────────────────────────

    public function test_usd_bank_is_revalued_at_month_end_and_reversed_on_day_one(): void
    {
        $cid = $this->s->companyId;
        $usd = $this->activateUsd();
        $this->rate($usd, '2026-01-01', '50');
        $this->rate($usd, '2026-01-31', '52');

        // Migrated in with USD 1,000 in the bank at 50 = EGP 50,000.
        $bank = $this->usdBankAccount($usd);
        $coa  = (int) $bank->coa_account_id;
        $this->ob->saveCoa($cid, ['account_id' => $coa, 'entry_side' => 'debit', 'amount' => '50000',
            'currency_id' => $usd, 'amount_foreign' => '1000', 'exchange_rate' => '50'], $this->actor);
        $this->ob->saveCoa($cid, ['account_id' => $this->s->coaId('314'), 'entry_side' => 'credit', 'amount' => '50000'], $this->actor);
        $this->ob->post($cid, $this->actor);

        $ledger = app(ForeignCurrencyLedgerService::class);
        $b = $ledger->balance($cid, $coa, '2026-01-15');
        $this->assertNotNull($b, 'H12: a USD bank account must be tracked as a foreign-currency account.');
        $this->assertMoneyEquals('1000.00', $b['native'], 'H12: the opening balance carries its USD amount.');
        $this->assertMoneyEquals('50000.00', $b['functional']);

        // H15 — the Cash Status dashboard shows USD under USD, EGP beside it.
        $dash = app(CashStatusDashboardService::class)->build($cid, '2026-01-15');
        $this->assertMoneyEquals('1000.00', $dash['reports']['cash_and_banks']['USD'], 'H15: USD 1,000, not EGP 50,000, under the USD tab.');
        $this->assertMoneyEquals('50000.00', $dash['reportsFunctional']['cash_and_banks']['USD'], 'H15: EGP equivalent shown beside it.');
        $this->assertSame(0, $dash['incompleteNative']['USD'] ?? 0);

        // H12 — preview and post the January revaluation.
        $svc = app(FxRevaluationService::class);
        $preview = $svc->preview($cid, '2026-01-31');
        $this->assertSame([], $preview['missing_rates']);
        $item = collect($preview['items'])->firstWhere('chart_of_account_id', $coa);
        $this->assertNotNull($item, 'H12: the USD bank must be in the revaluation.');
        $this->assertMoneyEquals('52000.00', $item['revalued_balance']);
        $this->assertMoneyEquals('2000.00', $item['difference']);
        $this->assertMoneyEquals('2000.00', $preview['total_gain']);

        $run = $svc->post($cid, '2026-01-31', null, $this->actor);
        $this->assertSame('2026-01-31', $run->journalEntry->entry_date->toDateString());
        $this->assertSame('2026-02-01', $run->reversalJournalEntry->entry_date->toDateString());

        $statement = app(LedgerStatementService::class);
        $this->assertMoneyEquals('52000.00', $statement->totals($cid, $coa, null, '2026-01-31')['ending_balance'], 'H12: bank at 1,000 × 52 on the month-end.');
        $this->assertMoneyEquals('50000.00', $statement->totals($cid, $coa, null, '2026-02-01')['ending_balance'], 'H12: back to the booked rate on day 1.');
        $this->assertMoneyEquals('-2000.00', $this->balanceAsOf($cid, $this->s->coaId('432'), '2026-01-31'), 'H12: EGP 2,000 unrealised gain in 432.');
        $this->assertMoneyEquals('1000.00', $ledger->balance($cid, $coa, '2026-01-31')['native'], 'H12: the USD balance itself never moves.');
        $this->assertMoneyEquals('1000.00', $ledger->balance($cid, $coa, '2026-02-01')['native']);

        // A month-end cannot be revalued twice.
        try {
            $svc->post($cid, '2026-01-31', null, $this->actor);
            $this->fail('H12: a second run for the same month-end must be refused.');
        } catch (\DomainException) {
            $this->addToAssertionCount(1);
        }

        // Cancel reverses both entries and keeps the run.
        $svc->cancel($cid, $run, 'Wrong rate typed', $this->actor);
        $this->assertSame(FxRevaluationRun::STATUS_CANCELLED, $run->fresh()->status);
        $this->assertMoneyEquals('50000.00', $statement->totals($cid, $coa, null, '2026-01-31')['ending_balance'], 'H12: cancel undoes the month-end entry.');

        $this->assertLedgerBalanced($cid);
    }

    // ── H12 + H15: open USD customer invoice ──────────────────────

    public function test_open_usd_invoice_is_revalued_and_aged_in_usd(): void
    {
        $cid = $this->s->companyId;
        $usd = $this->activateUsd();
        $this->rate($usd, '2026-01-01', '50');
        $this->rate($usd, '2026-01-31', '52');

        $this->ob->savePartnerInvoice($cid, [
            'partner_id' => $this->s->customers[0]->id, 'partner_type' => 'customer', 'account_id' => $this->s->coaId('133002'),
            'invoice_ref' => 'USD-INV-1', 'invoice_date' => '2025-12-15', 'due_date' => '2026-03-01',
            'invoice_amount' => '50000', 'previous_collection' => '0',
            'currency_id' => $usd, 'amount_foreign' => '1000', 'exchange_rate' => '50',
        ], $this->actor);
        $this->ob->saveCoa($cid, ['account_id' => $this->s->coaId('314'), 'entry_side' => 'credit', 'amount' => '50000'], $this->actor);
        $this->ob->post($cid, $this->actor);

        // H15 — aged under USD at USD 1,000 (was EGP 50,000 under USD).
        $aging = app(InvoiceAgingService::class)->build($cid, '2026-01-15', ['EGP', 'USD']);
        $usdTotal = collect($aging['customer']['USD']['totals'])->flatten()->reduce(fn ($c, $v) => bcadd($c, (string) $v, 2), '0');
        $this->assertMoneyEquals('1000.00', $usdTotal, 'H15: invoice aged at its USD amount.');
        $this->assertSame([], $aging['customer']['EGP']['totals']);

        // H12 — revalued document by document on 133002.
        $preview = app(FxRevaluationService::class)->preview($cid, '2026-01-31');
        $item = collect($preview['items'])->firstWhere('item_type', 'customer_invoice');
        $this->assertNotNull($item, 'H12: the open USD invoice must be revalued.');
        $this->assertMoneyEquals('1000.00', $item['native_balance']);
        $this->assertMoneyEquals('50000.00', $item['book_balance']);
        $this->assertMoneyEquals('2000.00', $item['difference']);

        $run = app(FxRevaluationService::class)->post($cid, '2026-01-31', null, $this->actor);
        $this->assertMoneyEquals('52000.00', $this->balanceAsOf($cid, $this->s->coaId('133002'), '2026-01-31'));
        $this->assertMoneyEquals('50000.00', $this->balanceAsOf($cid, $this->s->coaId('133002'), '2026-02-01'));
        $this->assertNotNull($run->reversal_journal_entry_id);
        $this->assertLedgerBalanced($cid);
    }

    // ── H15: cheques aged in their own currency ───────────────────

    public function test_usd_cheque_is_aged_under_usd_not_the_first_currency(): void
    {
        $cid = $this->s->companyId;
        $usd = $this->activateUsd();
        $this->rate($usd, '2026-01-01', '50');

        $this->ob->saveNoteReceivable($cid, [
            'partner_id' => $this->s->customers[0]->id, 'check_number' => 'USD-9', 'drawee_bank' => 'Bank',
            'check_date' => '2025-12-20', 'due_date' => '2026-04-05', 'amount' => '5000',
            'currency_id' => $usd, 'amount_foreign' => '100', 'exchange_rate' => '50', 'status' => 'in_safe',
        ], $this->actor);
        $this->ob->saveCoa($cid, ['account_id' => $this->s->coaId('314'), 'entry_side' => 'credit', 'amount' => '5000'], $this->actor);
        $this->ob->post($cid, $this->actor);

        $aging = app(ChequeAgingService::class)->build($cid, '2026-03-01', ['EGP', 'USD']);
        $usdTotal = collect($aging['customer']['USD']['totals'])->flatten()->reduce(fn ($c, $v) => bcadd($c, (string) $v, 2), '0');
        $this->assertMoneyEquals('100.00', $usdTotal, 'H15: USD 100 cheque under USD.');
        $this->assertSame([], $aging['customer']['EGP']['totals'], 'H15: nothing under EGP.');
    }

    // ── H14: Contract Dashboard ───────────────────────────────────

    public function test_contract_dashboard_counts_posted_only_and_shows_contract_currency(): void
    {
        $cid = $this->s->companyId;
        $usd = $this->activateUsd();
        $this->rate($usd, '2026-01-01', '50');
        $product = $this->s->products[0];

        $project = app(ProjectService::class)->create($cid, [
            'name_en' => 'USD Tower', 'partner_id' => $this->s->customers[0]->id,
            'contract_value' => 10000, 'currency_id' => $usd, 'exchange_rate' => 50,
            'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'active',
        ], $this->actor);

        $so = app(SalesOrderService::class)->create($cid, [
            'project_id' => $project->id, 'partner_id' => $this->s->customers[0]->id, 'subject' => 'USD SO',
            'currency_id' => $usd, 'exchange_rate' => 50,
            'issuance_date' => '2026-02-01', 'start_date' => '2026-02-01', 'end_date' => '2026-12-31',
            'payment_term_id' => $this->s->paymentTermId,
            'lines' => [[
                'product_id' => $product->id, 'description' => $product->name_en, 'quantity' => 20,
                'uom_id' => $product->primary_uom_id, 'unit_price' => 100, 'revenue_account_id' => $product->revenue_account_id,
            ]],
        ], $this->actor);
        app(SalesOrderService::class)->activate($so, $this->actor);
        $soLine = $so->fresh('lines')->lines->first();

        $invoiceSvc = app(CustomerInvoiceService::class);
        $make = fn (int $qty) => $invoiceSvc->create($cid, [
            'sales_order_id' => $so->id, 'partner_id' => $so->partner_id, 'invoice_date' => '2026-02-10',
            'currency_id' => $usd, 'exchange_rate' => 50, 'payment_term_id' => $this->s->paymentTermId,
            'lines' => [[
                'sales_order_line_id' => $soLine->id, 'product_id' => $product->id, 'description' => $product->name_en,
                'quantity' => $qty, 'uom_id' => $soLine->uom_id, 'unit_price' => 100, 'vat_rate' => 0,
            ]],
        ], $this->actor);

        $posted = $invoiceSvc->post($make(10), $this->actor, overrideSoLimit: true); // USD 1,000 = EGP 50,000
        $make(5);                                                                     // a DRAFT — must not count

        // Half collected. Set directly (a dashboard read test): the app
        // keeps amount_paid / amount_outstanding in EGP, at the invoice rate.
        CustomerInvoice::withoutGlobalScopes()->whereKey($posted->id)->update(['amount_paid' => '25000.00', 'amount_outstanding' => '25000.00']);

        $data = app(ContractDashboardService::class)->build($cid, '2026-01-01', '2026-12-31');
        $row = collect($data['details']['all'])->firstWhere('id', $project->id);

        $this->assertSame(1, $row['invoice_count'], 'H14: the draft invoice is not counted.');
        $this->assertMoneyEquals('1000.00', $row['invoiced'], 'H14: invoiced in USD.');
        $this->assertMoneyEquals('500.00', $row['collected'], 'H14: collected in USD, not EGP 25,000 under USD.');
        $this->assertMoneyEquals('500.00', $row['uncollected']);
        $this->assertMoneyEquals('9000.00', $row['remaining']);
        $this->assertMoneyEquals('25000.00', $data['mainCurrencyTotals']['collected'], 'H14: main-currency collected is not converted twice.');
        $this->assertMoneyEquals('25000.00', $data['mainCurrencyTotals']['uncollected']);
        $this->assertSame(0, $data['dataQuality']['unbalanced_invoice_count'], 'H14: no false "unbalanced" alarm on a USD invoice.');
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function activateUsd(): int
    {
        $usd = (int) Currency::withoutGlobalScopes()->whereNull('company_id')->where('code', 'USD')->value('id');

        CompanyActiveCurrency::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $this->s->companyId, 'currency_id' => $usd],
            ['is_functional' => false, 'is_active' => true]
        );

        return $usd;
    }

    private function rate(int $currencyId, string $date, string $rate): void
    {
        ExchangeRate::create([
            'company_id' => $this->s->companyId, 'currency_id' => $currencyId, 'rate' => $rate,
            'rate_date' => $date, 'source' => ExchangeRate::SOURCE_MANUAL,
            'created_by' => $this->actor->id, 'created_at' => now(),
        ]);
    }

    private function usdBankAccount(int $usd): BankAccount
    {
        $bank = app(BankService::class)->createBank($this->s->companyId, ['name_en' => 'USD Bank', 'is_active' => true], $this->actor);
        $leaf = app(ChartOfAccountService::class)->create($this->s->companyId, [
            'parent_id' => $this->s->coaId('132'), 'use_auto_code' => true, 'name_en' => 'USD Bank — Current',
        ], $this->actor);

        return app(BankService::class)->createBankAccount($this->s->companyId, $bank, [
            'account_name' => 'USD Bank — Current', 'account_number' => '200'.random_int(1000000, 9999999),
            'account_type' => 'current', 'currency_id' => $usd, 'coa_account_id' => $leaf->id, 'is_active' => true,
        ], $this->actor);
    }

    /** Debit − credit on an account up to and including $date. */
    private function balanceAsOf(int $companyId, int $accountId, string $date): string
    {
        return app(LedgerStatementService::class)->totals($companyId, $accountId, null, $date)['ending_balance'];
    }
}
