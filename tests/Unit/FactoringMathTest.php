<?php

namespace Tests\Unit;

// ══════════════════════════════════════════════════════════════════
//  FactoringMathTest
//
//  Audit H-20 — the factoring interest/advance maths now runs in
//  bcmath. These tests pin the figures worked out by hand so the
//  calculation can never silently drift back to floating point, and
//  prove the 1% rule for a typed-in interest amount.
//
//  No database needed — pure arithmetic.
//
//  @layer    Test
//  @depends  FactoringTransaction
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\FactoringTransaction;
use PHPUnit\Framework\TestCase;

class FactoringMathTest extends TestCase
{
    public function test_interest_is_calculated_on_360_day_basis_with_exact_decimals(): void
    {
        // 1,000,000 × 90% = 900,000 advance; 18.5% p.a.; 92 days (10 Jun → 10 Sep)
        // 900,000 × 18.5% / 360 × 92 = 42,550.00 exactly
        $r = FactoringTransaction::calculateAmounts('1000000.00', '90', '18.5', '500', '2026-06-10', '2026-09-10');

        $this->assertSame('900000.00', $r['factoring_amount']);
        $this->assertSame(92, $r['diff_in_days']);
        $this->assertSame('42550.00', $r['factoring_interest_amount']);
        $this->assertSame('856950.00', $r['received_amount']); // 900,000 − 42,550 − 500
    }

    public function test_floats_are_accepted_but_never_lose_cents(): void
    {
        $r = FactoringTransaction::calculateAmounts(333333.33, 70, 17.25, 0, '2026-01-01', '2026-03-02');

        // 333,333.33 × 70% = 233,333.331 → 233,333.33
        $this->assertSame('233333.33', $r['factoring_amount']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $r['factoring_interest_amount']);
    }

    public function test_typed_interest_within_one_percent_is_accepted(): void
    {
        $this->assertSame('42550.00', FactoringTransaction::resolveInterest('42550.00', null));
        $this->assertSame('42550.00', FactoringTransaction::resolveInterest('42550.00', ''));
        $this->assertSame('42600.00', FactoringTransaction::resolveInterest('42550.00', '42600')); // bank's own rounding
    }

    public function test_typed_interest_far_from_the_agreed_rate_is_refused(): void
    {
        $this->expectException(\DomainException::class);
        FactoringTransaction::resolveInterest('42550.00', '2500');
    }
}
