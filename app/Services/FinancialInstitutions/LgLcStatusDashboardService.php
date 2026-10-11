<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LgLcStatusDashboardService
//
//  Financial Institutions > Dashboard > LG & LC Status — the VeroCFO
//  equivalent of CashVero's LetterOfGuaranteeStatement /
//  LetterOfCreditStatement getDashboard*() helpers. Same structure
//  Mahmoud asked to match: for each of LG and LC — Limit/Outstanding/
//  Room/Cash Cover KPIs, "Outstanding per Type" donut data,
//  "Outstanding per Financial Institution" donut data, and a
//  per-bank/type/status detail table, plus a "Limit Details by Bank"
//  drill-down.
//
//  Ported adaptation from CashVero (documented, not silently
//  dropped): CashVero's LG/LC facility is a single-instrument
//  contract with its own Limit. VeroCFO's design is "one Agreement
//  (credit_facilities, facility_type = letter_guarantee / import_lc)
//  → many Issuances" (see migration 000065's header) — so here:
//    - Limit  = SUM(credit_facilities.facility_limit) for that bank's
//      Agreement rows of the relevant type (an LG/LC issued as 100%
//      Cash Cover has no Agreement/Limit behind it at all — it never
//      contributes to Limit, only to Outstanding/Cash Cover, exactly
//      like CashVero's own cash-cover-backed instruments).
//    - Outstanding / Cash Cover = SUM(current_amount / cash_cover_amount)
//      across RUNNING issuances (lg_issuances.status = 'running',
//      letter_of_credit_issuances.status = 'running') for that
//      bank+currency — issuance-level real numbers, not a shared
//      facility ledger balance, matching CashVero's own LG table
//      comment ("Outstanding is the LGs' own combined face amount").
//    - Room = Limit − Outstanding.
//
//  @layer    Service
//  @depends  CreditFacility, LgIssuance, LetterOfCreditIssuance
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\LetterOfCreditIssuance;
use App\Models\FinancialInstitutions\LgIssuance;
use Illuminate\Support\Facades\DB;

class LgLcStatusDashboardService
{
    public function currencies(int $companyId): array
    {
        $rows = DB::table('company_active_currencies')
            ->join('currencies', 'currencies.id', '=', 'company_active_currencies.currency_id')
            ->where('company_active_currencies.company_id', $companyId)
            ->orderBy('currencies.code')
            ->pluck('currencies.code')
            ->all();

        return $rows ?: ['EGP'];
    }

    public function build(int $companyId, array $currencies): array
    {
        return [
            'selectedCurrencies' => $currencies,
            'lg' => $this->section($companyId, $currencies, 'lg'),
            'lc' => $this->section($companyId, $currencies, 'lc'),
        ];
    }

    private function section(int $companyId, array $currencies, string $key): array
    {
        $limits = $this->limitsByBank($companyId, $currencies, $key);
        $issuanceAgg = $this->issuanceAggregates($companyId, $currencies, $key);

        $report = array_fill_keys($currencies, ['limit' => '0.00', 'outstanding_balance' => '0.00', 'room' => '0.00', 'cash_cover' => '0.00', 'limitDetailsByBank' => []]);
        $typeChart = array_fill_keys($currencies, []);
        $institutionChart = array_fill_keys($currencies, []);
        $canShow = array_fill_keys($currencies, false);
        $table = array_fill_keys($currencies, []);

        foreach ($currencies as $c) {
            $limitRows = $limits[$c] ?? [];
            $totalLimit = array_sum(array_column($limitRows, 'limit'));

            $outstandingRows = $issuanceAgg[$c] ?? [];
            $totalOutstanding = array_sum(array_column($outstandingRows, 'outstanding'));
            $totalCashCover   = array_sum(array_column($outstandingRows, 'cash_cover'));

            if ($totalLimit == 0 && $totalOutstanding == 0) continue;
            $canShow[$c] = true;

            $report[$c] = [
                'limit'               => number_format($totalLimit, 2, '.', ''),
                'outstanding_balance' => number_format($totalOutstanding, 2, '.', ''),
                'room'                => number_format($totalLimit - $totalOutstanding, 2, '.', ''),
                'cash_cover'          => number_format($totalCashCover, 2, '.', ''),
                'limitDetailsByBank'  => $this->mergeLimitDetails($limitRows, $outstandingRows),
            ];

            // Outstanding per Type — grouped by lg_type/lc_type
            $byType = [];
            foreach ($outstandingRows as $row) {
                $byType[$row['type']] = ($byType[$row['type']] ?? 0) + $row['outstanding'];
            }
            $typeChart[$c] = collect($byType)->map(fn ($v, $k) => ['category' => $k, 'value' => $v])->values()->all();

            // Outstanding per Financial Institution
            $byBank = [];
            foreach ($outstandingRows as $row) {
                $byBank[$row['bank_name']] = ($byBank[$row['bank_name']] ?? 0) + $row['outstanding'];
            }
            $institutionChart[$c] = collect($byBank)->map(fn ($v, $k) => ['category' => $k, 'value' => $v])->values()->all();

            $table[$c] = $outstandingRows;
        }

        return [
            'reports'    => $report,
            'charts'     => ['type' => $typeChart, 'institution' => $institutionChart],
            'canShow'    => $canShow,
            'tableRows'  => $table,
        ];
    }

