<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  InvoiceAgingService
//
//  Financial Institutions > Dashboard > Cash Forecast — Invoice Aging.
//  VeroCFO equivalent of CashVero's own InvoiceAgingService, ported
//  with the exact same day-interval boundaries (see
//  AgingBucketCalculator) — only open invoices (amount_outstanding
//  > 0) are aged, same as CashVero's own "net_balance > 0" filter
//  ("aging" is only meaningful for money still owed).
//
//  AUDIT H15 (Sept 2026): amount_outstanding is kept in the MAIN
//  currency (EGP) on every invoice and bill, but it was aged under the
//  invoice's own currency — EGP numbers under a USD heading. It is now
//  converted back to the document's own currency at the document's
//  own booked rate (exact: that is the rate it was multiplied by).
//  Posted documents only. Sums use bcmath (Rule 4).
//
//  @layer    Service
//  @depends  AgingBucketCalculator, CustomerInvoice, SupplierBill
// ══════════════════════════════════════════════════════════════════

use App\Models\CustomerInvoice;
use App\Models\SupplierBill;
use App\Services\FinancialInstitutions\Support\AgingBucketCalculator;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class InvoiceAgingService
{
    /**
     * @return array{
     *   customer: array<string, array{totals: array, chart: array, past_due: Collection}>,
     *   supplier: array<string, array{totals: array, chart: array, past_due: array}>,
     * }
     */
    public function build(int $companyId, string $asOfDate, array $currencies): array
    {
        return [
            'customer' => $this->ageModel(CustomerInvoice::class, $companyId, $asOfDate, $currencies),
            'supplier' => $this->ageModel(SupplierBill::class, $companyId, $asOfDate, $currencies),
        ];
    }

    private function ageModel(string $modelClass, int $companyId, string $asOfDate, array $currencies): array
    {
        $asOf = Carbon::parse($asOfDate);

        $rows = $modelClass::where('company_id', $companyId)
            ->where('status', 'posted')
            ->where('amount_outstanding', '>', 0)
            ->whereNotNull('due_date')
            ->with(['currency:id,code', 'partner:id,name_en'])
            ->get();

        $out = array_fill_keys($currencies, ['totals' => [], 'chart' => [], 'past_due' => []]);

        foreach ($currencies as $code) {
            $currencyRows = $rows->filter(fn ($r) => $r->currency?->code === $code);

            $totals = [];
            foreach ($currencyRows as $row) {
                $bucket = AgingBucketCalculator::classify($row->due_date, $asOf);
                $key = $bucket['interval'] === '0' ? 'current_due' : $bucket['interval'];
                $totals[$bucket['due_type']][$key] = bcadd($totals[$bucket['due_type']][$key] ?? '0', self::nativeOutstanding($row), 2);
            }

            $pastDue = $currencyRows->filter(fn ($r) => $r->due_date->lt($asOf))
                ->sortBy('due_date')
                ->map(fn ($r) => [
                    'invoice_number' => $r->invoice_code ?? $r->bill_code,
                    'partner_name'   => $r->partner?->name_en,
                    'invoice_due_date' => $r->due_date->toDateString(),
                    'net_balance'    => self::nativeOutstanding($r),
                ])->values()->all();

            $out[$code] = [
                'totals'   => $totals,
                'chart'    => AgingBucketCalculator::formatForChart($totals),
                'past_due' => $pastDue,
            ];
        }

        return $out;
    }

    /**
     * H15 — what is still owed in the document's OWN currency.
     * amount_outstanding is in the main currency at the document's
     * booked rate, so dividing by that rate recovers it exactly.
     */
    public static function nativeOutstanding($document): string
    {
        $rate = (string) ($document->exchange_rate ?: '1');
        if (bccomp($rate, '0', 6) <= 0) $rate = '1';

        $raw = bcdiv((string) $document->amount_outstanding, $rate, 8);

        return bcadd($raw, str_starts_with($raw, '-') ? '-0.005' : '0.005', 2);
    }
}
