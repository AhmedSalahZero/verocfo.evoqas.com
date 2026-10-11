<?php

namespace App\Services\FinancialInstitutions\Concerns;

// ══════════════════════════════════════════════════════════════════
//  ComputesFacilityExposure  (audit M-30)
//
//  Facility "available room" must compare like with like. Issuances
//  can be in a different currency from the facility limit, and a cash-
//  covered issuance only uses the bank's money for the UNCOVERED part.
//  This converts each issuance into the facility currency (via the
//  exchange-rate table, at today's rate) and deducts unreleased cash
//  cover.
//
//  @layer    Service (trait)
//  @depends  FxService
// ══════════════════════════════════════════════════════════════════

trait ComputesFacilityExposure
{
    /**
     * @param iterable $issuances  models with currency_id, cash_cover_amount, cash_cover_released_amount
     * @return array{net:string,gross:string,cash_cover:string,by_type:array}
     */
    protected function facilityExposure(int $companyId, $facility, iterable $issuances, string $amountField, string $typeField): array
    {
        $fx = app(\App\Services\Accounting\FxService::class);
        $today = now()->toDateString();
        $facRate = $fx->rateOn($companyId, $facility->currency_id ? (int) $facility->currency_id : null, $today);
        $net = $gross = $cover = '0';
        $byType = [];

        foreach ($issuances as $i) {
            $r = $fx->rateOn($companyId, $i->currency_id ? (int) $i->currency_id : null, $today);
            $toFac = fn (string $amt) => bcdiv(bcmul($amt, $r, 6), $facRate, 2);
            $g = $toFac((string) $i->{$amountField});
            $c = '0';
            if (bccomp((string) ($i->cash_cover_amount ?? 0), '0', 2) > 0) {
                $c = $toFac(bcsub((string) $i->cash_cover_amount, (string) ($i->cash_cover_released_amount ?? 0), 2));
                if (bccomp($c, $g, 2) > 0) $c = $g;
                if (bccomp($c, '0', 2) < 0) $c = '0';
            }
            $n = bcsub($g, $c, 2);
            $gross = bcadd($gross, $g, 2);
            $cover = bcadd($cover, $c, 2);
            $net   = bcadd($net, $n, 2);
            $k = (string) ($i->{$typeField} ?? '');
            $byType[$k] = bcadd($byType[$k] ?? '0', $n, 2);
        }

        return ['net' => $net, 'gross' => $gross, 'cash_cover' => $cover, 'by_type' => $byType];
    }
}
