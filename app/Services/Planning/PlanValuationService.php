<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanValuationService
//
//  Step 7 of the calculation engine — Business Plan & Valuation only
//  (Annual Plan never calls this; it has no WACC/perpetual growth
//  rate to compute against). Two independent outputs on the same
//  Valuation page (spec Section 4):
//
//  DCF — bcmath port of CFO Tools' calcKPIs(), same formulas:
//    FCFF(year) = EBIT × (1 − tax rate) + D&A − CapEx paid
//    Terminal Value = FCFF(last year) × (1+g) / (WACC − g), only
//      when WACC > g and FCFF(last) > 0 (same guard CFO Tools uses)
//    NPV = −Total Investment + Σ FCFF(y)/(1+WACC)^y + TV/(1+WACC)^n
//    IRR — Newton-Raphson on the same cash-flow stream (Decision 3:
//      exact same method as CFO Tools, the standard investment-
//      banking approach), starting from WACC as the initial guess.
//
//  ONE CORRECTION vs CFO Tools, flagged not silently copied: CFO
//  Tools' own MOIC formula adds Terminal Value twice — once inside
//  `totalR` (via `+ Math.max(0, tv)`) and again directly
//  (`(totalR + tv) / totalInvestment`). That looks like a genuine
//  arithmetic bug in the reference implementation, not a deliberate
//  choice — MOIC here counts Terminal Value once. Same posture this
//  codebase already takes elsewhere (Building Bible's many
//  "Corrected" notes) — fix, and say so, don't silently replicate.
//
//  ONE BUG OF OUR OWN, FOUND AND FIXED (Sept 2026, while building the
//  Results page): fcffByYear() originally read CapEx from
//  $pl[$m]['capex_paid'], a key that only ever existed on the Cash
//  Flow row ($cf[$m]), never on the P&L row — P&L only carries
//  'depreciation', the non-cash charge; the actual cash spent on
//  fixed assets is a Cash Flow concept. The lookup silently fell back
//  to '0.00' every time via `??`, so CapEx was never actually
//  subtracted from FCFF — overstating NPV/IRR for any plan with
//  fixed assets. dcf() now takes $cf as its own parameter and reads
//  capex_paid from there. Confirmed numerically before and after the
//  fix with a standalone test (500 units of CapEx correctly reduced
//  FCFF by 500 after the fix; the buggy version showed zero effect).
//
//  TOTAL INVESTMENT — deliberately NOT the same definition CFO Tools
//  uses. CFO Tools includes the company's pre-existing opening equity
//  in "Total Investment," which conflates historical sunk capital
//  with the forward-looking decision this NPV is meant to inform.
//  Here, Total Investment = the plan's own computed
//  required_equity_injection (PlanFinancialStatementBuilder) — the
//  actual NEW cash this plan needs, nothing else. FLAGGED AS A
//  DESIGN CHOICE NEEDING CONFIRMATION, not assumed silently correct
//  — an existing company's historical paid-up capital arguably
//  belongs in the picture for some valuation purposes; this can be
//  revisited once real usage clarifies which framing is wanted.
//
//  Multiplier Valuation — confirmed simple design (Decision 6): one
//  basis (revenue/ebitda/net_profit), one user-entered multiplier,
//  applied to the plan's OWN Year 1 result. No comparable
//  transactions, no Net Debt adjustment.
//
//  bcmath throughout (Rule 4). Read-only.
//
//  @layer    Service
//  @depends  Plan
// ══════════════════════════════════════════════════════════════════

use App\Models\Plan;

class PlanValuationService
{
    private const IRR_MAX_ITERATIONS = 200;
    private const IRR_PRECISION = '0.00000001';

