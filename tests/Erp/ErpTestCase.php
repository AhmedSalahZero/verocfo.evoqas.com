<?php

namespace Tests\Erp;

// ══════════════════════════════════════════════════════════════════
//  ErpTestCase
//
//  Base class for the ERP business-logic regression suite.
//
//  WHY THIS EXISTS (and why it does not use RefreshDatabase):
//  this application has 281 migrations that take roughly ten minutes
//  to build on MySQL. Rebuilding that schema per test class is not
//  viable, and RefreshDatabase would do exactly that. Instead the
//  schema is built ONCE into a dedicated database (vero_cfo_test)
//  and every test runs inside a transaction that is rolled back on
//  teardown — so tests stay isolated from each other without paying
//  the migration cost.
//
//  The system-level seed data (Chart of Accounts, currencies, units
//  of measure, payment terms, sales channels, business sectors) is
//  seeded once into that same database and is deliberately NOT
//  re-created per test: those rows carry company_id = NULL and are
//  shared platform-wide by design (see ChartOfAccountSeeder's own
//  header), exactly as they are in production.
//
//  TENANT SCOPING NOTE: CompanyScope only engages when a user is
//  authenticated AND 'currentCompanyId' is bound (see that class).
//  Service-layer tests here run unauthenticated and pass an explicit
//  $companyId into every service call — the same path the demo-company
//  console commands use, and the same path the controllers ultimately
//  reach. Tests that specifically exercise tenant isolation bind both
//  explicitly, so the scope is genuinely under test rather than
//  accidentally disabled.
//
//  @layer    Test Support
// ══════════════════════════════════════════════════════════════════

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class ErpTestCase extends TestCase
{
    use DatabaseTransactions;

    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardAgainstRunningOnTheRealDatabase();
        $this->assertSystemSeedDataPresent();

        $this->actor = User::withoutGlobalScopes()->firstOrCreate(
            ['email' => 'erp-suite@vero-cfo.test'],
            [
                'name'       => 'ERP Regression Suite',
                'password'   => bcrypt('irrelevant'),
                'role'       => User::ROLE_SUPER_ADMIN,
                'company_id' => null,
                'lang_pref'  => 'en',
                'timezone'   => 'Africa/Cairo',
                'is_active'  => true,
            ]
        );
    }

    /**
     * A hard stop. This suite creates companies and posts real journal
     * entries; pointed at the development database it would pollute
     * it permanently (DatabaseTransactions rolls back, but a mistake
     * in configuration should never get that far). The database name
     * must be explicitly a test database.
     */
    private function guardAgainstRunningOnTheRealDatabase(): void
    {
        $name = DB::connection()->getDatabaseName();

        if (! str_contains($name, 'test')) {
            $this->fail(
                "Refusing to run the ERP suite against database [{$name}] — it is not a test database. ".
                'Run with -c phpunit.erp.xml, which pins DB_DATABASE=vero_cfo_test.'
            );
        }
    }

    /**
     * Fails with an actionable message rather than a confusing
     * cascade of null-account errors if the one-time seed step was
     * skipped.
     */
    private function assertSystemSeedDataPresent(): void
    {
        if (DB::table('chart_of_accounts')->whereNull('company_id')->count() === 0) {
            $this->fail(
                'The test database has no system Chart of Accounts. Run the one-time setup: '.
                'DB_DATABASE=vero_cfo_test php artisan migrate --force && '.
                'DB_DATABASE=vero_cfo_test php artisan db:seed --force'
            );
        }
    }

    // ── Shared assertion helpers ──────────────────────────────────

    /**
     * The single most important invariant in the whole system: every
     * journal entry this company owns balances to the cent, and the
     * company's ledger balances in aggregate.
     */
    protected function assertLedgerBalanced(int $companyId, string $context = ''): void
    {
        $unbalanced = DB::select(
            'select je.id, je.entry_number, je.source_type,
                    sum(l.debit) d, sum(l.credit) c
               from journal_entries je
               join journal_entry_lines l on l.journal_entry_id = je.id
              where je.company_id = ?
              group by je.id
             having abs(sum(l.debit) - sum(l.credit)) > 0.004',
            [$companyId]
        );

        $detail = implode(', ', array_map(
            fn ($r) => "{$r->entry_number}({$r->source_type}) D={$r->d} C={$r->c}",
            $unbalanced
        ));

        $this->assertSame(
            [], $unbalanced,
            trim("{$context} Unbalanced journal entries found: {$detail}")
        );
    }

    /**
     * Signed balance of one account code, debit positive, computed
     * from EVERY journal entry line the company owns.
     *
     * Note there is deliberately no status filter here. A reversal
     * consists of two entries — the original, whose status becomes
     * 'reversed', and its mirror image, which stays 'posted'. Summing
     * both gives the true economic balance (they net to zero);
     * summing neither would too. Summing only one of them does not,
     * which is precisely the defect audit finding ACC-002 records.
     * This helper therefore sums both, so it can be used as the
     * independent yardstick the application's own reports are
     * measured against.
     */
    protected function glBalance(int $companyId, string $accountCode): string
    {
        $row = DB::selectOne(
            'select coalesce(sum(l.debit) - sum(l.credit), 0) bal
               from journal_entry_lines l
               join chart_of_accounts a on a.id = l.chart_of_account_id
              where l.company_id = ? and a.code = ?',
            [$companyId, $accountCode]
        );

        return number_format((float) $row->bal, 2, '.', '');
    }

    /** Signed true-ledger balance of one account by primary key. */
    protected function glBalanceById(int $companyId, int $accountId): string
    {
        $row = DB::selectOne(
            'select coalesce(sum(l.debit) - sum(l.credit), 0) bal
               from journal_entry_lines l
              where l.company_id = ? and l.chart_of_account_id = ?',
            [$companyId, $accountId]
        );

        return number_format((float) $row->bal, 2, '.', '');
    }

    /**
     * The same balance as it looked through the PRE-FIX reporting
     * filter — status = 'posted' only, which every reporting service
     * used to apply and none does any more (audit finding ACC-002,
     * fixed 2026-09).
     *
     * Kept deliberately after the fix, as the counter-example: it
     * still reproduces the wrong number on demand, so a test can
     * show what the reports used to say and prove they no longer say
     * it. Never use it as the expected value of anything.
     */
    protected function preAcc002FilteredBalanceById(int $companyId, int $accountId): string
    {
        $row = DB::selectOne(
            'select coalesce(sum(l.debit) - sum(l.credit), 0) bal
               from journal_entry_lines l
               join journal_entries je on je.id = l.journal_entry_id
              where l.company_id = ? and l.chart_of_account_id = ?
                and je.status = "posted"',
            [$companyId, $accountId]
        );

        return number_format((float) $row->bal, 2, '.', '');
    }

    /** bcmath-exact equality assertion with a readable failure message. */
    protected function assertMoneyEquals(string $expected, string $actual, string $message = ''): void
    {
        $this->assertSame(
            0,
            bccomp($expected, $actual, 2),
            trim("{$message} Expected {$expected}, got {$actual} (difference ".bcsub($expected, $actual, 4).').')
        );
    }

    /**
     * Unit-cost assertion at FOUR decimal places.
     *
     * Deliberately separate from assertMoneyEquals(), which compares
     * at two. Weighted-average unit costs are stored decimal(15,4),
     * and two averages that differ only in the third or fourth
     * decimal are genuinely different costs — comparing them at 2dp
     * silently accepts a broken costing engine. (Found by mutation
     * testing: a mutant that recalculated the average on every issue
     * survived a 2dp assertion and was caught only once this
     * comparison was tightened.)
     */
    protected function assertUnitCostEquals(string $expected, string $actual, string $message = ''): void
    {
        $this->assertSame(
            0,
            bccomp($expected, $actual, 4),
            trim("{$message} Expected unit cost {$expected}, got {$actual} (difference ".bcsub($expected, $actual, 6).').')
        );
    }

    /** bcmath-exact quantity assertion (4dp, matching the schema). */
    protected function assertQuantityEquals(string $expected, string $actual, string $message = ''): void
    {
        $this->assertSame(
            0,
            bccomp($expected, $actual, 4),
            trim("{$message} Expected quantity {$expected}, got {$actual}.")
        );
    }
}