    /** Agreement-level limits, grouped by bank, for LG or LC. */
    private function limitsByBank(int $companyId, array $currencies, string $key): array
    {
        $facilityType = $key === 'lg' ? 'letter_guarantee' : 'import_lc';

        $facilities = CreditFacility::where('company_id', $companyId)
            ->where('facility_type', $facilityType)
            ->where('status', 'active')
            ->with(['bank:id,name_en', 'currency:id,code'])
            ->get();

        $out = array_fill_keys($currencies, []);
        foreach ($facilities as $f) {
            $code = $f->currency?->code;
            if (! $code || ! in_array($code, $currencies, true)) continue;

            $out[$code][] = [
                'financial_institution_name' => $f->bank?->name_en,
                'bank_id' => $f->bank_id,
                'limit'   => (float) $f->facility_limit,
            ];
        }
        return $out;
    }

    /** Running-issuance aggregates (Outstanding + Cash Cover) grouped by (bank, type, source, status), for LG or LC. */
    private function issuanceAggregates(int $companyId, array $currencies, string $key): array
    {
        $out = array_fill_keys($currencies, []);

        if ($key === 'lg') {
            $issuances = LgIssuance::where('company_id', $companyId)
                ->whereIn('status', ['running', 'expired'])
                ->with(['bank:id,name_en', 'currency:id,code'])
                ->get();

            $groups = $issuances->groupBy(fn ($lg) => implode('|', [$lg->bank_id, $lg->lg_type, $lg->issuance_type, $lg->status]));

            foreach ($groups as $group) {
                $first = $group->first();
                $code = $first->currency?->code;
                if (! $code || ! in_array($code, $currencies, true)) continue;

                $out[$code][] = [
                    'financial_institution_name' => $first->bank?->name_en,
                    'bank_name' => $first->bank?->name_en,
                    'type'      => $first->lg_type,
                    'source'    => self::sourceLabel($first->issuance_type),
                    'status'    => $first->status === 'expired' ? 'Expired' : 'Running',
                    'count'     => $group->count(),
                    'outstanding' => (float) $group->sum('current_amount'),
                    'cash_cover'  => (float) $group->sum('cash_cover_amount'),
                ];
            }
        } else {
            $issuances = LetterOfCreditIssuance::where('company_id', $companyId)
                ->whereIn('status', ['running', 'paid'])
                ->with(['bank:id,name_en', 'currency:id,code'])
                ->get();

            $groups = $issuances->groupBy(fn ($lc) => implode('|', [$lc->bank_id, $lc->lc_type, $lc->issuance_type, $lc->status]));

            foreach ($groups as $group) {
                $first = $group->first();
                $code = $first->currency?->code;
                if (! $code || ! in_array($code, $currencies, true)) continue;

                $out[$code][] = [
                    'financial_institution_name' => $first->bank?->name_en,
                    'bank_name' => $first->bank?->name_en,
                    'type'      => $first->lc_type,
                    'source'    => self::sourceLabel($first->issuance_type),
                    'status'    => $first->status === 'paid' ? 'Paid' : 'Running',
                    'limit'     => (float) $group->sum('lc_amount'),
                    'outstanding' => (float) $group->sum('lc_amount'),
                    'cash_cover'  => (float) $group->sum('cash_cover_amount'),
                ];
            }
        }

        return $out;
    }

    private function mergeLimitDetails(array $limitRows, array $outstandingRows): array
    {
        $byBank = [];
        foreach ($limitRows as $row) {
            $name = $row['financial_institution_name'] ?? '—';
            $byBank[$name]['financial_institution_name'] = $name;
            $byBank[$name]['limit'] = ($byBank[$name]['limit'] ?? 0) + $row['limit'];
        }
        foreach ($outstandingRows as $row) {
            $name = $row['financial_institution_name'] ?? '—';
            $byBank[$name]['financial_institution_name'] = $name;
            $byBank[$name]['outstanding'] = ($byBank[$name]['outstanding'] ?? 0) + $row['outstanding'];
            $byBank[$name]['cash_cover']  = ($byBank[$name]['cash_cover'] ?? 0) + $row['cash_cover'];
        }
        return collect($byBank)->map(function ($row) {
            $limit = $row['limit'] ?? 0;
            $outstanding = $row['outstanding'] ?? 0;
            return [
                'financial_institution_name' => $row['financial_institution_name'],
                'limit'       => number_format($limit, 2, '.', ''),
                'outstanding' => number_format($outstanding, 2, '.', ''),
                'room'        => number_format($limit - $outstanding, 2, '.', ''),
                'cash_cover'  => number_format($row['cash_cover'] ?? 0, 2, '.', ''),
            ];
        })->values()->all();
    }

    private static function sourceLabel(string $issuanceType): string
    {
        return match ($issuanceType) {
            'facility'     => 'Against Facility',
            'cash_cover'   => '100% Cash Cover',
            'time_deposit' => 'Against Time Deposit',
            default        => ucfirst($issuanceType),
        };
    }
}
