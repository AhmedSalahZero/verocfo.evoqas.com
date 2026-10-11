<?php

namespace Tests\Unit;

// ══════════════════════════════════════════════════════════════════
//  MoneyTest
//
//  Audit L5. Proves the exact-decimal helper that replaces (float) casts
//  on money. Expected values are worked out by hand. No database needed.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_rounding_is_half_away_from_zero(): void
    {
        $this->assertSame('2.35', Money::round('2.345'));
        $this->assertSame('-2.35', Money::round('-2.345'));
        $this->assertSame('2.34', Money::round('2.3449999'));
        $this->assertSame('1235.00', Money::round('1234.995'));
        $this->assertSame('0.0001', Money::round('0.00005', 4));
    }

    public function test_it_never_returns_negative_zero(): void
    {
        $this->assertSame('0.00', Money::round('-0.004'));
        $this->assertSame('0.00', Money::round('-0'));
    }

    public function test_blank_or_junk_counts_as_zero(): void
    {
        $this->assertSame('0.00', Money::round(null));
        $this->assertSame('0.00', Money::round(''));
        $this->assertSame('0.00', Money::round('abc'));
    }

    public function test_floats_and_ints_are_accepted_without_exponent_trouble(): void
    {
        $this->assertSame('12.50', Money::round(12.5));
        $this->assertSame('7.00', Money::round(7));
        $this->assertSame('100000000000000000000.00', Money::round(1.0E+20));
    }

    public function test_summing_many_cents_is_exact(): void
    {
        // 0.1 added a thousand times is exactly 100.00 — a float drifts.
        $this->assertSame('100.00', Money::sum(array_fill(0, 1000, '0.1')));
        $this->assertSame('0.30', Money::sum(['0.1', '0.2']));
    }

    public function test_ten_cents_plus_twenty_cents_agrees_with_the_javascript_mirror(): void
    {
        // 0.10 + 0.20 is 0.30000000000000004 in binary floating point.
        $this->assertNotSame(0.30, 0.10 + 0.20);
        $this->assertSame('0.30', Money::sum(['0.10', '0.20']));
        $this->assertSame('0.30', Money::round(bcadd('0.10', '0.20', 4)));

        // Mirror of resources/js/Utils/money.js lineSubtotal() then sum():
        // qty × price cut to 2 decimals, then those line totals added.
        $lineSubtotal = fn (string $qty, string $price): string => bcmul($qty, $price, 2);

        $this->assertSame(
            Money::sum(['0.10', '0.20']),
            Money::sum([$lineSubtotal('1', '0.10'), $lineSubtotal('1', '0.20')]),
        );
    }

    public function test_display_adds_thousand_separators(): void
    {
        $this->assertSame('1,234,567.50', Money::display('1234567.5'));
        $this->assertSame('-1,000.00', Money::display('-1000'));
        $this->assertSame('999.00', Money::display('999'));
        $this->assertSame('0.00', Money::display(null));
    }

    public function test_comparisons_are_exact(): void
    {
        $this->assertTrue(Money::isPositive('0.01'));
        $this->assertFalse(Money::isPositive('0.004'));
        $this->assertTrue(Money::isZero('0.001'));
        $this->assertSame(0, Money::compare('10.00', '10'));
        $this->assertSame(-1, Money::compare('9.99', '10'));
    }
}
