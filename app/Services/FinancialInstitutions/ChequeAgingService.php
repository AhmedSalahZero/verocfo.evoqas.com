<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  ChequeAgingService
//
//  Financial Institutions > Dashboard > Cash Forecast — Cheque Aging.
//  Same day-interval bucketing as InvoiceAgingService (see
//  AgingBucketCalculator) — CashVero's own ChequeAgingService reuses
//  the identical bucket contract via the same shared trait, so this
//  mirrors that here too, via the same AgingBucketCalculator.
//
//  Only OUTSTANDING cheques are aged — status = received /
//  sent_for_collection for receivables (not yet collected or
//  rejected), status = issued for payables (not yet paid).
//
//  AUDIT H15 (Sept 2026): cheques used to be put, all of them, under
//  the FIRST currency in the list (alphabetical — e.g. AED). Since
//  Audit C8 each cheque knows its own currency (currencyIdResolved()),
//  so each cheque is now aged under ITS OWN currency, at its face
//  value in that currency. Sums use bcmath (Rule 4).
//
//  @layer    Service
//  @depends  AgingBucketCalculator, ChequeReceivable, ChequePayable
// ══════════════════════════════════════════════════════════════════

use App\Models\ChequePayable;
use App\Models\ChequeReceivable;
use App\Services\FinancialInstitutions\Support\AgingBucketCalculator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChequeAgingService
{
    public function build(int $companyId, string $asOfDate, array $currencies): array
    {
        $codes = DB::table('currencies')->pluck('code', 'id');
        $mainCurrency = app(CashStatusDashboardService::class)->mainCurrency($companyId);

        return [
            'customer' => $this->ageReceivable($companyId, $asOfDate, $currencies, $codes, $mainCurrency),
            'supplier' => $this->agePayable($companyId, $asOfDate, $currencies, $codes, $mainCurrency),
        ];
    }

    private function ageReceivable(int $companyId, string $asOfDate, array $currencies, Collection $codes, string $mainCurrency): array
    {
        $cheques = ChequeReceivable::where('company_id', $companyId)
            ->whereIn('status', [ChequeReceivable::STATUS_RECEIVED, ChequeReceivable::STATUS_SENT_FOR_COLLECTION])
            ->whereNotNull('due_date')
            ->get();

        return $this->bucketAndFormat($cheques, Carbon::parse($asOfDate), $currencies, $codes, $mainCurrency);
    }

    private function agePayable(int $companyId, string $asOfDate, array $currencies, Collection $codes, string $mainCurrency): array
    {
        $cheques = ChequePayable::where('company_id', $companyId)
            ->where('status', ChequePayable::STATUS_ISSUED)
            ->whereNotNull('due_date')
            ->get();

        return $this->bucketAndFormat($cheques, Carbon::parse($asOfDate), $currencies, $codes, $mainCurrency);
    }

    private function bucketAndFormat($cheques, Carbon $asOf, array $currencies, Collection $codes, string $mainCurrency): array
    {
        $totals = array_fill_keys($currencies, []);

        foreach ($cheques as $cheque) {
            $currencyId = $cheque->currencyIdResolved();
            $code = $currencyId ? strtoupper((string) ($codes[$currencyId] ?? '')) : $mainCurrency;
            if (! array_key_exists($code, $totals)) continue;

            $bucket = AgingBucketCalculator::classify($cheque->due_date, $asOf);
            $key = $bucket['interval'] === '0' ? 'current_due' : $bucket['interval'];
            $totals[$code][$bucket['due_type']][$key] = bcadd(
                $totals[$code][$bucket['due_type']][$key] ?? '0',
                (string) $cheque->cheque_amount,
                2
            );
        }

        $out = [];
        foreach ($currencies as $code) {
            $out[$code] = [
                'totals'      => $totals[$code],
                'aging_chart' => AgingBucketCalculator::formatForChart($totals[$code]),
            ];
        }

        return $out;
    }
}
