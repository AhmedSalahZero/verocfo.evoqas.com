<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM31M35Test
//
//   M-31  A batch of post-dated cheques: the first cheque takes the
//         remainder, EGP value is shared in proportion, nothing is
//         lost to rounding.
//   M-35  Leave days: inclusive calendar count for ordinary types.
//
//  Worked figures: total 10,000.00 booked at 31,000.00 EGP, extras of
//  2,500.00 and 2,500.00 → first cheque 5,000.00 (15,500.00 EGP);
//  each extra is 2,500 / 10,000 × 31,000 = 7,750.00 EGP; the last
//  cheque takes the remainder, so the three add to 31,000.00.
//
//  Run with:  php artisan test -c phpunit.erp.xml
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Services\Accounting\Concerns\SplitsChequeBatch;
use App\Services\Accounting\LeaveRequestService;
use Tests\TestCase;

class AuditM31M35Test extends TestCase
{
    private function splitter(): object
    {
        return new class {
            use SplitsChequeBatch;
            public function run(array $c, string $t, string $f): array
            {
                return $this->splitChequeBatch($c, $t, $f);
            }
        };
    }

    public function test_batch_first_cheque_takes_the_remainder_and_totals_match(): void
    {
        $rows = $this->splitter()->run([
            'cheque_number' => 'A1', 'due_date' => '2026-11-01', 'drawee_bank_id' => 1,
            'extra' => [
                ['cheque_number' => 'A2', 'due_date' => '2026-12-01', 'amount' => '2500'],
                ['cheque_number' => 'A3', 'due_date' => '2027-01-01', 'amount' => '2500'],
            ],
        ], '10000.00', '31000.00');

        $this->assertCount(3, $rows);
        $this->assertSame('5000.00', $rows[0]['amount']);
        $this->assertSame('31000.00', bcadd(bcadd($rows[0]['amount_functional'], $rows[1]['amount_functional'], 2), $rows[2]['amount_functional'], 2));
        $this->assertSame('10000.00', bcadd(bcadd($rows[0]['amount'], $rows[1]['amount'], 2), $rows[2]['amount'], 2));
        $this->assertSame('7750.00', $rows[1]['amount_functional']); // 2,500 / 10,000 × 31,000
    }

    public function test_single_cheque_is_unchanged(): void
    {
        $rows = $this->splitter()->run(['cheque_number' => 'B1', 'due_date' => '2026-11-01', 'drawee_bank_id' => 1], '800.00', '800.00');
        $this->assertCount(1, $rows);
        $this->assertSame('800.00', $rows[0]['amount']);
        $this->assertSame('800.00', $rows[0]['amount_functional']);
    }

    public function test_extras_that_eat_the_whole_amount_are_refused(): void
    {
        $this->expectException(\DomainException::class);
        $this->splitter()->run([
            'cheque_number' => 'C1', 'due_date' => '2026-11-01', 'drawee_bank_id' => 1,
            'extra' => [['cheque_number' => 'C2', 'due_date' => '2026-12-01', 'amount' => '1000']],
        ], '1000.00', '1000.00');
    }

    public function test_calendar_day_count_is_inclusive(): void
    {
        $this->assertSame('3.00', (new LeaveRequestService())->calculateDays('2026-03-05', '2026-03-07'));
    }
}
