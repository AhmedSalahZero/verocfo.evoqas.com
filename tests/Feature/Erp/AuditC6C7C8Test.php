<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditC6C7C8Test
//
//  Regression tests for the three Critical audit findings fixed in
//  September 2026:
//
//    C6 — the Balance Sheet (and Cash Flow) must include what is
//         really posted to 314 Retained Earnings: migrated opening
//         retained earnings and later dividend declarations.
//    C7 — posting opening balances must create the real open items
//         behind the opening entry: open customer invoices and
//         supplier bills, cheque-register rows and opening stock —
//         and saving an opening invoice must no longer wipe the
//         inventory line from the opening trial balance.
//    C8 — foreign-currency amounts are converted at the right rate:
//         a USD cheque collected after the rate moved books realised
//         FX; a USD delivery books revenue in EGP and the invoice
//         clears Accrued Revenue (143) to exactly zero.
//
//  Every step goes through the real services, like the rest of this
//  suite (see ErpScenario). Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ChequePayable;
use App\Models\ChequeReceivable;
use App\Models\CompanyActiveCurrency;
use App\Models\Currency;
use App\Models\CustomerInvoice;
use App\Models\ExchangeRate;
use App\Models\ObCoaBalance;
use App\Models\ProductStockBalance;
use App\Models\SupplierBill;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\CashFlowStatementService;
use App\Services\Accounting\ChequePayableService;
use App\Services\Accounting\ChequeReceivableService;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\DeliveryNoteService;
use App\Services\Accounting\JournalEntryService;
use App\Services\OpeningBalanceService;
use App\Services\Projects\SalesOrderService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditC6C7C8Test extends ErpTestCase
{
    private ErpScenario $s;
    private OpeningBalanceService $ob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->s  = (new ErpScenario($this->actor))->bootstrap(1);
        $this->ob = app(OpeningBalanceService::class);
    }

    // ── C6 ────────────────────────────────────────────────────────

    public function test_balance_sheet_includes_migrated_retained_earnings_and_dividends(): void
    {
        $cid  = $this->s->companyId;
        $safe = $this->s->createCashSafe();

        // Migrated in with 100,000 of accumulated profit sitting in cash.
        $this->ob->saveCoa($cid, ['account_id' => $safe, 'entry_side' => 'debit', 'amount' => '100000'], $this->actor);
        $this->ob->saveCoa($cid, ['account_id' => $this->s->coaId('314'), 'entry_side' => 'credit', 'amount' => '100000'], $this->actor);
        $this->ob->post($cid, $this->actor);

        $report = $this->balanceSheetAsOf('2026-06-30');
        $this->assertTrue($report['is_balanced']['p'], 'C6: out by '.$report['difference']['p'].' after migrating retained earnings.');
        $this->assertMoneyEquals('100000.00', $this->retainedEarnings($report), 'C6: migrated retained earnings must show in 314.');

        // A dividend declared out of retained earnings: Dr 314 / Cr 229.
        app(JournalEntryService::class)->post(
            companyId: $cid, entryDate: '2026-03-31',
            lines: [
                ['chart_of_account_id' => $this->s->coaId('314'), 'debit' => '30000', 'credit' => '0', 'description' => 'Dividend'],
                ['chart_of_account_id' => $this->s->coaId('229'), 'debit' => '0', 'credit' => '30000', 'description' => 'Dividend'],
            ],
            sourceType: 'test_dividend', sourceId: null, narration: 'Dividend declared', actor: $this->actor,
        );

        $report = $this->balanceSheetAsOf('2026-06-30');
        $this->assertTrue($report['is_balanced']['p'], 'C6: out by '.$report['difference']['p'].' after a dividend.');
        $this->assertMoneyEquals('70000.00', $this->retainedEarnings($report));

        // Cash Flow reconciles across the dividend month (no cash moved).
        $cf = app(CashFlowStatementService::class)->build($cid, [[
            'key' => 'q1', 'label' => 'Q1', 'start_date' => '2026-02-01', 'end_date' => '2026-03-31',
        ]]);
        $this->assertTrue($cf['is_reconciled']['q1'], 'C6: cash flow off by '.$cf['reconciliation_difference']['q1']);

        $this->assertLedgerBalanced($cid);
    }

    // ── C7 ────────────────────────────────────────────────────────

    public function test_posting_opening_balances_creates_the_open_items(): void
    {
        $cid      = $this->s->companyId;
        $customer = $this->s->customers[0];
        $supplier = $this->s->suppliers[0];
        $product  = $this->s->products[0];
        $bank     = $this->s->createBankAccount();

        // Stock first, then an invoice — the invoice save used to wipe
        // the inventory auto-row (the "13%" delete filter).
        $this->ob->saveStock($cid, [
            'product_id' => $product->id, 'location_id' => $this->s->masterLocationId,
            'uom_id' => $product->primary_uom_id, 'quantity' => '10', 'total_value' => '500',
        ], $this->actor);
        $this->ob->savePartnerInvoice($cid, [
            'partner_id' => $customer->id, 'partner_type' => 'customer', 'account_id' => $this->s->coaId('133001'),
            'invoice_ref' => 'OLD-INV-1', 'invoice_date' => '2025-11-15', 'due_date' => '2026-02-15',
            'invoice_amount' => '6000', 'previous_collection' => '1000',
        ], $this->actor);
        $this->assertTrue(
            ObCoaBalance::withoutGlobalScopes()->where('company_id', $cid)->where('auto_populated', true)
                ->where('account_id', $product->inventory_account_id)->exists(),
            'C7: saving an opening invoice must not delete the opening inventory line.'
        );

        $this->ob->savePartnerInvoice($cid, [
            'partner_id' => $supplier->id, 'partner_type' => 'supplier', 'account_id' => $this->s->coaId('221001'),
            'invoice_ref' => 'SUP-77', 'invoice_date' => '2025-12-01', 'due_date' => '2026-01-31',
            'invoice_amount' => '3000', 'previous_collection' => '0',
        ], $this->actor);
        $this->ob->saveNoteReceivable($cid, [
            'partner_id' => $customer->id, 'check_number' => 'CHQ-IN-1', 'drawee_bank' => 'Customer Bank',
            'check_date' => '2025-12-20', 'due_date' => '2026-01-20', 'amount' => '2000', 'status' => 'in_safe',
        ], $this->actor);
        $this->ob->saveNotePayable($cid, [
            'partner_id' => $supplier->id, 'check_number' => 'CHQ-OUT-1', 'drawl_bank' => 'Our Bank',
            'check_date' => '2025-12-20', 'due_date' => '2026-01-25', 'amount' => '1000', 'status' => 'issued',
            'bank_account_id' => $bank->coa_account_id,
        ], $this->actor);

        // Debits 5,000 AR + 2,000 cheques + 500 stock = 7,500;
        // credits 3,000 AP + 1,000 cheques issued = 4,000 → 3,500 equity.
        $this->ob->saveCoa($cid, ['account_id' => $this->s->coaId('314'), 'entry_side' => 'credit', 'amount' => '3500'], $this->actor);
        $this->ob->post($cid, $this->actor);

        $invoice = CustomerInvoice::withoutGlobalScopes()->where('company_id', $cid)->where('is_opening_balance', true)->firstOrFail();
        $this->assertMoneyEquals('5000.00', (string) $invoice->amount_outstanding);
        $this->assertSame('posted', $invoice->status);

        $bill = SupplierBill::withoutGlobalScopes()->where('company_id', $cid)->where('is_opening_balance', true)->firstOrFail();
        $this->assertMoneyEquals('3000.00', (string) $bill->amount_outstanding);

        $chqIn = ChequeReceivable::withoutGlobalScopes()->where('company_id', $cid)->whereNotNull('ob_note_receivable_id')->firstOrFail();
        $this->assertMoneyEquals('2000.00', $chqIn->bookedFunctional());

        $chqOut = ChequePayable::withoutGlobalScopes()->where('company_id', $cid)->whereNotNull('ob_note_payable_id')->firstOrFail();
        $this->assertSame((int) $bank->coa_account_id, (int) $chqOut->ob_source_account_id);

        $stock = ProductStockBalance::withoutGlobalScopes()->where('company_id', $cid)->where('product_id', $product->id)->firstOrFail();
        $this->assertQuantityEquals('10', (string) $stock->quantity_on_hand);
        $this->assertMoneyEquals('500.00', (string) $stock->wac_total_value);

        // The cheques are now in the ledger and can be cleared.
        $this->assertMoneyEquals('2000.00', $this->glBalance($cid, '134001'));
        $this->assertMoneyEquals('-1000.00', $this->glBalance($cid, '222001'));
        app(ChequePayableService::class)->markAsPaid($chqOut, '2026-01-25', $this->actor);
        $this->assertMoneyEquals('0.00', $this->glBalance($cid, '222001'));

        $this->assertLedgerBalanced($cid);

        // Posting twice is refused.
        $this->expectException(\DomainException::class);
        $this->ob->post($cid, $this->actor);
    }

    // ── C8 ────────────────────────────────────────────────────────

    public function test_usd_cheque_collected_after_the_rate_moved_books_realised_fx(): void
    {
        $cid = $this->s->companyId;
        $usd = $this->activateUsd();
        $this->rate($usd, '2026-01-01', '50');
        $this->rate($usd, '2026-04-01', '52');

        // USD 100 cheque in the safe at 50 = EGP 5,000.
        $this->ob->saveNoteReceivable($cid, [
            'partner_id' => $this->s->customers[0]->id, 'check_number' => 'USD-1', 'drawee_bank' => 'Bank',
            'check_date' => '2025-12-20', 'due_date' => '2026-04-05', 'amount' => '5000',
            'currency_id' => $usd, 'amount_foreign' => '100', 'exchange_rate' => '50', 'status' => 'in_safe',
        ], $this->actor);
        $this->ob->saveCoa($cid, ['account_id' => $this->s->coaId('314'), 'entry_side' => 'credit', 'amount' => '5000'], $this->actor);
        $this->ob->post($cid, $this->actor);

        $cheque = ChequeReceivable::withoutGlobalScopes()->where('company_id', $cid)->firstOrFail();
        $bank   = $this->s->createBankAccount();

        $svc = app(ChequeReceivableService::class);
        $svc->sendForCollection($cheque, ['collection_destination_type' => 'bank_account', 'destination_id' => $bank->id, 'sent_for_collection_at' => '2026-04-05'], $this->actor);
        $svc->collect($cheque->fresh(), '2026-04-10', $this->actor);

        $this->assertMoneyEquals('0.00', $this->glBalance($cid, '134001'), 'C8: 134 must clear to exactly zero.');
        $this->assertMoneyEquals('5200.00', $this->glBalanceById($cid, (int) $bank->coa_account_id), 'C8: bank at 100 × 52.');
        $this->assertMoneyEquals('-200.00', $this->glBalance($cid, '431'), 'C8: EGP 200 realised FX gain.');
        $this->assertLedgerBalanced($cid);
    }

    public function test_usd_delivery_books_egp_revenue_and_invoice_clears_accrued_revenue(): void
    {
        $cid     = $this->s->companyId;
        $usd     = $this->activateUsd();
        $product = $this->s->products[0];
        $this->rate($usd, '2026-01-01', '50');
        $this->rate($usd, '2026-03-01', '52');

        $this->s->receiveStock([[$product, 10, 100.00]], '2026-01-10');

        $so = app(SalesOrderService::class)->create($cid, [
            'partner_id' => $this->s->customers[0]->id, 'subject' => 'USD order',
            'currency_id' => $usd, 'exchange_rate' => 50,
            'issuance_date' => '2026-02-01', 'start_date' => '2026-02-01', 'end_date' => '2026-12-31',
            'payment_term_id' => $this->s->paymentTermId,
            'lines' => [[
                'product_id' => $product->id, 'description' => $product->name_en, 'quantity' => 4,
                'uom_id' => $product->primary_uom_id, 'unit_price' => 20, 'revenue_account_id' => $product->revenue_account_id,
            ]],
        ], $this->actor);
        app(SalesOrderService::class)->activate($so, $this->actor);
        $so = $so->fresh('lines');
        $soLine = $so->lines->first();

        // Delivered at 50: USD 80 → EGP 4,000 into 143.
        $dn = app(DeliveryNoteService::class)->create($cid, [
            'sales_order_id' => $so->id, 'partner_id' => $so->partner_id, 'delivery_date' => '2026-02-10',
            'lines' => [['sales_order_line_id' => $soLine->id, 'location_id' => $this->s->masterLocationId, 'quantity' => 4, 'uom_id' => $soLine->uom_id]],
        ], $this->actor);
        app(DeliveryNoteService::class)->post($dn, $this->actor);
        $this->assertMoneyEquals('4000.00', $this->glBalance($cid, '143'), 'C8: accrued revenue in EGP, not USD.');

        // Invoiced at 52: AR 4,160; 143 cleared at the booked 4,000; 160 FX gain.
        $invoice = app(CustomerInvoiceService::class)->create($cid, [
            'sales_order_id' => $so->id, 'partner_id' => $so->partner_id, 'invoice_date' => '2026-03-05',
            'currency_id' => $usd, 'exchange_rate' => 52, 'payment_term_id' => $this->s->paymentTermId,
            'lines' => [[
                'sales_order_line_id' => $soLine->id, 'product_id' => $product->id, 'description' => $product->name_en,
                'quantity' => 4, 'uom_id' => $soLine->uom_id, 'unit_price' => 20, 'vat_rate' => 0,
            ]],
        ], $this->actor);
        app(CustomerInvoiceService::class)->post($invoice, $this->actor, overrideSoLimit: true);

        $this->assertMoneyEquals('0.00', $this->glBalance($cid, '143'), 'C8: 143 must clear to exactly zero.');
        $this->assertMoneyEquals('-160.00', $this->glBalance($cid, '431'), 'C8: realised FX gain of EGP 160.');
        $this->assertLedgerBalanced($cid);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function balanceSheetAsOf(string $date): array
    {
        return app(BalanceSheetService::class)->build($this->s->companyId, [[
            'key' => 'p', 'label' => 'as of', 'start_date' => $date, 'end_date' => $date,
        ]]);
    }

    private function retainedEarnings(array $report): string
    {
        foreach ($report['equity']['sections'] as $section) {
            foreach ($section['rows'] as $row) {
                if (($row['code'] ?? null) === '314') {
                    return (string) $row['periods']['p'];
                }
            }
        }

        return '0.00';
    }

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
}
