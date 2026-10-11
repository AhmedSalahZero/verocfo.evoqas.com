<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  AdvancesSettlementsService
//
//  Advances & Settlements (2026-09) — the dashboard that was missing
//  even after Custody/Advance-from-Salary/Per-Diem got their own
//  accounts and OtherPayment/OtherCollection got type-aware routing.
//  This service doesn't post anything itself — giving an advance
//  still goes through OtherPayment, settling one manually still goes
//  through OtherCollection, and payroll deduction still goes through
//  PayrollRunService. This is purely a READ layer that combines all
//  three sources into: (1) a per-employee balance per advance type,
//  (2) a company-wide list of employees with an outstanding balance,
//  and (3) one employee's full chronological history.
//
//  Custody (148) has no payroll-deduction leg — only Advance from
//  Salary (147) does, per PayrollRunService::outstandingAdvanceBalance()
//  (which this class deliberately does NOT duplicate for 147; see
//  balancesForPartner() below, which calls it directly so there is
//  exactly one place that formula lives).
//
//  Per Diem is intentionally absent everywhere in this file — it's
//  an outright expense with no balance, by design (see OtherPayment
//  class header).
//
//  @layer    Service
//  @depends  Partner, OtherPayment, OtherCollection, PayrollRunLine,
//            PayrollRun, ChartOfAccount, PayrollRunService
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\OtherCollection;
use App\Models\OtherPayment;
use App\Models\Partner;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AdvancesSettlementsService
{
    public function __construct(
        private PayrollRunService $payrollRunService,
    ) {}

    /**
     * One page of employees who currently have a non-zero balance on
     * EITHER 147 or 148 (or both). Balances are computed per-employee
     * via grouped SQL sums, not one query per row, so this stays fast
     * regardless of employee count.
     */
    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        $advanceAccount = ChartOfAccount::findByCode($companyId, '147');
        $custodyAccount = ChartOfAccount::findByCode($companyId, '148');

        $granted   = $this->sumByPartner(OtherPayment::class, 'debit_account_id', $companyId, $advanceAccount?->id, $custodyAccount?->id);
        $recovered = $this->sumByPartner(OtherCollection::class, 'credit_account_id', $companyId, $advanceAccount?->id, $custodyAccount?->id);
        $payrollRecovered = PayrollRunLine::where('company_id', $companyId)
            ->whereHas('payrollRun', fn ($q) => $q->where('status', PayrollRun::STATUS_POSTED))
            ->selectRaw('partner_id, SUM(advance_deduction_amount) as total')
            ->groupBy('partner_id')
            ->pluck('total', 'partner_id');

        $partnerIds = collect($granted['147'] ?? [])->keys()
            ->merge(collect($granted['148'] ?? [])->keys())
            ->merge(collect($recovered['147'] ?? [])->keys())
            ->merge(collect($recovered['148'] ?? [])->keys())
            ->unique();

        $rows = $partnerIds->map(function ($partnerId) use ($granted, $recovered, $payrollRecovered) {
            $advanceBalance = bcsub(
                bcsub((string) ($granted['147'][$partnerId] ?? '0'), (string) ($payrollRecovered[$partnerId] ?? '0'), 2),
                (string) ($recovered['147'][$partnerId] ?? '0'),
                2
            );
            $custodyBalance = bcsub((string) ($granted['148'][$partnerId] ?? '0'), (string) ($recovered['148'][$partnerId] ?? '0'), 2);

            $advanceBalance = bccomp($advanceBalance, '0', 2) > 0 ? $advanceBalance : '0.00';
            $custodyBalance = bccomp($custodyBalance, '0', 2) > 0 ? $custodyBalance : '0.00';

            return [
                'partner_id'       => $partnerId,
                'advance_balance'  => $advanceBalance,
                'custody_balance'  => $custodyBalance,
            ];
        })
        ->filter(fn ($r) => bccomp($r['advance_balance'], '0', 2) > 0 || bccomp($r['custody_balance'], '0', 2) > 0)
        ->values();

        $partners = Partner::where('company_id', $companyId)
            ->whereIn('id', $rows->pluck('partner_id'))
            ->get(['id', 'code', 'name_en', 'name_ar'])
            ->keyBy('id');

        $rows = $rows->map(fn ($r) => array_merge($r, ['partner' => $partners->get($r['partner_id'])]))
            ->sortByDesc(fn ($r) => bcadd($r['advance_balance'], $r['custody_balance'], 2))
            ->values();

        // Small dataset by design (employee count is capped at 20 per
        // the Enterprise tier) — paginate in-memory rather than adding
        // a second, more complex query path.
        $page = max(1, (int) ($filters['page'] ?? request()->integer('page', 1)));
        $perPage = 20;
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return new LengthAwarePaginator($slice, $rows->count(), $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    /** Both balances for one employee — used on the drill-down page header. */
    public function balancesForPartner(int $companyId, int $partnerId): array
    {
        $custodyAccount = ChartOfAccount::findByCode($companyId, '148');

        $custodyGranted = $custodyAccount ? (string) OtherPayment::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('debit_account_id', $custodyAccount->id)
            ->where('status', OtherPayment::STATUS_POSTED)
            ->sum('amount_functional') : '0';

        $custodyRecovered = $custodyAccount ? (string) OtherCollection::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('credit_account_id', $custodyAccount->id)
            ->where('status', OtherCollection::STATUS_POSTED)
            ->sum('amount_functional') : '0';

        $custodyBalance = bcsub($custodyGranted, $custodyRecovered, 2);

        return [
            // Single source of truth for 147 — see class header.
            'advance_balance' => $this->payrollRunService->outstandingAdvanceBalance($companyId, $partnerId),
            'custody_balance' => bccomp($custodyBalance, '0', 2) > 0 ? $custodyBalance : '0.00',
        ];
    }

    /**
     * One employee's full history, newest first: every advance given
     * (OtherPayment), every manual settlement (OtherCollection), and
     * every payroll deduction (PayrollRunLine.advance_deduction_amount
     * on a posted run) — merged into one timeline so it reads like a
     * real statement of account rather than three separate lists.
     */
    public function history(int $companyId, int $partnerId): Collection
    {
        $advanceAccount = ChartOfAccount::findByCode($companyId, '147');
        $custodyAccount = ChartOfAccount::findByCode($companyId, '148');
        $accountIds = array_filter([$advanceAccount?->id, $custodyAccount?->id]);

        $given = OtherPayment::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->whereIn('debit_account_id', $accountIds)
            ->where('status', OtherPayment::STATUS_POSTED)
            ->get(['id', 'payment_code', 'payment_date', 'amount_functional', 'debit_account_id', 'internal_notes'])
            ->map(fn ($p) => [
                'date'        => (string) $p->payment_date,
                'type'        => $p->debit_account_id === $advanceAccount?->id ? 'advance_salary' : 'custody',
                'kind'        => 'given',
                'amount'      => (string) $p->amount_functional,
                'reference'   => $p->payment_code,
                'note'        => $p->internal_notes,
                'source'      => 'other_payment',
                'source_id'   => $p->id,
            ]);

        $settled = OtherCollection::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->whereIn('credit_account_id', $accountIds)
            ->where('status', OtherCollection::STATUS_POSTED)
            ->get(['id', 'collection_code', 'collection_date', 'amount_functional', 'credit_account_id', 'internal_notes'])
            ->map(fn ($c) => [
                'date'        => (string) $c->collection_date,
                'type'        => $c->credit_account_id === $advanceAccount?->id ? 'advance_salary' : 'custody',
                'kind'        => 'settled',
                'amount'      => (string) $c->amount_functional,
                'reference'   => $c->collection_code,
                'note'        => $c->internal_notes,
                'source'      => 'other_collection',
                'source_id'   => $c->id,
            ]);

        $payrollDeducted = PayrollRunLine::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('advance_deduction_amount', '>', 0)
            ->whereHas('payrollRun', fn ($q) => $q->where('status', PayrollRun::STATUS_POSTED))
            ->with('payrollRun:id,period_year,period_month,run_date')
            ->get(['id', 'payroll_run_id', 'partner_id', 'advance_deduction_amount'])
            ->map(fn ($line) => [
                'date'        => (string) $line->payrollRun->run_date,
                'type'        => 'advance_salary', // payroll deduction only ever touches 147
                'kind'        => 'settled',
                'amount'      => (string) $line->advance_deduction_amount,
                'reference'   => $line->payrollRun->periodLabel(),
                'note'        => null,
                'source'      => 'payroll_run',
                'source_id'   => $line->payroll_run_id,
            ]);

        return $given->concat($settled)->concat($payrollDeducted)
            ->sortByDesc('date')
            ->values();
    }

    // ── Private ────────────────────────────────────────────────────

    /**
     * Grouped SUM(amount_functional) per partner, split into '147'
     * and '148' buckets, for a given model + account-column pair.
     * Returns e.g. ['147' => [partnerId => total, ...], '148' => [...]].
     */
    private function sumByPartner(string $modelClass, string $accountColumn, int $companyId, ?int $advanceAccountId, ?int $custodyAccountId): array
    {
        $result = ['147' => collect(), '148' => collect()];

        if ($advanceAccountId) {
            $result['147'] = $modelClass::where('company_id', $companyId)
                ->where($accountColumn, $advanceAccountId)
                ->where('status', 'posted')
                ->selectRaw('partner_id, SUM(amount_functional) as total')
                ->groupBy('partner_id')
                ->pluck('total', 'partner_id');
        }

        if ($custodyAccountId) {
            $result['148'] = $modelClass::where('company_id', $companyId)
                ->where($accountColumn, $custodyAccountId)
                ->where('status', 'posted')
                ->selectRaw('partner_id, SUM(amount_functional) as total')
                ->groupBy('partner_id')
                ->pluck('total', 'partner_id');
        }

        return $result;
    }
}
