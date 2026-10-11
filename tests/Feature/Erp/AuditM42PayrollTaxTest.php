<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM42PayrollTaxTest
//
//  Audit M-42 (test coverage): payroll with salaries tax and social
//  insurance, and the amounts owed to the government afterwards.
//
//  One employee on 10,000 a month, in the General & Administrative
//  category, with these figures entered on the payroll line:
//      salaries tax              1,250
//      social insurance (staff)  1,100
//      social insurance (company) 1,885
//
//  Worked out by hand:
//      expense      541  = 10,000 + 1,885            = 11,885  (debit)
//      net pay      239  = 10,000 − 1,250 − 1,100    =  7,650  (credit)
//      tax payable  226005                           =  1,250  (credit)
//      SI payable   240  = 1,100 + 1,885             =  2,985  (credit)
//      credits total 7,650 + 1,250 + 2,985           = 11,885  = the debit
//
//  Run with:  php artisan test
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\EmployeePayrollProfile;
use App\Models\PayrollRun;
use App\Services\Accounting\EmployeePayrollProfileService;
use App\Services\Accounting\GovernmentRemittanceService;
use App\Services\Accounting\PayrollRunService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditM42PayrollTaxTest extends ErpTestCase
{
    private ErpScenario $s;
    private PayrollRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->s = (new ErpScenario($this->actor))->createCompany();

        $employee = $this->s->createEmployee('Payroll Employee');
        app(EmployeePayrollProfileService::class)->create($this->s->companyId, [
            'partner_id'       => $employee->id,
            'base_salary'      => '10000.00',
            'currency_id'      => $this->s->currencyId,
            'expense_category' => EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN,
            'effective_date'   => '2026-01-01',
        ], $this->actor);

        $this->run = app(PayrollRunService::class)
            ->create($this->s->companyId, 2026, 3, '2026-03-31', $this->actor);
    }

    public function test_posting_splits_the_payroll_into_net_pay_tax_and_social_insurance(): void
    {
        $cid  = $this->s->companyId;
        $line = $this->run->lines->first();

        $updated = app(PayrollRunService::class)
            ->setManualAmounts($line, '1250.00', '1100.00', '1885.00', '0.00', $this->actor);
        $this->assertMoneyEquals('7650.00', (string) $updated->net_pay, '10,000 − tax 1,250 − staff SI 1,100.');

        app(PayrollRunService::class)->post($this->run->fresh(), $this->actor);

        $this->assertMoneyEquals('11885.00', $this->glBalance($cid, '541'), 'Salary expense includes the company share of SI.');
        $this->assertMoneyEquals('-7650.00', $this->glBalance($cid, '239'), 'Net pay owed to the employee.');
        $this->assertMoneyEquals('-1250.00', $this->glBalance($cid, '226005'), 'Salaries tax owed to the authority.');
        $this->assertMoneyEquals('-2985.00', $this->glBalance($cid, '240'), 'Both shares of social insurance are owed.');
        $this->assertLedgerBalanced($cid);
    }

    public function test_the_government_balances_follow_the_posted_run_and_vanish_when_it_is_reopened(): void
    {
        $cid  = $this->s->companyId;
        $svc  = app(PayrollRunService::class);
        $gov  = app(GovernmentRemittanceService::class);

        $svc->setManualAmounts($this->run->lines->first(), '1250.00', '1100.00', '1885.00', '0.00', $this->actor);

        $this->assertSame('0.00', $gov->balances($cid)['tax'], 'A draft run owes the authority nothing yet.');

        $svc->post($this->run->fresh(), $this->actor);
        $this->assertSame(
            ['tax' => '1250.00', 'social_insurance' => '2985.00'],
            $gov->balances($cid)
        );

        $svc->setToDraft($this->run->fresh(), $this->actor);
        $this->assertSame(
            ['tax' => '0.00', 'social_insurance' => '0.00'],
            $gov->balances($cid),
            'Reopening the run takes the amounts owed back off.'
        );
        $this->assertMoneyEquals('0.00', $this->glBalance($cid, '226005'));
        $this->assertMoneyEquals('0.00', $this->glBalance($cid, '240'));
        $this->assertLedgerBalanced($cid);
    }

    public function test_an_advance_deduction_larger_than_the_advance_owed_is_refused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/exceeds/i');

        app(PayrollRunService::class)->setManualAmounts(
            $this->run->lines->first(), '0.00', '0.00', '0.00', '500.00', $this->actor
        );
    }
}
