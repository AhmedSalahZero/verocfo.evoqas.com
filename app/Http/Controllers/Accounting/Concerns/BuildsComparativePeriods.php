<?php

namespace App\Http\Controllers\Accounting\Concerns;

// ══════════════════════════════════════════════════════════════════
//  BuildsComparativePeriods
//
//  Shared by every Financial Report controller that offers the three
//  view modes Trial Balance introduced (September 2026, per Mahmoud:
//  "build comparative monthly/quarterly like Trial Balance" for
//  Income Statement / Balance Sheet / Cash Flow too) — extracted from
//  TrialBalanceController::buildPeriods() so all four controllers
//  call the exact same logic instead of four near-identical copies
//  quietly drifting apart over time.
//
//    - single    — one date range (or one as-of date for Balance
//                  Sheet, which ignores start_date — see that
//                  controller), the default
//    - monthly   — one chosen Fiscal Year's real 12 accounting_periods
//    - quarterly — those same periods grouped into 4 quarters
//
//  Periods are always read from the company's real FiscalYear /
//  AccountingPeriod records — never a hardcoded calendar-month grid,
//  since fiscal years don't have to start in January and multiple
//  can be open at once (Building Bible §26).
//
//  @layer    Controller Concern
//  @depends  FiscalYear, AccountingPeriod
// ══════════════════════════════════════════════════════════════════

use App\Models\FiscalYear;
use Illuminate\Http\Request;

trait BuildsComparativePeriods
{
    protected function validatePeriodRequest(Request $request, array $extraRules = []): array
    {
        $validated = $request->validate(array_merge([
            'mode'           => ['nullable', 'in:single,monthly,quarterly'],
            'cost_center_id' => ['nullable', 'integer'],
            'fiscal_year_id' => ['nullable', 'integer', 'required_if:mode,monthly,quarterly'],
        ], $extraRules));

        $validated['mode'] = $validated['mode'] ?? 'single';

        return $validated;
    }

    /**
     * Assembles the ordered period list every report service needs:
     * ['key','label','start_date','end_date'][]. $singlePeriod is
     * the caller's own single-mode period (a date RANGE for Income
     * Statement/Cash Flow, or a single as-of date used as both start
     * and end for Balance Sheet — the caller decides what start_date
     * means for its own report, this trait only assembles the list).
     */
    protected function buildPeriods(int $companyId, array $validated, array $singlePeriod): array
    {
        if ($validated['mode'] === 'single') {
            return [$singlePeriod];
        }

        $fiscalYear = FiscalYear::where('company_id', $companyId)
            ->with(['periods' => fn ($q) => $q->orderBy('period_number')])
            ->find($validated['fiscal_year_id']);

        if (! $fiscalYear || $fiscalYear->periods->isEmpty()) {
            return [];
        }

        $monthlyPeriods = $fiscalYear->periods->map(fn ($p) => [
            'key'        => 'p'.$p->period_number,
            'label'      => $p->name,
            'start_date' => $p->start_date->format('Y-m-d'),
            'end_date'   => $p->end_date->format('Y-m-d'),
        ])->values();

        if ($validated['mode'] === 'monthly') {
            return $monthlyPeriods->all();
        }

        return $monthlyPeriods->chunk(3)->values()->map(function ($chunk, $i) {
            $chunk = $chunk->values();
            return [
                'key'        => 'q'.($i + 1),
                'label'      => 'Q'.($i + 1),
                'start_date' => $chunk->first()['start_date'],
                'end_date'   => $chunk->last()['end_date'],
            ];
        })->all();
    }

    protected function fiscalYearOptions(int $companyId): array
    {
        return FiscalYear::where('company_id', $companyId)
            ->orderByDesc('start_date')
            ->get(['id', 'name', 'start_date', 'end_date', 'status'])
            ->map(fn ($fy) => [
                'id'         => $fy->id,
                'name'       => $fy->name,
                'start_date' => $fy->start_date->format('Y-m-d'),
                'end_date'   => $fy->end_date->format('Y-m-d'),
                'status'     => $fy->status,
            ])
            ->values()
            ->all();
    }
}
