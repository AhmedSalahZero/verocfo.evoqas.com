<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM29InterestTest
//
//  100,000 at 12% for 30 days is 1,000.00 on a 360-day count and
//  986.30 on a 365-day count. 5,000.00 matches neither, so posting
//  time-deposit interest is refused and no journal is written.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\BankAccountTdEvent;
use App\Models\JournalEntry;
use App\Services\FinancialInstitutions\BankService;
use App\Services\FinancialInstitutions\TimeDepositService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditM29InterestTest extends ErpTestCase
{
    public function test_time_deposit_interest_outside_the_contract_band_is_refused(): void
    {
        $scenario = (new ErpScenario($this->actor))->bootstrap(1);
        $current  = $scenario->createBankAccount('TD Funding');

        $bank = $current->bank;
        $td = app(BankService::class)->createBankAccount($scenario->companyId, $bank, [
            'account_name'     => '90-day deposit',
            'account_number'   => 'TD'.random_int(100000, 999999),
            'account_type'     => 'time_deposit',
            'currency_id'      => $scenario->currencyId,
            'coa_account_id'   => $scenario->coaId('132'),
            'is_active'        => true,
            'td_entry_type'    => 'existing',
            'td_amount'        => '100000.00',
            'td_interest_rate' => '12',
            'td_start_date'    => '2026-01-01',
            'td_maturity_date' => '2026-04-01',
        ], $this->actor);

        $before = JournalEntry::withoutGlobalScopes()->where('company_id', $scenario->companyId)->count();

        try {
            app(TimeDepositService::class)->applyInterest($td, [
                'event_date'              => '2026-01-31',
                'interest_amount'         => '5000.00',
                'credit_bank_account_id'  => $current->id,
            ], $this->actor);
            $this->fail('Interest of 5,000.00 was accepted against a 1,000.00 contract figure.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('more than 1%', $e->getMessage());
        }

        $this->assertSame(0, BankAccountTdEvent::where('bank_account_id', $td->id)->count());
        $this->assertSame($before, JournalEntry::withoutGlobalScopes()->where('company_id', $scenario->companyId)->count());
    }
}
