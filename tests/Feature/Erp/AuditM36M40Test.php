<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM36M40Test
//
//   M-36  Payroll run labels tell regular / bonus / final runs apart.
//   M-39  LedgerRewriteGuard refuses once LEDGER_GO_LIVE is on.
//   M-40  The app timezone is Africa/Cairo (a 00:30 Cairo entry keeps
//         its own date).
//
//  Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\PayrollRun;
use App\Support\LedgerRewriteGuard;
use Carbon\Carbon;
use Tests\TestCase;

class AuditM36M40Test extends TestCase
{
    public function test_regular_run_label_is_just_the_month(): void
    {
        $run = new PayrollRun(['period_year' => 2026, 'period_month' => 5, 'run_type' => 'regular', 'sequence' => 1]);
        $this->assertSame('May 2026', $run->periodLabel());
    }

    public function test_bonus_and_final_runs_are_labelled_with_type_and_number(): void
    {
        $bonus = new PayrollRun(['period_year' => 2026, 'period_month' => 5, 'run_type' => 'bonus', 'sequence' => 2]);
        $final = new PayrollRun(['period_year' => 2026, 'period_month' => 5, 'run_type' => 'final_settlement', 'sequence' => 1]);
        $this->assertSame('May 2026 (Bonus #2)', $bonus->periodLabel());
        $this->assertSame('May 2026 (Final settlement #1)', $final->periodLabel());
    }

    public function test_ledger_guard_allows_before_go_live_and_refuses_after(): void
    {
        config(['app.ledger_go_live' => false]);
        LedgerRewriteGuard::assertAllowed('a test fix');
        $this->assertTrue(true);

        config(['app.ledger_go_live' => true]);
        $this->expectException(\RuntimeException::class);
        LedgerRewriteGuard::assertAllowed('a test fix');
    }

    public function test_default_timezone_is_cairo(): void
    {
        $this->assertSame('Africa/Cairo', config('app.timezone'));
        $t = Carbon::parse('2026-10-09 00:30:00', config('app.timezone'));
        $this->assertSame('2026-10-09', $t->toDateString());
    }
}
