<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM42PaymentsWhtTest
//
//  Audit M-42 (test coverage): payments that carry withholding tax.
//
//   - A SUPPLIER bill with 14% VAT and 5% withholding is paid in full
//     from the cash safe. The bill clears, the cash that leaves is the
//     NET amount (goods + VAT − withholding), and the withholding stays
//     on the books as a liability (226002) until it is remitted.
//   - A CUSTOMER collection that settles an invoice partly in cash and
//     partly as Debit WHT (the customer kept 3% for the tax authority)
//     clears the whole invoice, puts only the cash in the safe, and
//     parks the withheld part in 142 Debit WHT Receivable.
//
//  Every expected figure is worked out by hand in the test, never read
//  back from the application.
//
//  Run with:  php artisan test
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Services\Accounting\CustomerPaymentService;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\SupplierPaymentService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditM42PaymentsWhtTest extends ErpTestCase
{
    private ErpScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = (new ErpScenario($this->actor))->bootstrap(1);
    }

    public function test_supplier_bill_with_vat_and_wht_is_paid_net_and_the_wht_stays_a_liability(): void
    {
        $cid = $this->s->companyId;

        // 100 units @ 20.00 = 2,000 goods; VAT 14% = 280; WHT 5% = 100.
        // Net payable = 2,000 + 280 - 100 = 2,180.
        $grn  = $this->s->receiveStock([[$this->s->products[0], 100, 20.00]], '2026-01-10');
        $bill = $this->s->billGrn($grn, '2026-01-12', vatRate: 14, whtRate: 5);
        $this->assertMoneyEquals('2180.00', (string) $bill->amount_outstanding, 'The bill is owed NET of withholding.');

        $safe = $this->s->createCashSafe();
        $this->fundSafe($safe, '5000.00');

        app(SupplierPaymentService::class)->record($cid, [
            'partner_id'      => $bill->partner_id,
            'payment_type'    => 'settlement',
            'payment_date'    => '2026-01-20',
            'money_type'      => 'cash_payment',
            'pay_currency_id' => $this->s->currencyId,
            'amount'          => '2180.00',
            'safe_account_id' => $safe,
            'settlements'     => [[
                'supplier_bill_id'  => $bill->id,
                'settlement_amount' => '2180.00',
            ]],
        ], $this->actor);

        $this->assertMoneyEquals('0.00', (string) $bill->fresh()->amount_outstanding, 'The bill is fully settled.');
        $this->assertMoneyEquals('0.00', $this->glBalance($cid, '221001'), 'Accounts Payable clears to zero.');
        $this->assertMoneyEquals('2820.00', $this->glBalanceById($cid, $safe), '5,000 funded − 2,180 paid.');
        $this->assertMoneyEquals('-100.00', $this->glBalance($cid, '226002'), 'Withholding is still owed to the tax authority.');
        $this->assertLedgerBalanced($cid);
    }

    public function test_customer_collection_with_debit_wht_clears_the_invoice_and_parks_the_wht(): void
    {
        $cid = $this->s->companyId;

        $this->s->receiveStock([[$this->s->products[0], 10, 10.00]], '2026-01-10');
        $so = $this->s->createSalesOrder([[$this->s->products[0], 2, 50.00]], '2026-02-01');
        $this->s->deliver($so, '2026-02-05');
        $invoice = $this->s->invoice($so, '2026-02-05', 0); // 100.00, no VAT

        $safe = $this->s->createCashSafe();

        // The customer pays 97 in cash and withholds 3 (3%).
        app(CustomerPaymentService::class)->record($cid, [
            'partner_id'          => $invoice->partner_id,
            'collection_type'     => 'settlement',
            'payment_date'        => '2026-02-10',
            'money_type'          => 'cash_in_safe',
            'safe_account_id'     => $safe,
            'receive_currency_id' => $this->s->currencyId,
            'invoice_currency_id' => $this->s->currencyId,
            'amount'              => '97.00',
            'settlements'         => [[
                'customer_invoice_id' => $invoice->id,
                'settlement_amount'   => '97.00',
                'wht_amount'          => '3.00',
            ]],
        ], $this->actor);

        $fresh = $invoice->fresh();
        $this->assertMoneyEquals('0.00', (string) $fresh->amount_outstanding, 'Cash + WHT together clear the invoice.');
        $this->assertMoneyEquals('3.00', (string) $fresh->amount_wht_deducted);
        $this->assertMoneyEquals('97.00', $this->glBalanceById($cid, $safe), 'Only the cash reaches the safe.');
        $this->assertMoneyEquals('3.00', $this->glBalance($cid, '142'), 'The withheld part is a Debit WHT receivable.');
        $this->assertLedgerBalanced($cid);
    }

    public function test_a_collection_cannot_clear_more_than_the_invoice_owes(): void
    {
        $this->s->receiveStock([[$this->s->products[0], 10, 10.00]], '2026-01-10');
        $so = $this->s->createSalesOrder([[$this->s->products[0], 2, 50.00]], '2026-02-01');
        $this->s->deliver($so, '2026-02-05');
        $invoice = $this->s->invoice($so, '2026-02-05', 0);

        $this->expectException(\DomainException::class);

        app(CustomerPaymentService::class)->record($this->s->companyId, [
            'partner_id'          => $invoice->partner_id,
            'collection_type'     => 'settlement',
            'payment_date'        => '2026-02-10',
            'money_type'          => 'cash_in_safe',
            'safe_account_id'     => $this->s->createCashSafe(),
            'receive_currency_id' => $this->s->currencyId,
            'invoice_currency_id' => $this->s->currencyId,
            'amount'              => '98.00',
            'settlements'         => [[
                'customer_invoice_id' => $invoice->id,
                'settlement_amount'   => '98.00',
                'wht_amount'          => '3.00', // 101 against a 100 invoice
            ]],
        ], $this->actor);
    }

    // ── Helpers ───────────────────────────────────────────────────

    /** Puts cash in the safe the same way other tests do: a posted journal entry. */
    private function fundSafe(int $safeId, string $amount): void
    {
        app(JournalEntryService::class)->post(
            companyId: $this->s->companyId,
            entryDate: '2026-01-15',
            lines: [
                ['chart_of_account_id' => $safeId, 'debit' => $amount, 'credit' => '0', 'description' => 'funding'],
                ['chart_of_account_id' => $this->s->coaId('423'), 'debit' => '0', 'credit' => $amount, 'description' => 'funding'],
            ],
            sourceType: 'manual_journal_entry',
            sourceId: null,
            narration: 'M42 fund the safe',
            actor: $this->actor,
        );
    }
}
