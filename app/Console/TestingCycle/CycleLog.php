<?php

namespace App\Console\TestingCycle;

// ══════════════════════════════════════════════════════════════════
//  CycleLog
//
//  Everything the cycle did and everything it found. One instance
//  lives for the whole run and is shared by every scenario.
//
//  THREE THINGS ARE COUNTED SEPARATELY, and the distinction matters
//  more than it might look:
//
//    checks   — a verification ran and passed. Evidence of coverage.
//    findings — the application behaved differently from what the
//               business rules say it should. A defect in the
//               product. NEVER worked around.
//    breaks   — the cycle itself could not continue down a path
//               (a scenario threw before it could finish). Recorded
//               as a finding too, because a business operation that
//               a real accountant would perform and this system
//               refuses IS a finding, not a test-harness problem.
//
//  The counts of operations are what makes the final report
//  meaningful: "217 documents posted" says nothing on its own;
//  "217 documents posted, 903 verifications passed, 6 findings"
//  says whether anything was actually checked.
//
//  @layer    Console Support
// ══════════════════════════════════════════════════════════════════

use Illuminate\Console\Command;

class CycleLog
{
    /** @var array<int, Finding> */
    private array $findings = [];

    private int $checksPassed = 0;

    /** @var array<string, int> operation label => count */
    private array $operations = [];

    /** @var array<int, string> */
    private array $timeline = [];

    private string $company = '—';
    private string $period  = '—';

    private int $findingSequence = 0;

    public function __construct(private Command $command, private bool $verbose = true)
    {
    }

    // ── Where we are ──────────────────────────────────────────────

    public function enterCompany(string $company): void
    {
        $this->company = $company;
    }

    public function enterPeriod(string $period): void
    {
        $this->period = $period;
    }

    public function currentCompany(): string
    {
        return $this->company;
    }

    public function currentPeriod(): string
    {
        return $this->period;
    }

    // ── Recording ─────────────────────────────────────────────────

    /** A business document was successfully created through the app. */
    public function operation(string $label, string $detail = ''): void
    {
        $this->operations[$label] = ($this->operations[$label] ?? 0) + 1;

        if ($this->verbose && $detail !== '') {
            $this->command->getOutput()->writeln("      <fg=gray>{$detail}</>");
        }
    }

    /** A verification ran and passed. */
    public function checkPassed(): void
    {
        $this->checksPassed++;
    }

    public function record(Finding $finding): void
    {
        $this->findings[] = $finding;

        $colour = match ($finding->severity) {
            Finding::SEVERITY_CRITICAL => 'red',
            Finding::SEVERITY_HIGH     => 'red',
            Finding::SEVERITY_MEDIUM   => 'yellow',
            default                    => 'yellow',
        };

        $this->command->getOutput()->writeln(
            "      <fg={$colour};options=bold>✗ {$finding->id}</> <fg={$colour}>{$finding->title}</>"
        );

        // The title says WHAT went wrong; without the actual behaviour
        // beside it a run that stops early (or is read from a CI log)
        // gives no way to tell a product defect from a payload the
        // cycle got wrong. The full record still prints at the end.
        if ($finding->actual !== '') {
            $this->command->getOutput()->writeln(
                '        <fg=gray>'.str_replace("\n", ' ', $finding->actual).'</>'
            );
        }
    }

    /**
     * Builds a finding with the cycle's current position already
     * filled in — scenarios should never have to remember which
     * company and month they are in.
     */
    public function finding(
        string $prefix,
        string $severity,
        string $confidence,
        string $title,
        string $operation,
        string $expected,
        string $actual,
        string $difference = '',
        string $evidence = '',
        string $source = '',
        string $rootCause = '',
        array  $context = [],
    ): Finding {
        $this->findingSequence++;

        $finding = new Finding(
            id:         sprintf('%s-%03d', $prefix, $this->findingSequence),
            severity:   $severity,
            confidence: $confidence,
            title:      $title,
            company:    $this->company,
            period:     $this->period,
            operation:  $operation,
            expected:   $expected,
            actual:     $actual,
            difference: $difference,
            evidence:   $evidence,
            source:     $source,
            rootCause:  $rootCause,
            context:    $context,
        );

        $this->record($finding);

        return $finding;
    }

    public function note(string $message): void
    {
        if ($this->verbose) {
            $this->command->getOutput()->writeln("    <fg=cyan>{$message}</>");
        }
        $this->timeline[] = "[{$this->company} / {$this->period}] {$message}";
    }

    // ── Reading back ──────────────────────────────────────────────

    /** @return array<int, Finding> */
    public function findings(): array
    {
        return $this->findings;
    }

    public function checksPassedCount(): int
    {
        return $this->checksPassed;
    }

    /** @return array<string, int> */
    public function operationCounts(): array
    {
        ksort($this->operations);

        return $this->operations;
    }

    public function operationTotal(): int
    {
        return array_sum($this->operations);
    }

    /** @return array<string, int> severity => count */
    public function severityCounts(): array
    {
        $counts = [
            Finding::SEVERITY_CRITICAL => 0,
            Finding::SEVERITY_HIGH     => 0,
            Finding::SEVERITY_MEDIUM   => 0,
            Finding::SEVERITY_LOW      => 0,
        ];

        foreach ($this->findings as $finding) {
            $counts[$finding->severity] = ($counts[$finding->severity] ?? 0) + 1;
        }

        return $counts;
    }

    public function hasFindings(): bool
    {
        return $this->findings !== [];
    }
}
