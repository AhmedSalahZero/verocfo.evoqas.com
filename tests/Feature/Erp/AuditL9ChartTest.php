<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditL9ChartTest
//
//  Account 227 (letters of guarantee) is not a posting account.
//  Account 241 (end of service) sits under 21 Long-Term Liabilities.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Accounting\NonPostableAccountException;
use App\Models\ChartOfAccount;
use App\Services\Accounting\JournalEntryService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditL9ChartTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->bootstrap();
    }

    public function test_a_journal_line_on_account_227_is_refused(): void
    {
        $account = ChartOfAccount::withoutGlobalScopes()->where('code', '227')->whereNull('company_id')->first();
        $this->assertNotNull($account);
        $this->assertFalse((bool) $account->is_postable);

        $this->expectException(NonPostableAccountException::class);

        app(JournalEntryService::class)->post($this->scenario->companyId, '2026-01-15', [
            ['chart_of_account_id' => $account->id, 'debit' => '10.00', 'credit' => '0', 'description' => 'LG'],
            ['chart_of_account_id' => $this->scenario->coaId('314'), 'debit' => '0', 'credit' => '10.00', 'description' => 'LG'],
        ], 'manual', null, 'Account 227', $this->actor);
    }

    public function test_account_241_belongs_to_long_term_liabilities(): void
    {
        $account = ChartOfAccount::withoutGlobalScopes()->where('code', '241')->whereNull('company_id')->first();
        $parent = ChartOfAccount::withoutGlobalScopes()->find($account->parent_id);

        $this->assertSame('21', $parent->code);
        $this->assertSame('Long-term Liabilities', $account->account_type);
    }
}