    /**
     * $pl = the monthly P&L array from PlanFinancialStatementBuilder.
     * $cf = the monthly Cash Flow array from the same builder — needed
     * here specifically for its 'capex_paid' figure, which lives on
     * the cash flow row, not the P&L row (P&L only carries
     * 'depreciation', the non-cash expense — the actual cash spent on
     * fixed assets is a Cash Flow concept). FCFF math needs both.
     * $requiredEquityInjection = same builder's output.
     *
     * Returns NPV/IRR/Terminal Value/MOIC/Payback/Break-Even, all
     * bcmath strings (percentages already ×100, e.g. '18.42' = 18.42%).
     */
    public function dcf(Plan $plan, array $pl, array $cf, string $requiredEquityInjection): array
    {
        $wacc = bcdiv((string) $plan->wacc_pct, '100', 6);
        $perpGrowth = bcdiv((string) $plan->perpetual_growth_pct, '100', 6);
        $taxRate = bcdiv((string) $plan->corporate_tax_rate, '100', 6);

        $fcffByYear = $this->fcffByYear($plan, $pl, $cf, $taxRate);
        $lastFcff = end($fcffByYear);

        $terminalValue = '0.00';
        if (bccomp($wacc, $perpGrowth, 6) > 0 && bccomp($lastFcff, '0', 2) > 0) {
            $terminalValue = bcdiv(
                bcmul($lastFcff, bcadd('1', $perpGrowth, 6), 2),
                bcsub($wacc, $perpGrowth, 6),
                2
            );
        }

        $totalInvestment = $requiredEquityInjection; // see class header — deliberate divergence from CFO Tools

        $npv = $this->npv($fcffByYear, $terminalValue, $wacc, $totalInvestment);
        $irr = $this->irr($fcffByYear, $terminalValue, $totalInvestment, $wacc);
        $moic = $this->moic($fcffByYear, $terminalValue, $totalInvestment);
        [$paybackMonths] = $this->payback($fcffByYear, $totalInvestment);
        [$breakEvenMonths] = $this->breakEven($pl);

        return [
            'npv' => $npv,
            'irr_pct' => bcmul($irr, '100', 4),
            'terminal_value' => $terminalValue,
            'moic' => $moic,
            'payback_months' => $paybackMonths,
            'break_even_months' => $breakEvenMonths,
            'total_investment' => $totalInvestment,
            'wacc_pct' => (string) $plan->wacc_pct,
            'perpetual_growth_pct' => (string) $plan->perpetual_growth_pct,
            'fcff_by_year' => $fcffByYear,
        ];
    }

    /**
     * Confirmed simple design (Decision 6): Year 1's total for the
     * chosen basis, × the user-entered multiplier. One number.
     */
    public function multiplierValuation(Plan $plan, array $pl): string
    {
        if (! $plan->valuation_basis || $plan->valuation_multiplier === null) {
            return '0.00';
        }

        $year1Basis = $this->year1Total($pl, $plan->valuation_basis);

        return bcmul($year1Basis, (string) $plan->valuation_multiplier, 2);
    }

    // ── Internals ─────────────────────────────────────────────────

    private function fcffByYear(Plan $plan, array $pl, array $cf, string $taxRate): array
    {
        $fcff = [];
        for ($y = 0; $y < $plan->duration_years; $y++) {
            $ebit = '0.00'; $dep = '0.00'; $capex = '0.00';
            for ($m = $y * 12; $m < ($y + 1) * 12; $m++) {
                $ebit = bcadd($ebit, $pl[$m]['ebit'], 2);
                $dep  = bcadd($dep, $pl[$m]['depreciation'], 2);
                $capex = bcadd($capex, $cf[$m]['capex_paid'] ?? '0.00', 2);
            }
            $nopat = bcmul($ebit, bcsub('1', $taxRate, 6), 2);
            $fcff[$y] = bcsub(bcadd($nopat, $dep, 2), $capex, 2);
        }

        return $fcff;
    }

    private function npv(array $fcffByYear, string $tv, string $wacc, string $totalInvestment): string
    {
        $npv = bcmul($totalInvestment, '-1', 2);
        $n = count($fcffByYear);

        foreach ($fcffByYear as $y => $f) {
            $discount = bcpow(bcadd('1', $wacc, 10), (string) ($y + 1), 10);
            $npv = bcadd($npv, bcdiv($f, $discount, 2), 2);
        }

        if (bccomp($tv, '0', 2) > 0) {
            $discount = bcpow(bcadd('1', $wacc, 10), (string) $n, 10);
            $npv = bcadd($npv, bcdiv($tv, $discount, 2), 2);
        }

        return $npv;
    }

