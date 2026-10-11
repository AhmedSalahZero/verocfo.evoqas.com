<?php

namespace App\Console\TestingCycle;

// ══════════════════════════════════════════════════════════════════
//  Finding
//
//  One thing the cycle discovered. Deliberately a value object with
//  a lot of fields, because a finding that cannot be investigated is
//  not worth recording: the whole point of running a multi-year
//  business history is that when something breaks in August of year
//  two, whoever reads the report can reconstruct exactly what the
//  company had done up to that moment.
//
//  SEVERITY is about consequence, CONFIDENCE is about evidence, and
//  the two are independent. A CRITICAL/POSSIBLE finding is a real
//  thing — "if this is what it looks like, money is wrong, but I
//  could not prove it from here."
//
//  Nothing in this class fixes anything. The cycle reports.
//
//  @layer    Console Support
// ══════════════════════════════════════════════════════════════════

class Finding
{
    const SEVERITY_CRITICAL = 'CRITICAL';
    const SEVERITY_HIGH     = 'HIGH';
    const SEVERITY_MEDIUM   = 'MEDIUM';
    const SEVERITY_LOW      = 'LOW';

    const CONFIDENCE_CONFIRMED  = 'CONFIRMED';
    const CONFIDENCE_LIKELY     = 'LIKELY';
    const CONFIDENCE_POSSIBLE   = 'POSSIBLE';
    const CONFIDENCE_UNVERIFIED = 'UNVERIFIED';

    public function __construct(
        public string $id,
        public string $severity,
        public string $confidence,
        public string $title,
        public string $company,
        public string $period,
        public string $operation,
        public string $expected,
        public string $actual,
        public string $difference = '',
        public string $evidence = '',
        public string $source = '',
        public string $rootCause = '',
        public array  $context = [],
    ) {
    }

    /**
     * The block that goes in the run report. Written to be pasted
     * straight into an issue tracker without editing.
     */
    public function render(): string
    {
        $lines = [
            str_repeat('─', 66),
            "{$this->id}  [{$this->severity} / {$this->confidence}]",
            $this->title,
            str_repeat('─', 66),
            "  Company    : {$this->company}",
            "  Period     : {$this->period}",
            "  Operation  : {$this->operation}",
        ];

        if ($this->source !== '') {
            $lines[] = "  Source     : {$this->source}";
        }

        $lines[] = '';
        $lines[] = "  Expected   : {$this->expected}";
        $lines[] = "  Actual     : {$this->actual}";

        if ($this->difference !== '') {
            $lines[] = "  Difference : {$this->difference}";
        }

        if ($this->context !== []) {
            $lines[] = '';
            $lines[] = '  State at the moment of failure:';
            foreach ($this->context as $key => $value) {
                $lines[] = sprintf('    %-26s %s', $key, is_scalar($value) ? $value : json_encode($value));
            }
        }

        if ($this->evidence !== '') {
            $lines[] = '';
            $lines[] = '  Evidence:';
            foreach (explode("\n", $this->evidence) as $line) {
                $lines[] = '    '.$line;
            }
        }

        if ($this->rootCause !== '') {
            $lines[] = '';
            $lines[] = "  Possible root cause: {$this->rootCause}";
        }

        return implode("\n", $lines);
    }

    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'severity'   => $this->severity,
            'confidence' => $this->confidence,
            'title'      => $this->title,
            'company'    => $this->company,
            'period'     => $this->period,
            'operation'  => $this->operation,
            'expected'   => $this->expected,
            'actual'     => $this->actual,
            'difference' => $this->difference,
            'evidence'   => $this->evidence,
            'source'     => $this->source,
            'root_cause' => $this->rootCause,
            'context'    => $this->context,
        ];
    }
}
