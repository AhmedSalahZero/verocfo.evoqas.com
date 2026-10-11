<?php

namespace App\Services\FinancialInstitutions\Support;

// ══════════════════════════════════════════════════════════════════
//  AgingBucketCalculator
//
//  Shared day-interval bucketing logic for Invoice Aging and Cheque
//  Aging (Financial Institutions > Dashboard > Cash Forecast).
//  Boundaries copied exactly from CashVero's own
//  getInvoiceDayIntervals() helper, so the buckets you see here are
//  the same buckets CashVero used — not an approximation.
//
//  A due date exactly on the as-of date is 'current_due' (its own
//  bucket, own color on the chart — never lumped into "coming due").
//  Everything else is 'past_due' (due date before as-of) or
//  'coming_due' (due date after as-of), sorted into one of the day
//  intervals below by the absolute day gap.
//
//  @layer    Service (support)
//  @depends  Carbon
// ══════════════════════════════════════════════════════════════════

use Carbon\Carbon;

class AgingBucketCalculator
{
    public const DAY_INTERVALS = ['1-7', '8-15', '16-30', '31-45', '46-60', '61-90', '91-120', '121-150'];
    public const MORE_THAN_150 = 'More Than 150';

    /**
     * @return array{due_type: 'past_due'|'current_due'|'coming_due', interval: string}
     */
    public static function classify(Carbon $dueDate, Carbon $asOfDate): array
    {
        $diffInDays = $dueDate->diffInDays($asOfDate, true); // absolute — direction handled separately

        if ($diffInDays == 0) {
            return ['due_type' => 'current_due', 'interval' => '0'];
        }

        $dueType = $asOfDate->greaterThan($dueDate) ? 'past_due' : 'coming_due';

        foreach (self::DAY_INTERVALS as $interval) {
            [$from, $to] = explode('-', $interval);
            if ($diffInDays >= (int) $from && $diffInDays <= (int) $to) {
                return ['due_type' => $dueType, 'interval' => $interval];
            }
        }

        return ['due_type' => $dueType, 'interval' => self::MORE_THAN_150];
    }

    /**
     * Turns a { due_type => { interval => amount } } totals table into
     * the flat row shape AgingDivergingBarChart expects: one row per
     * bucket, Past Due already negative, Current/Coming Due positive.
     * Same contract as CashVero's own formatAgingBucketsForChart().
     */
    public static function formatForChart(array $totals): array
    {
        $rows = [];

        foreach ($totals as $dueType => $intervals) {
            foreach ($intervals as $interval => $amount) {
                if ($interval === 'current_due') {
                    $rows[] = ['category' => 'Current Due', 'value' => (float) $amount, 'colorVar' => '#1490A8'];
                    continue;
                }
                $label = ($dueType === 'past_due' ? 'Past Due · ' : 'Coming Due · ').$interval.' Days';
                $value = (float) $amount;
                $rows[] = [
                    'category' => $label,
                    'value'    => $dueType === 'past_due' ? -abs($value) : abs($value),
                    'colorVar' => $dueType === 'past_due' ? '#dc2626' : '#16a34a',
                ];
            }
        }

        return $rows;
    }
}
