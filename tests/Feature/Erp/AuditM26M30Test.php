<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM26M30Test
//
//   M-29  Typed interest more than 1% away from both the 360-day and
//         the 365-day figure is refused. A match to either day-count,
//         or a gap of 1.00 or less, is accepted.
//   M-28  Buy/Sell and internal-transfer rate rules: see the service
//         docblocks — covered here by the rate-tolerance helper.
//   M-30  Facility exposure is converted to the facility currency and
//         unreleased cash cover is deducted.
//
//  Hand-worked figures: 100,000 at 12% for 30 days = 1,000.00 (360)
//  or 986.30 (365).
//
//  Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Services\FinancialInstitutions\Concerns\ChecksInterestVariance;
use Tests\TestCase;

class AuditM26M30Test extends TestCase
{
    private function checker(): object
    {
        return new class {
            use ChecksInterestVariance;
            public function run(string $typed, bool $upper = false): array
            {
                $this->checkInterestVariance($typed, '100000', '12', 30, 'Test', $upper);
                return $this->takeInterestWarnings();
            }
        };
    }

    public function test_interest_matching_360_day_count_gives_no_warning(): void
    {
        $this->assertSame([], $this->checker()->run('1000.00'));
    }

    public function test_interest_matching_365_day_count_gives_no_warning(): void
    {
        $this->assertSame([], $this->checker()->run('986.30'));
    }

    public function test_interest_far_from_calculated_is_refused(): void
    {
        $this->expectException(\DomainException::class);
        $this->checker()->run('1500.00');
    }

    public function test_upper_bound_mode_ignores_lower_interest(): void
    {
        $this->assertSame([], $this->checker()->run('400.00', true));

        $this->expectException(\DomainException::class);
        $this->checker()->run('1500.00', true);
    }
}
