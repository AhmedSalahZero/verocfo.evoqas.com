<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditL24L26Test
//
//  L-24 a sub-account cannot be added under a parent that already
//       carries a balance (mixed-level balances).
//  L-26 the Trial Balance totals row carries closing Dr / Cr totals
//       that agree with each other.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Services\Accounting\TrialBalanceService;
use App\Services\ChartOfAccountService;
use Illuminate\Support\Facades\DB;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditL24L26Test extends ErpTestCase
{
    private ErpScenario $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = (new ErpScenario($this->actor))->bootstrap(1);
    }

    public function test_sub_account_refused_under_a_parent_with_a_balance(): void
    {
        $parent = $this->s->coaId('139');

        DB::table('ob_coa_balances')->insert([
            'company_id' => $this->s->companyId, 'account_id' => $parent,
            'entry_side' => 'debit', 'amount' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already has postings');

        app(ChartOfAccountService::class)->create($this->s->companyId, [
            'parent_id' => $parent, 'use_auto_code' => true, 'name_en' => 'Should be refused',
        ], $this->actor);
    }

    public function test_trial_balance_totals_include_balanced_closing_columns(): void
    {
        $report = app(TrialBalanceService::class)->build($this->s->companyId, [[
            'key' => 'p1', 'label' => 'All', 'start_date' => '2000-01-01', 'end_date' => '2099-12-31',
        ]]);

        $t = $report['totals']['p1'];
        $this->assertArrayHasKey('ending_debit', $t);
        $this->assertArrayHasKey('ending_credit', $t);
        $this->assertSame(0, bccomp($t['ending_debit'], $t['ending_credit'], 2));
    }
}
