<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanOpeningBalanceService
//
//  Sources a Plan's Opening Balance step from the company's REAL
//  books — confirmed design, spec Section 3.3. For an existing
//  company, nothing is retyped: this calls the real
//  BalanceSheetService::build() (the same report Financial Reports >
//  Balance Sheet uses) for a single snapshot period ending the day
//  BEFORE the plan's start date — same "before start_date" convention
//  TrialBalanceService's own opening-balance query already uses, so
//  the plan's Day 1 opening position is "everything true up to and
//  including the day before the plan begins," not double-counting
//  anything that happens to post on the plan's own first day.
//
//  This is exactly why the balancesAsOf() extraction
//  (AccountBalanceCalculator) mattered: BalanceSheetService already
//  depends on it, and this service depends on BalanceSheetService —
//  one balance engine, reused twice, never re-implemented.
//
//  For a genuinely new company with no ledger history yet
//  (plan.opening_balance_source = 'manual' or 'none'), this service
//  is simply not called — the wizard step falls back to the manual
//  entry shape already established elsewhere in Vero CFO's own
//  Opening Balances module (Settings, Stream 2), out of scope here.
//
//  bcmath throughout (Rule 4). Read-only.
//
//  @layer    Service
//  @depends  Plan, BalanceSheetService
// ══════════════════════════════════════════════════════════════════

use App\Models\Plan;
use App\Services\Accounting\BalanceSheetService;
use Carbon\Carbon;

class PlanOpeningBalanceService
{
    public function __construct(private BalanceSheetService $balanceSheetService)
    {
    }

    /**
     * Returns the real Balance Sheet shape (BalanceSheetService::build()'s
     * own return contract — see that class's header) as of the day
     * before the plan's start date. Only meaningful when
     * $plan->opening_balance_source === Plan::OB_SOURCE_AUTO.
     */
    public function build(Plan $plan): array
    {
        $asOfDate = Carbon::parse($plan->start_date)->subDay()->toDateString();

        $period = [
            'key'        => 'opening',
            'label'      => 'Opening Balance',
            'start_date' => $asOfDate,
            'end_date'   => $asOfDate,
        ];

        return $this->balanceSheetService->build($plan->company_id, [$period]);
    }

    /**
     * A compact figure set the calculation engine actually needs to
     * seed a plan's Cash Flow / Balance Sheet projection — pulled out
     * of build()'s full report shape by account CODE (not
     * account_type — confirmed against the real ChartOfAccountSeeder:
     * account_type groups broadly at "Current Assets" for cash, banks,
     * AR, and inventory alike, so it can't distinguish between them;
     * code is the only reliable key), rather than the engine having
     * to walk the whole grouped report itself.
     *
     * Same fresh-lookup-by-code discipline as Building Bible Rule
     * 35.4 (GRNI/556/etc. resolved by code, never a stored/cached id).
     *
     * Returns bcmath strings: ['cash_and_bank', 'accounts_receivable',
     * 'inventory', 'accounts_payable', 'total_equity'].
     */
    public function openingFigures(Plan $plan): array
    {
        $report = $this->build($plan);
        $periodKey = 'opening';

        $figures = [
            'cash_and_bank'        => '0.00',
            'accounts_receivable'  => '0.00',
            'inventory'            => '0.00',
            'accounts_payable'     => '0.00',
            'total_equity'         => (string) ($report['equity']['totals'][$periodKey] ?? '0.00'),
        ];

        // Codes confirmed against ChartOfAccountSeeder: 131 Cash,
        // 132 Banks, 133 Accounts Receivable, 135 Inventory,
        // 221 Accounts Payable. A code match includes that account's
        // own Level 4/5 sub-accounts (e.g. 132001, 133001) via a
        // starts-with check, not an exact match on the Level 3 parent
        // alone — postings only ever land on Level 4/5.
        $assetRows = collect($report['assets']['sections'] ?? [])->flatMap(fn ($s) => $s['rows'] ?? []);
        $liabilityRows = collect($report['liabilities']['sections'] ?? [])->flatMap(fn ($s) => $s['rows'] ?? []);

        foreach ($assetRows as $row) {
            $amount = (string) ($row['periods'][$periodKey] ?? '0.00');

            if (str_starts_with($row['code'], '131') || str_starts_with($row['code'], '132')) {
                $figures['cash_and_bank'] = bcadd($figures['cash_and_bank'], $amount, 2);
            } elseif (str_starts_with($row['code'], '133')) {
                $figures['accounts_receivable'] = bcadd($figures['accounts_receivable'], $amount, 2);
            } elseif (str_starts_with($row['code'], '135')) {
                $figures['inventory'] = bcadd($figures['inventory'], $amount, 2);
            }
        }

        foreach ($liabilityRows as $row) {
            if (str_starts_with($row['code'], '221')) {
                $figures['accounts_payable'] = bcadd(
                    $figures['accounts_payable'],
                    (string) ($row['periods'][$periodKey] ?? '0.00'),
                    2
                );
            }
        }

        return $figures;
    }
}