    /**
     * Newton-Raphson IRR — same method as CFO Tools (Decision 3), on
     * the stream [-totalInvestment, FCFF(1)...FCFF(n-1), FCFF(n)+TV].
     * Falls back gracefully (returns '0') if it fails to converge —
     * never throws, since a plan with unusual cash flows (e.g. all
     * positive, or all negative) legitimately has no real IRR.
     */
    private function irr(array $fcffByYear, string $tv, string $totalInvestment, string $wacc): string
    {
        $stream = array_values($fcffByYear);
        $last = count($stream) - 1;
        $stream[$last] = bcadd($stream[$last], $tv, 2);
        array_unshift($stream, bcmul($totalInvestment, '-1', 2));

        $r = $wacc;

        for ($i = 0; $i < self::IRR_MAX_ITERATIONS; $i++) {
            $npvAtR = $this->npvAtRate($stream, $r);
            $derivative = $this->npvDerivative($stream, $r);

            if (bccomp($this->abs($derivative), '0.0000000001', 10) < 0) {
                break;
            }

            $newR = bcsub($r, bcdiv($npvAtR, $derivative, 10), 10);

            if (bccomp($this->abs(bcsub($newR, $r, 10)), self::IRR_PRECISION, 10) < 0) {
                $r = $newR;
                break;
            }

            // Clamp to a sane range, same guard CFO Tools uses, so a
            // wild early iteration can't diverge to an absurd value.
            if (bccomp($newR, '-0.99', 6) < 0) {
                $newR = '-0.99';
            }
            if (bccomp($newR, '100', 6) > 0) {
                $newR = '100';
            }

            $r = $newR;
        }

        return $r;
    }

    private function npvAtRate(array $stream, string $rate): string
    {
        $sum = '0.00';
        foreach ($stream as $i => $cashFlow) {
            $discount = bcpow(bcadd('1', $rate, 10), (string) $i, 10);
            $sum = bcadd($sum, bcdiv($cashFlow, $discount, 10), 10);
        }

        return $sum;
    }

    private function npvDerivative(array $stream, string $rate): string
    {
        $sum = '0.00';
        foreach ($stream as $i => $cashFlow) {
            if ($i === 0) {
                continue;
            }
            $discount = bcpow(bcadd('1', $rate, 10), (string) ($i + 1), 10);
            $term = bcdiv(bcmul((string) $i, $cashFlow, 10), $discount, 10);
            $sum = bcsub($sum, $term, 10);
        }

        return $sum;
    }

    private function abs(string $value): string
    {
        return bccomp($value, '0', 10) < 0 ? bcmul($value, '-1', 10) : $value;
    }

    /**
     * Corrected vs CFO Tools — Terminal Value counted once, not twice.
     * See class header.
     */
    private function moic(array $fcffByYear, string $tv, string $totalInvestment): string
    {
        if (bccomp($totalInvestment, '0', 2) <= 0) {
            return '0.00';
        }

        $totalReturns = '0.00';
        foreach ($fcffByYear as $f) {
            if (bccomp($f, '0', 2) > 0) {
                $totalReturns = bcadd($totalReturns, $f, 2);
            }
        }
        if (bccomp($tv, '0', 2) > 0) {
            $totalReturns = bcadd($totalReturns, $tv, 2);
        }

        return bcdiv($totalReturns, $totalInvestment, 2);
    }

    private function payback(array $fcffByYear, string $totalInvestment): array
    {
        $cumulative = bcmul($totalInvestment, '-1', 2);

        foreach ($fcffByYear as $y => $f) {
            $before = $cumulative;
            $cumulative = bcadd($cumulative, $f, 2);

            if (bccomp($cumulative, '0', 2) >= 0) {
                $fraction = bccomp($f, '0', 2) > 0
                    ? bcdiv($this->abs($before), $f, 4)
                    : '0';
                $months = $y * 12 + (int) round(((float) $fraction) * 12);

                return [$months];
            }
        }

        return [null];
    }

    private function breakEven(array $pl): array
    {
        $cumulative = '0.00';

        foreach ($pl as $m => $row) {
            $before = $cumulative;
            $cumulative = bcadd($cumulative, $row['netProfit'], 2);

            if (bccomp($cumulative, '0', 2) >= 0) {
                return [$m];
            }
        }

        return [null];
    }

    private function year1Total(array $pl, string $basis): string
    {
        $total = '0.00';

        for ($m = 0; $m < 12 && isset($pl[$m]); $m++) {
            $value = match ($basis) {
                'revenue'    => $pl[$m]['revenue'],
                'ebitda'     => $pl[$m]['ebitda'],
                'net_profit' => $pl[$m]['netProfit'],
                default      => '0.00',
            };
            $total = bcadd($total, $value, 2);
        }

        return $total;
    }
}
