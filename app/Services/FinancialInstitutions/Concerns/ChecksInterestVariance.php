<?php

namespace App\Services\FinancialInstitutions\Concerns;

// ══════════════════════════════════════════════════════════════════
//  ChecksInterestVariance  (audit M-29)
//
//  Shared "does the typed interest look right?" check for time
//  deposits, medium-term loans and LC settlements. Same band as the
//  overdraft rule: a difference of more than 1% (and more than 1.00)
//  from every day-count candidate (360 and 365) is refused. A figure
//  that matches either day-count is accepted.
//
//  @layer    Service (trait)
//  @depends  bcmath
// ══════════════════════════════════════════════════════════════════

trait ChecksInterestVariance
{
    /** @var string[] warnings collected during the current call */
    public array $interestWarnings = [];

    /**
     * @param string $balance  amount interest accrues on
     * @param string $ratePct  annual rate in percent
     * @param int    $days     number of days
     * @param bool   $upperBoundOnly  only warn when typed is ABOVE expected
     */
    protected function checkInterestVariance(string $typed, string $balance, string $ratePct, int $days, string $label, bool $upperBoundOnly = false): void
    {
        if ($days <= 0 || bccomp($ratePct, '0', 6) <= 0 || bccomp($balance, '0', 2) <= 0) {
            return;
        }
        $candidates = [];
        foreach (['360', '365'] as $basis) {
            $candidates[] = bcdiv(bcmul(bcmul($balance, bcdiv($ratePct, '100', 10), 10), (string) $days, 10), $basis, 2);
        }
        foreach ($candidates as $c) {
            $diff = bcsub($typed, $c, 2);
            if ($upperBoundOnly && bccomp($diff, '0', 2) <= 0) {
                return;
            }
            $abs = ltrim($diff, '-');
            $tol = bccomp(bcmul($c, '0.01', 2), '1.00', 2) > 0 ? bcmul($c, '0.01', 2) : '1.00';
            if (bccomp($abs, $tol, 2) <= 0) {
                return; // matches this day-count
            }
        }

        throw new \DomainException(
            "{$label}: the interest entered ({$typed}) differs from the calculated "
            .implode(' – ', array_unique($candidates)).' by more than 1%.'
        );
    }

    public function takeInterestWarnings(): array
    {
        $w = $this->interestWarnings;
        $this->interestWarnings = [];
        return $w;
    }
}
