<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM23OutstandingTest
//
//  The facility outstanding balance is the facility-currency figure
//  withdrawals and settlements maintain. Once those exist, typing a
//  different number on the facility edit is refused. For a facility
//  in the company's own currency the figure matches the ledger.
//
//  Hand-worked: draw 1,000.00, repay 400.00, outstanding 600.00.
//  The liability account is credited 1,000 and debited 400.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilitySettlement;
use App\Services\Accounting\JournalEntryService;
use App\Services\FinancialInstitutions\CleanOverdraftService;
use App\Services\FinancialInstitutions\FacilityPeriodEndService;
use App\Services\FinancialInstitutions\FacilitySettlementEngine;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditM23OutstandingTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->bootstrap(1);
    }

    public function test_edit_cannot_retype_outstanding_after_a_withdrawal_and_the_ledger_matches(): void
    {
        $bank = $this->scenario->createBankAccount('OD Bank');
        $facility = $this->overdraft($bank->id, $bank->bank_id, '0');

        app(CleanOverdraftService::class)->update($this->scenario->companyId, $facility, $this->payload($bank, '0'), $this->actor);
        $facility->refresh();
        $this->assertMoneyEquals('0.00', (string) $facility->outstanding_balance);

        $draw = app(JournalEntryService::class)->post(
            $this->scenario->companyId,
            '2026-01-10',
            [
                ['chart_of_account_id' => $bank->coa_account_id, 'debit' => '1000.00', 'credit' => '0'],
                ['chart_of_account_id' => $this->scenario->coaId('223001'), 'debit' => '0', 'credit' => '1000.00'],
            ],
            'facility_drawdown',
            $facility->id,
            'Drawdown',
            $this->actor,
        );

        app(FacilitySettlementEngine::class)->recordWithdrawal($facility, [
            'amount'           => '1000.00',
            'withdrawal_date'  => '2026-01-10',
            'bank_account_id'  => $bank->id,
            'source_type'      => 'other_payment',
            'source_id'        => $facility->id,
            'journal_entry_id' => $draw->id,
        ], $this->actor);

        try {
            app(CleanOverdraftService::class)->update(
                $this->scenario->companyId,
                $facility->fresh(),
                $this->payload($bank, '50.00'),
                $this->actor,
            );
            $this->fail('A different outstanding balance was accepted after a withdrawal.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('withdrawals and settlements', $e->getMessage());
        }

        $repay = app(JournalEntryService::class)->post(
            $this->scenario->companyId,
            '2026-01-20',
            [
                ['chart_of_account_id' => $this->scenario->coaId('223001'), 'debit' => '400.00', 'credit' => '0'],
                ['chart_of_account_id' => $bank->coa_account_id, 'debit' => '0', 'credit' => '400.00'],
            ],
            'facility_repayment',
            $facility->id,
            'Repayment',
            $this->actor,
        );

        app(FacilitySettlementEngine::class)->recordSettlement($facility->fresh(), [
            'amount'           => '400.00',
            'settlement_date'  => '2026-01-20',
            'bank_account_id'  => $bank->id,
            'source_type'      => FacilitySettlement::SOURCE_OTHER_COLLECTION,
            'source_id'        => $facility->id,
            'journal_entry_id' => $repay->id,
        ], $this->actor);

        $facility->refresh();
        $this->assertMoneyEquals('600.00', (string) $facility->outstanding_balance);

        $rows = app(FacilityPeriodEndService::class)->ledgerReconciliation($this->scenario->companyId, '2026-01-31');
        $this->assertSame([], $rows, 'A functional-currency facility must not report an outstanding difference.');
        $this->assertLedgerBalanced($this->scenario->companyId);
    }

    private function overdraft(int $bankAccountId, int $bankId, string $outstanding): CreditFacility
    {
        return app(CleanOverdraftService::class)->create(
            $this->scenario->companyId,
            $this->payload((object) ['id' => $bankAccountId, 'bank_id' => $bankId], $outstanding),
            $this->actor,
        );
    }

    private function payload(object $bank, string $outstanding): array
    {
        return [
            'bank_id'               => $bank->bank_id,
            'start_date'            => '2026-01-01',
            'review_date'           => '2029-01-01',
            'account_number'        => 'OD-M23-'.random_int(1000, 9999),
            'currency_id'           => $this->scenario->currencyId,
            'coa_account_id'        => $this->scenario->coaId('223001'),
            'bank_account_id'       => $bank->id,
            'facility_limit'        => 500000,
            'outstanding_balance'   => $outstanding,
            'balance_date'          => '2026-01-01',
            'borrowing_rate'        => 18,
            'bank_margin_rate'      => 3,
            'min_interest_rate'     => 1,
            'hdb_rate'              => 0.5,
            'admin_fee_rate'        => 0.25,
            'settled_max_days'      => 90,
            'follows_corridor_rate' => false,
            'purpose'               => 'Working capital',
        ];
    }
}
