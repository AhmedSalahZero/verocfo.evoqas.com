<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  RunTestingCycle  —  demo:seed-testing-cycle
//
//  An automated ERP tester. It operates two companies for several
//  years the way an accountant would: opening forms, entering data,
//  submitting them, and then checking what the system did with them.
//
//  THIS IS NOT A SEEDER, and the difference is the whole point:
//
//    a seeder                    this cycle
//    ─────────────────────       ──────────────────────────────
//    call a service              submit through Form Request
//                                  validation, then the service
//    catch and continue          verify the result, and record a
//                                  finding if it is wrong
//    exit 0 regardless           exit non-zero when the ERP
//                                  misbehaved
//    random quantities           fixed, hand-checkable figures
//    one company, one year       two archetypes, several connected
//                                  years, each starting from what
//                                  the last one actually left
//
//  WHAT "CONNECTED" MEANS. Nothing here is standalone. A collection
//  settles an invoice a real sale produced; a supplier payment
//  settles a bill a real purchase produced; production consumes raw
//  material a real receipt brought in; year two opens on the balances
//  year one closed with. That is what makes it capable of finding
//  defects that only appear after a history has accumulated.
//
//  THE CYCLE KEEPS ITS OWN BOOKS. InventoryBook maintains a parallel
//  weighted-average inventory using the rule as written down, and
//  every movement is compared against it. Asking the application what
//  the average is and asserting it equals itself would prove nothing.
//
//  IT NEVER WORKS AROUND A DEFECT. If a supplier payment drives a
//  bank balance negative, the cycle records it and carries on with
//  the scenarios that are still safe — it does not quietly reduce the
//  payment to make the run look clean.
//
//  SAFETY. The two companies are identified by name, hard-deleted in
//  full, and rebuilt from nothing on every run, so runs never
//  accumulate. The command refuses to touch any company it did not
//  create itself.
//
//  USAGE
//    php artisan demo:seed-testing-cycle
//    php artisan demo:seed-testing-cycle --years=3
//    php artisan demo:seed-testing-cycle --only=manufacturing
//    php artisan demo:seed-testing-cycle --start-year=2023
//
//  EXIT CODE
//    0  the ERP behaved correctly throughout
//    1  findings were recorded — see the report at the end
//
//  Audit H8 (Sept 2026): refuses to run when APP_ENV=production
//  (RefusesInProduction trait).
//
//  @layer    Console Command
//  @depends  App\Console\TestingCycle\*
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CoverageRegistry;
use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Scenarios\MonthlyOperations;
use App\Console\TestingCycle\Scenarios\CrossModule;
use App\Console\TestingCycle\Scenarios\NegativeTests;
use App\Console\TestingCycle\Scenarios\OpeningBalances;
use App\Console\TestingCycle\Scenarios\InventoryAndMasterData;
use App\Console\TestingCycle\Scenarios\Planning;
use App\Console\TestingCycle\Scenarios\Reporting;
use App\Console\TestingCycle\Scenarios\TreasuryFacilities;
use App\Console\TestingCycle\Scenarios\Setup;
use App\Console\TestingCycle\Scenarios\YearEnd;
use App\Console\TestingCycle\Verify;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RunTestingCycle extends Command
{
    use \App\Console\Concerns\RefusesInProduction; // Audit H8

    protected $signature = 'demo:seed-testing-cycle
        {--years=3 : How many fiscal years of business history to run}
        {--start-year=2023 : The first fiscal year}
        {--only= : Run one archetype only — manufacturing|trading}
        {--keep : Do not delete the test companies first (for debugging a previous run)}';

    protected $description = 'Operates two test companies through a multi-year business history via the real application paths, verifying inventory, costing, accounting, banking and reporting at every step.';

    /**
     * The companies this command owns. Identified by exact name, and
     * nothing else is ever touched — a run must never be able to
     * delete a real company because someone mistyped an id.
     */
    private const COMPANIES = [
        Setup::ARCHETYPE_MANUFACTURING => 'Cycle Test Co. A (Manufacturing)',
        Setup::ARCHETYPE_TRADING       => 'Cycle Test Co. B (Trading)',
    ];

    /**
     * Also deleted on every run, but not an archetype — it exists
     * only to be migrated into, and never trades.
     */
    private const EXTRA_COMPANIES = [
        OpeningBalances::COMPANY_NAME,
    ];

    private CycleLog $log;
    private FormGate $gate;
    private User $actor;

    public function handle(): int
    {
        // Audit H8 — never on the live server
        if ($this->refuseInProduction()) {
            return 1;
        }

        $years     = max(1, (int) $this->option('years'));
        $startYear = (int) $this->option('start-year');
        $only      = $this->option('only');

        $this->log  = new CycleLog($this);
        $this->gate = new FormGate($this->log);
        $this->actor = $this->resolveActor();

        $archetypes = self::COMPANIES;
        if ($only) {
            if (! isset($archetypes[$only])) {
                $this->error("Unknown archetype [{$only}]. Use manufacturing or trading.");

                return self::FAILURE;
            }
            $archetypes = [$only => $archetypes[$only]];
        }

        $this->printBanner($years, $startYear, $archetypes);

        if (! $this->option('keep')) {
            $this->cleanSlate(array_merge(array_values($archetypes), self::EXTRA_COMPANIES));
        }

        $startedAt = microtime(true);

        foreach ($archetypes as $archetype => $name) {
            $this->runCompany($archetype, $name, $startYear, $years);
        }

        // How a company that already exists gets in. Its own company,
        // because opening balances are a one-time migration step and
        // cannot be run against books that have already been traded.
        $this->attempt(
            'the opening balance migration',
            fn () => (new OpeningBalances($this->log, $this->gate))->run($this->actor, $startYear)
        );

        $this->report($startedAt, $years, $startYear);

        return $this->log->hasFindings() ? self::FAILURE : self::SUCCESS;
    }

    // ══════════════════════════════════════════════════════════════
    //  Clean slate
    // ══════════════════════════════════════════════════════════════

    /**
     * Hard-deletes the cycle's own companies and then PROVES they are
     * gone. The proof matters: a partial delete would leave the next
     * run building on stale balances and quietly invalidate every
     * number it reports.
     */
    private function cleanSlate(array $archetypes): void
    {
        $this->newLine();
        $this->line('<options=bold>Clean slate</>');

        foreach ($archetypes as $name) {
            $companies = Company::withoutGlobalScopes()->where('name_en', $name)->get();

            foreach ($companies as $company) {
                $id = $company->id;
                app(CompanyService::class)->destroy($company, $this->actor);
                $this->line("  Deleted previous <fg=yellow>{$name}</> (id {$id}).");

                $leftovers = $this->orphanedRowsFor($id);

                if ($leftovers !== []) {
                    $this->log->enterCompany($name);
                    $this->log->enterPeriod('clean slate');
                    $this->log->finding(
                        prefix: 'CLN',
                        severity: Finding::SEVERITY_HIGH,
                        confidence: Finding::CONFIDENCE_CONFIRMED,
                        title: 'Deleting a company left rows behind',
                        operation: 'Hard-delete test company',
                        expected: 'no row anywhere with this company_id',
                        actual: count($leftovers).' table(s) still hold rows',
                        evidence: implode("\n", array_map(fn ($t, $n) => "{$t}: {$n} row(s)", array_keys($leftovers), $leftovers)),
                        rootCause: 'The next run would build on stale data.',
                    );
                }
            }
        }

        $this->line('  <fg=green>Clean.</>');
    }

    /** @return array<string, int> table => leftover row count */
    private function orphanedRowsFor(int $companyId): array
    {
        $tables = DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('column_name', 'company_id')
            ->select(DB::raw('table_name as tbl'))
            ->pluck('tbl')
            ->reject(fn ($t) => $t === 'companies');

        $leftovers = [];

        foreach ($tables as $table) {
            $count = DB::table($table)->where('company_id', $companyId)->count();
            if ($count > 0) {
                $leftovers[$table] = $count;
            }
        }

        return $leftovers;
    }

    // ══════════════════════════════════════════════════════════════
    //  One company, several years
    // ══════════════════════════════════════════════════════════════

    private function runCompany(string $archetype, string $name, int $startYear, int $years): void
    {
        $this->newLine();
        $this->line('<options=bold;fg=cyan>'.str_repeat('═', 66).'</>');
        $this->line("<options=bold;fg=cyan>  {$name}</>");
        $this->line('<options=bold;fg=cyan>'.str_repeat('═', 66).'</>');

        $setup = new Setup($this->log, $this->gate);
        $ctx   = $setup->run($this->actor, $name, $archetype, $startYear);

        if (! $ctx) {
            $this->error('  Company could not be created — skipping this archetype.');

            return;
        }

        $operations = new MonthlyOperations($this->log, $this->gate);
        $yearEnd    = new YearEnd($this->log, $this->gate);
        $negative   = new NegativeTests($this->log, $this->gate);

        // The order below models how a company actually works, and it
        // is NOT "trade, close, trade, close". A business opens January
        // and keeps trading; the previous year is formally closed some
        // weeks later, once everything has been reconciled. So the new
        // year is opened FIRST and the old one closed after.
        //
        // That ordering matters for more than realism: it is the only
        // way the cycle passes through the window where a year has
        // ENDED but is not yet CLOSED — the state audit finding ACC-001
        // concerns. Closing each year before opening the next would
        // step over that window entirely and report a clean run.
        for ($yearIndex = 0; $yearIndex < $years; $yearIndex++) {
            $year = $startYear + $yearIndex;

            $this->newLine();
            $this->line("<options=bold>  ── Fiscal year {$year} ".str_repeat('─', 40).'</>');

            if ($yearIndex > 0) {
                if (! $yearEnd->openNextYear($ctx, $year)) {
                    $this->log->note("Could not open fiscal year {$year} — stopping this company here.");
                    break;
                }

                // Last year has ended but has NOT been closed yet.
                // This is the window ACC-001 lives in, and a real
                // finance team sits in it every January — measure it
                // before closing anything.
                $yearEnd->measureOpenPriorYears($ctx, $year);

                // Now close it, and check the books on the far side.
                $yearEnd->close($ctx, $year - 1, $yearIndex - 1, $years);
            }

            $this->attempt("the {$year} trading year", fn () => $operations->runYear($ctx, $archetype, $year, $yearIndex));

            // The bank facilities a company arranges once, in its
            // first year — a loan, a deposit, the secured overdrafts.
            // After the trading year, not before: reverse factoring
            // needs a real unpaid supplier bill and the contract
            // overdraft needs a real sales order to pledge.
            if ($yearIndex === 0) {
                $this->attempt('the treasury facilities',
                    fn () => (new TreasuryFacilities($this->log, $this->gate))->run($ctx, $year));
                $this->attempt('inventory and master data',
                    fn () => (new InventoryAndMasterData($this->log, $this->gate))->run($ctx, $archetype, $year));
            }

            // Negative and edge-case testing runs inside a real year,
            // against a company that genuinely has stock, money and
            // history — which is the only way most of these rules can
            // be reached at all.
            if ($yearIndex === 0) {
                $this->attempt('the negative tests', fn () => $negative->run($ctx, $archetype, $year));
            }
        }

        // The final year has no successor to open, so it is closed on
        // its own at the end.
        $yearEnd->close($ctx, $startYear + $years - 1, $years - 1, $years);

        // Next year's plan, built on the books the cycle just wrote —
        // the one place the accounting and planning engines have to
        // agree with each other.
        $this->attempt('the planning module',
            fn () => (new Planning($this->log, $this->gate))->run($ctx, $startYear + $years - 1));

        // Everything the company READS, once its history is complete
        // — reports are only worth testing against a full year.
        $this->attempt('the reporting layer',
            fn () => (new Reporting($this->log))->run($ctx, $startYear, $startYear + $years - 1));

        $this->finalCompanyChecks($ctx);
    }

    /**
     * Runs one scenario and records an unhandled crash as a finding
     * rather than ending the run.
     *
     * A scenario that throws all the way out has still found
     * something — usually the worst kind of something, since it got
     * past every guard the scenario itself has. Losing every LATER
     * scenario as collateral damage would hide more than it reveals,
     * and the exit code still reports failure because the crash is
     * recorded as a finding like any other.
     */
    private function attempt(string $what, callable $scenario): void
    {
        try {
            $scenario();
        } catch (\Throwable $e) {
            $this->log->finding(
                prefix: 'CYC',
                severity: Finding::SEVERITY_HIGH,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: "The application threw an unhandled error during {$what}",
                operation: $what,
                expected: 'the operations in this area to complete or refuse cleanly',
                actual: get_class($e).': '.$e->getMessage(),
                source: basename($e->getFile()).':'.$e->getLine(),
                rootCause: 'Nothing in the application caught this, so a user performing the same steps would '
                    .'see a server error page.',
            );
        }
    }

    /**
     * The whole-company invariants, run once at the very end when the
     * history is at its longest and most interconnected.
     */
    private function finalCompanyChecks(CycleContext $ctx): void
    {
        $this->log->enterPeriod('final verification');
        $verify = new Verify($this->log, $ctx->companyId);

        $verify->ledgerBalanced('Final verification');
        $verify->documentCodesAreCompanyScoped('Final verification');
        $verify->noCrossCompanyPostings('Final verification');
        $verify->noNegativeStock('Final verification');
        $verify->inventoryReconcilesToLedger('Final verification');

        // Every other module's register against the ledger it posts
        // into — the checks no single module can make about itself.
        $this->attempt('the cross-module reconciliation',
            fn () => (new CrossModule($this->log))->run($ctx));

        $this->log->note('Final whole-company verification complete.');
    }

    // ══════════════════════════════════════════════════════════════
    //  Reporting
    // ══════════════════════════════════════════════════════════════

    private function printBanner(int $years, int $startYear, array $archetypes): void
    {
        $this->newLine();
        $this->line('<options=bold>Vero CFO — automated ERP business cycle</>');
        $this->line('  Years      : '.$years.'  ('.$startYear.'–'.($startYear + $years - 1).')');
        $this->line('  Companies  : '.implode(', ', $archetypes));
        $this->line('  Database   : '.DB::getDatabaseName());
    }

    private function report(float $startedAt, int $years, int $startYear): void
    {
        $elapsed = round(microtime(true) - $startedAt);

        $this->newLine(2);
        $this->line('<options=bold>'.str_repeat('═', 66).'</>');
        $this->line('<options=bold>  CYCLE RESULT</>');
        $this->line('<options=bold>'.str_repeat('═', 66).'</>');

        $counts = $this->log->severityCounts();
        $total  = array_sum($counts);

        $this->newLine();
        $this->line('  Business operations performed : '.$this->log->operationTotal());
        $this->line('  Verifications passed          : '.$this->log->checksPassedCount());
        $this->line('  Findings                      : '.$total);
        $this->line('  Elapsed                       : '.gmdate('H:i:s', (int) $elapsed));

        $this->newLine();
        $this->line('  <options=bold>What was exercised</>');
        foreach ($this->log->operationCounts() as $label => $count) {
            $this->line(sprintf('    %-34s %6d', $label, $count));
        }

        $this->newLine();
        $this->line('  <options=bold>Findings by severity</>');
        foreach ($counts as $severity => $count) {
            $colour = $count === 0 ? 'green' : ($severity === Finding::SEVERITY_LOW ? 'yellow' : 'red');
            $this->line(sprintf('    <fg=%s>%-34s %6d</>', $colour, $severity, $count));
        }

        $this->printCoverage();

        if ($this->log->hasFindings()) {
            $this->newLine(2);
            $this->line('<options=bold;fg=red>'.str_repeat('═', 66).'</>');
            $this->line('<options=bold;fg=red>  FINDINGS</>');
            $this->line('<options=bold;fg=red>'.str_repeat('═', 66).'</>');

            foreach ($this->sortedFindings() as $finding) {
                $this->newLine();
                $this->line($finding->render());
            }

            $this->newLine();
            $this->line('<fg=yellow>  Nothing above was fixed. Accounting logic was not modified.</>');
        } else {
            $this->newLine();
            $this->line('<options=bold;fg=green>  No findings. The ERP behaved correctly across the whole cycle.</>');
        }

        $this->newLine();
    }

    /**
     * Coverage, measured rather than claimed. A domain counts as
     * covered only if the operations that prove it ran actually
     * appear in the log — a scenario that threw on every attempt
     * produces findings but no operations, and shows up here as a
     * gap, which is exactly what it is.
     */
    private function printCoverage(): void
    {
        $registry   = new CoverageRegistry();
        $assessment = $registry->assess($this->log);
        $totals     = $registry->totals($assessment);

        $this->newLine();
        $this->line('  <options=bold>Domain coverage</>');

        foreach ($assessment as $domain => $result) {
            $colour = match ($result['status']) {
                'COVERED'     => 'green',
                'PARTIAL'     => 'yellow',
                default       => 'red',
            };

            $this->line(sprintf('    <fg=%s>%-13s</> %s', $colour, $result['status'], $domain));

            if ($result['missing'] !== [] && $result['status'] !== 'COVERED') {
                $this->line('                  <fg=gray>not exercised: '.implode(', ', $result['missing']).'</>');
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '    %d domains covered, %d partial, %d not covered.',
            $totals['COVERED'], $totals['PARTIAL'], $totals['NOT COVERED']
        ));

        $unclaimed = $registry->unclaimed($this->log);
        if ($unclaimed !== []) {
            $this->line('    <fg=gray>Operations performed but not claimed by any domain: '
                .implode(', ', $unclaimed).'</>');
        }
    }

    /** @return array<int, Finding> */
    private function sortedFindings(): array
    {
        $order = [
            Finding::SEVERITY_CRITICAL => 0,
            Finding::SEVERITY_HIGH     => 1,
            Finding::SEVERITY_MEDIUM   => 2,
            Finding::SEVERITY_LOW      => 3,
        ];

        $findings = $this->log->findings();

        usort($findings, fn ($a, $b) => ($order[$a->severity] ?? 9) <=> ($order[$b->severity] ?? 9));

        return $findings;
    }

    private function resolveActor(): User
    {
        return User::withoutGlobalScopes()->where('role', User::ROLE_SUPER_ADMIN)->first()
            ?? User::withoutGlobalScopes()->firstOrFail();
    }
}
