<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  ContractDashboardService
//
//  Projects & Contracts > Contract Dashboard. VeroCFO equivalent of
//  CashVero's own ContractDashboardService — same structure, same
//  sections, ported field-for-field where VeroCFO's schema has a
//  direct equivalent, documented adaptations where it doesn't.
//
//  MODEL MAPPING (confirmed by reading both schemas directly):
//    CashVero "Contract"        → VeroCFO "Project"
//      contract_code            → contract_code
//      amount (contract value)  → contract_value
//      client                   → partner
//      invoiced_amount          → SUM(CustomerInvoice.subtotal_amount)
//                                  across the project's Sales Orders
//                                  (ex-VAT — comparable with contract_value)
//      billed/collected/withheld/deductions/uncollected
//                                → SUM of the matching CustomerInvoice
//                                  columns (invoice_value / amount_paid /
//                                  amount_wht_deducted / amount_credited+
//                                  amount_debited / amount_outstanding)
//                                  — VAT-inclusive, reconcile among
//                                  themselves, exactly like CashVero's
//                                  own comment on this distinction
//      invoiced_main/collected_main/uncollected_main
//                                → the matching *_functional columns,
//                                  which VeroCFO already computes per
//                                  invoice at its own exchange rate —
//                                  no extra conversion needed, unlike
//                                  CashVero which computes this live
//    Contract::RUNNING / RUNNING_AND_AGAINST / FINISHED
//                                → Project.status: active / on_hold /
//                                  closed / cancelled (VeroCFO has a
//                                  4th bucket CashVero doesn't —
//                                  cancelled — counted separately,
//                                  never silently folded into another
//                                  bucket)
//
//  This dashboard has NO charts in CashVero's own current design —
//  every section (including "Monthly Trend") is a KPI-card-plus-table
//  layout, confirmed by reading the actual Vue file. Matched exactly
//  here — no chart components needed for this page.
//
//  AUDIT H14 (Sept 2026) — three problems fixed:
//
//    1. Draft and cancelled invoices were counted. Only POSTED
//       invoices now feed any figure on this page (a draft is not a
//       sale yet; a cancelled one never happened).
//
//    2. EGP figures were shown under USD headings. On an invoice,
//       invoice_value / subtotal_amount are in the invoice's own
//       currency, but amount_paid, amount_wht_deducted,
//       amount_credited, amount_debited and amount_outstanding are
//       all kept in the MAIN (functional) currency (migration 000118,
//       CustomerPaymentService, CreditNoteService). The old code
//       added them together under the contract's currency. Now every
//       per-currency figure is in the CONTRACT's currency:
//         • invoice in the contract's currency → main-currency figure
//           ÷ the invoice's own booked rate (exact — that is the rate
//           it was multiplied by);
//         • contract in the main currency → the main-currency figure
//           as it stands;
//         • otherwise → main-currency figure ÷ the contract's rate;
//           with no usable contract rate the invoice is left out of
//           the per-currency figures and listed under Data Quality,
//           never guessed.
//       The main-currency cards read the functional columns directly.
//
//    3. Collections were converted twice (amount_paid, already in
//       EGP, was multiplied by the rate again). Removed. Uncollected
//       in the main currency is now the real AR balance
//       (amount_outstanding) instead of billed − collected, which
//       ignored WHT and credit notes.
//
//    Also: debit notes RAISE what a customer owes, so "Deductions" is
//    now credit notes − debit notes (they were added together); Top
//    Customers groups by customer ID, not name (same-name customers
//    no longer merge); all money arithmetic is bcmath (Rule 4).
//
//  @layer    Service
//  @depends  Project, SalesOrder, CustomerInvoice, AgingBucketCalculator
// ══════════════════════════════════════════════════════════════════

use App\Models\CustomerInvoice;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ContractDashboardService
{
    private const DEFAULT_PERIOD_YEARS = 3;
    private const NEAR_EXPIRY_DAYS = 30;

    /** Posted invoices of this company's contracts — loaded once per build(). */
    private ?Collection $postedInvoices = null;

    public function build(int $companyId, ?string $startDate, ?string $endDate): array
    {
        $today = $endDate ?: Carbon::today()->toDateString();
        $periodStart = $startDate ?: Carbon::parse($today)->subYearsNoOverflow(self::DEFAULT_PERIOD_YEARS)->toDateString();

        if ($periodStart > $today) {
            [$periodStart, $today] = [$today, $periodStart];
        }

        $mainCurrency = $this->mainCurrency($companyId);
        $projects = $this->projects($companyId);
        $this->postedInvoices = $this->loadPostedInvoices($projects->pluck('id'));

        $rows = $this->contractRows($projects, $mainCurrency, $today);
        $currencies = $rows->pluck('currency')->filter()->unique()->sort()->values()->all();
        $projectsById = $rows->keyBy('id');

        return [
            'counts'            => $this->counts($rows),
            'currencies'        => $currencies,
            'byCurrency'        => $this->byCurrency($rows, $currencies),
            'mainCurrency'      => $mainCurrency,
            'mainCurrencyTotals'=> $this->mainCurrencyTotals($rows, $mainCurrency),
            'alerts'            => $this->alerts($rows, $today),
            'topByValue'        => $this->topPerCurrency($rows, $currencies, 'amount'),
            'topByRemaining'    => $this->topPerCurrency($rows, $currencies, 'remaining'),
            'topByCount'        => $this->topCustomersByCount($rows, $currencies),
            'topByUncollected'  => $this->topPerCurrency($rows, $currencies, 'uncollected'),
            'aging'             => $this->aging($projectsById, $currencies, $mainCurrency, $today),
            'agingBuckets'      => $this->agingBucketLabels(),
            'trend'             => $this->trend($projectsById, $currencies, $mainCurrency, $periodStart, $today),
            'dataQuality'       => $this->dataQuality($rows, $projectsById, $mainCurrency),
            'details'           => $this->details($rows, $currencies),
            'startDate'         => $periodStart,
            'endDate'           => $today,
            'startDateFormatted'=> Carbon::parse($periodStart)->format('d/m/Y'),
            'endDateFormatted'  => Carbon::parse($today)->format('d/m/Y'),
            'nearExpiryDays'    => self::NEAR_EXPIRY_DAYS,
        ];
    }

    private function mainCurrency(int $companyId): string
    {
        $code = \App\Models\Company::where('id', $companyId)->value('base_currency');
        return strtoupper((string) ($code ?: 'EGP'));
    }

    // ── Source data ──────────────────────────────────────────────

    private function projects(int $companyId): Collection
    {
        return Project::where('company_id', $companyId)
            ->with(['partner:id,name_en,code', 'currency:id,code'])
            ->get();
    }

    /** H14: POSTED only — drafts and cancelled invoices never reach this page. */
    private function loadPostedInvoices(Collection $projectIds): Collection
    {
        if ($projectIds->isEmpty()) return collect();

        return CustomerInvoice::where('status', CustomerInvoice::STATUS_POSTED)
            ->whereHas('salesOrder', fn ($q) => $q->whereIn('project_id', $projectIds))
            ->with(['salesOrder:id,project_id,so_code', 'currency:id,code'])
            ->get();
    }

    // ── Currency helpers (bcmath — Rule 4) ───────────────────────

    /** Rounds a bcmath string half-up to 2 decimals. */
    private function round2(string $value): string
    {
        $offset = bccomp($value, '0', 6) < 0 ? '-0.005' : '0.005';
        return bcadd(bcadd($value, $offset, 6), '0', 2);
    }

    private function sum(iterable $values): string
    {
        $total = '0';
        foreach ($values as $v) {
            $total = bcadd($total, (string) ($v ?? '0'), 2);
        }
        return $total;
    }

    /**
     * Converts a MAIN-currency amount on an invoice into its CONTRACT's
     * currency. Null when there is no honest rate to use — the caller
     * leaves that invoice out and Data Quality lists it.
     */
    private function toContractCurrency(CustomerInvoice $inv, array $contract, string $functionalAmount, string $mainCurrency): ?string
    {
        $invoiceCurrency = strtoupper((string) $inv->currency?->code);

        if ($invoiceCurrency === $contract['currency']) {
            if ($invoiceCurrency === $mainCurrency) return $functionalAmount;
            $rate = (string) $inv->exchange_rate;
            return bccomp($rate, '0', 6) > 0 ? $this->round2(bcdiv($functionalAmount, $rate, 8)) : null;
        }

        if ($contract['currency'] === $mainCurrency) return $functionalAmount;

        $contractRate = $this->trustworthyRate($contract, $mainCurrency);
        return $contractRate !== null ? $this->round2(bcdiv($functionalAmount, $contractRate, 8)) : null;
    }

    /** Invoiced / billed in the contract's currency — native columns when the currencies match. */
    private function nativeOrConverted(CustomerInvoice $inv, array $contract, string $nativeColumn, string $functionalColumn, string $mainCurrency): ?string
    {
        if (strtoupper((string) $inv->currency?->code) === $contract['currency']) {
            return (string) $inv->{$nativeColumn};
        }
        return $this->toContractCurrency($inv, $contract, (string) $inv->{$functionalColumn}, $mainCurrency);
    }

    /** Credit notes reduce what is owed; debit notes increase it. */
    private function netAdjustmentsFunctional(CustomerInvoice $inv): string
    {
        return bcsub((string) $inv->amount_credited, (string) $inv->amount_debited, 2);
    }

    // ── Contract rows ────────────────────────────────────────────

    private function contractRows(Collection $projects, string $mainCurrency, string $asOf): Collection
    {
        $invoicesByProject = $this->postedInvoices->groupBy(fn ($inv) => $inv->salesOrder?->project_id);

        return $projects->map(function (Project $project) use ($invoicesByProject, $mainCurrency, $asOf) {
            $contract = [
                'currency'      => strtoupper((string) $project->currency?->code),
                'exchange_rate' => (string) ($project->exchange_rate ?? '0'),
            ];

            $n = ['invoiced' => '0', 'billed' => '0', 'collected' => '0', 'withheld' => '0', 'deductions' => '0', 'uncollected' => '0'];
            $m = ['invoiced' => '0', 'collected' => '0', 'uncollected' => '0'];
            $unconvertible = 0;

            foreach ($invoicesByProject->get($project->id, collect()) as $inv) {
                // Main (functional) currency — straight from the ledger-side columns.
                $m['invoiced']    = bcadd($m['invoiced'], (string) $inv->subtotal_amount_functional, 2);
                $m['collected']   = bcadd($m['collected'], (string) $inv->amount_paid, 2);
                $m['uncollected'] = bcadd($m['uncollected'], (string) $inv->amount_outstanding, 2);

                // Contract currency.
                $parts = [
                    'invoiced'    => $this->nativeOrConverted($inv, $contract, 'subtotal_amount', 'subtotal_amount_functional', $mainCurrency),
                    'billed'      => $this->nativeOrConverted($inv, $contract, 'invoice_value', 'invoice_value_functional', $mainCurrency),
                    'collected'   => $this->toContractCurrency($inv, $contract, (string) $inv->amount_paid, $mainCurrency),
                    'withheld'    => $this->toContractCurrency($inv, $contract, (string) $inv->amount_wht_deducted, $mainCurrency),
                    'deductions'  => $this->toContractCurrency($inv, $contract, $this->netAdjustmentsFunctional($inv), $mainCurrency),
                    'uncollected' => $this->toContractCurrency($inv, $contract, (string) $inv->amount_outstanding, $mainCurrency),
                ];

                if (in_array(null, $parts, true)) {
                    $unconvertible++;
                    continue;
                }
                foreach ($parts as $k => $v) {
                    $n[$k] = bcadd($n[$k], $v, 2);
                }
            }

            $amount = bcadd((string) $project->contract_value, '0', 2);
            $isOpen = in_array($project->status, ['active', 'on_hold'], true);
            $isExpired = $isOpen && $project->end_date && $project->end_date->toDateString() < $asOf;

            return [
                'id'               => $project->id,
                'partner_id'       => $project->partner_id,
                'customer_name'    => $project->partner?->name_en ?? 'N/A',
                'customer_code'    => $project->partner?->code,
                'code'             => $project->contract_code,
                'name'             => $project->name_en,
                'currency'         => $contract['currency'],
                'amount'           => $amount,
                'invoice_count'    => $invoicesByProject->get($project->id, collect())->count(),
                'invoiced'         => $n['invoiced'],
                'remaining'        => bcsub($amount, $n['invoiced'], 2),
                'billed'           => $n['billed'],
                'collected'        => $n['collected'],
                'withheld'         => $n['withheld'],
                'deductions'       => $n['deductions'],
                'uncollected'      => $n['uncollected'],
                'invoiced_main'    => $m['invoiced'],
                'collected_main'   => $m['collected'],
                'uncollected_main' => $m['uncollected'],
                'unconvertible_invoice_count' => $unconvertible,
                'status'           => $project->status,
                'is_expired'       => $isExpired,
                'status_label'     => $isExpired ? 'Expired' : $this->statusLabel($project->status),
                'start_date'       => $project->start_date?->toDateString(),
                'end_date'         => $project->end_date?->toDateString(),
                'exchange_rate'    => $contract['exchange_rate'],
            ];
        });
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'active'    => 'Active',
            'on_hold'   => 'On Hold',
            'closed'    => 'Closed',
            'cancelled' => 'Cancelled',
            default     => ucfirst($status),
        };
    }

    // ── Counts / Alerts ──────────────────────────────────────────

    private function notInvoiced(Collection $rows): Collection
    {
        return $rows->where('invoice_count', 0)->where('status', '!=', 'closed');
    }

    private function overBilled(Collection $rows): Collection
    {
        return $rows->filter(fn ($r) => bccomp($r['remaining'], '0', 2) < 0);
    }

    private function counts(Collection $rows): array
    {
        return [
            'total'       => $rows->count(),
            'active'      => $rows->where('status', 'active')->where('is_expired', false)->count(),
            'on_hold'     => $rows->where('status', 'on_hold')->where('is_expired', false)->count(),
            'expired'     => $rows->where('is_expired', true)->count(),
            'closed'      => $rows->where('status', 'closed')->count(),
            'cancelled'   => $rows->where('status', 'cancelled')->count(),
            'not_invoiced'=> $this->notInvoiced($rows)->count(),
            'over_billed' => $this->overBilled($rows)->count(),
        ];
    }

    private function alerts(Collection $rows, string $asOf): array
    {
        return [
            'past_end_date_count' => $rows->where('is_expired', true)->count(),
            'ending_soon_count'   => $this->endingSoon($rows, $asOf)->count(),
            'not_invoiced_count'  => $this->notInvoiced($rows)->count(),
            'over_billed_count'   => $this->overBilled($rows)->count(),
            'ending_soon_rows'    => $this->endingSoon($rows, $asOf)->values()->all(),
            'not_invoiced_rows'   => $this->notInvoiced($rows)->values()->all(),
            'past_end_date_rows'  => $rows->where('is_expired', true)->values()->all(),
            'over_billed_rows'    => $this->overBilled($rows)->values()->all(),
        ];
    }

    private function endingSoon(Collection $rows, string $asOf): Collection
    {
        $cutoff = Carbon::parse($asOf)->addDays(self::NEAR_EXPIRY_DAYS)->toDateString();

        return $rows->filter(fn ($r) => in_array($r['status'], ['active', 'on_hold'], true)
            && $r['end_date'] && $r['end_date'] >= $asOf && $r['end_date'] <= $cutoff);
    }

    // ── Per-currency / main-currency totals ─────────────────────

    private function byCurrency(Collection $rows, array $currencies): array
    {
        $out = [];
        foreach ($currencies as $currency) {
            $currencyRows = $rows->where('currency', $currency);
            $out[$currency] = ['contract_count' => $currencyRows->count()];
            foreach (['amount' => 'value', 'invoiced' => 'invoiced', 'remaining' => 'remaining', 'billed' => 'billed',
                      'collected' => 'collected', 'withheld' => 'withheld', 'deductions' => 'deductions',
                      'uncollected' => 'uncollected'] as $field => $key) {
                $out[$currency][$key] = $this->sum($currencyRows->pluck($field));
            }
        }
        return $out;
    }

    private function mainCurrencyTotals(Collection $rows, string $mainCurrency): array
    {
        // Contract Value can only use the contract's own exchange_rate,
        // so a contract with no usable rate is left OUT of this total
        // rather than silently converted at a rate nobody set — flagged
        // in dataQuality()['exchange_rate_issues'], never hidden.
        $trustworthy = $rows->filter(fn ($r) => $this->trustworthyRate($r, $mainCurrency) !== null);
        $value = $this->sum($trustworthy->map(fn ($r) => $r['currency'] === $mainCurrency
            ? $r['amount']
            : $this->round2(bcmul($r['amount'], $r['exchange_rate'], 8))));

        // Invoiced/Collected/Uncollected are the functional-currency
        // columns each invoice already holds at its own booked rate.
        return [
            'value'                     => $value,
            'invoiced'                  => $this->sum($rows->pluck('invoiced_main')),
            'collected'                 => $this->sum($rows->pluck('collected_main')),
            'uncollected'               => $this->sum($rows->pluck('uncollected_main')),
            'value_unconvertible_count' => $rows->count() - $trustworthy->count(),
        ];
    }

    /** The contract's own rate to the main currency, or null when none was really set. */
    private function trustworthyRate(array $row, string $mainCurrency): ?string
    {
        if ($row['currency'] === $mainCurrency) return '1';
        $rate = (string) $row['exchange_rate'];
        if (bccomp($rate, '0', 6) <= 0 || bccomp($rate, '1', 6) === 0) return null;
        return $rate;
    }

    // ── Top-N leaderboards ────────────────────────────────────────

    private function topPerCurrency(Collection $rows, array $currencies, string $sortKey): array
    {
        $out = [];
        foreach ($currencies as $currency) {
            $out[$currency] = $rows->where('currency', $currency)
                ->sort(fn ($a, $b) => bccomp($b[$sortKey], $a[$sortKey], 2))
                ->take(10)->values()->all();
        }
        return $out;
    }

    /** Grouped by customer ID — two customers with the same name stay separate. */
    private function topCustomersByCount(Collection $rows, array $currencies): array
    {
        $out = [];
        foreach ($currencies as $currency) {
            $out[$currency] = $rows->where('currency', $currency)
                ->groupBy(fn ($r) => (string) ($r['partner_id'] ?? 'none'))
                ->map(function ($group) {
                    $first = $group->first();
                    $name = $first['customer_name'];
                    if (! empty($first['customer_code'])) $name .= ' ('.$first['customer_code'].')';
                    return [
                        'partner_id'     => $first['partner_id'],
                        'customer_name'  => $name,
                        'contract_count' => $group->count(),
                        'amount'         => $this->sum($group->pluck('amount')),
                    ];
                })
                ->sortByDesc('contract_count')->take(10)->values()->all();
        }
        return $out;
    }

    // ── Aging (Contract Dashboard's own coarser 5-bucket scheme —
    //    deliberately different from the finer 8-interval scheme used
    //    on Cash Forecast's Invoice Aging; matches CashVero's own
    //    ContractDashboardService::aging(), not AgingBucketCalculator) ──
    //
    //    H14: amounts are in the CONTRACT's currency (the page's tabs
    //    are contract currencies) — amount_outstanding is kept in the
    //    main currency and is converted, never shown under a foreign
    //    heading as-is.

    private const AGING_BUCKETS = ['not_due', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    private function aging(Collection $projectsById, array $currencies, string $mainCurrency, string $asOf): array
    {
        if ($currencies === []) return [];

        $asOfC = Carbon::parse($asOf);

        $aging = [];
        foreach ($currencies as $currency) {
            $aging[$currency] = [];
            foreach (self::AGING_BUCKETS as $bucket) {
                $aging[$currency][$bucket] = ['amount' => '0', 'invoice_count' => 0];
            }
            $aging[$currency]['total'] = ['amount' => '0', 'invoice_count' => 0];
            $aging[$currency]['overdue_total'] = ['amount' => '0', 'invoice_count' => 0];
        }

        $open = $this->postedInvoices
            ->filter(fn ($inv) => bccomp((string) $inv->amount_outstanding, '0', 2) > 0
                && $inv->invoice_date && $inv->invoice_date->toDateString() <= $asOf);

        foreach ($open as $invoice) {
            $contract = $projectsById->get($invoice->salesOrder?->project_id);
            if (! $contract || ! isset($aging[$contract['currency']])) continue;

            $amount = $this->toContractCurrency($invoice, $contract, (string) $invoice->amount_outstanding, $mainCurrency);
            if ($amount === null) continue; // listed under Data Quality

            $currency = $contract['currency'];
            $daysPastDue = $invoice->due_date ? $asOfC->diffInDays($invoice->due_date, false) * -1 : -1;
            $bucket = match (true) {
                $daysPastDue <= 0  => 'not_due',
                $daysPastDue <= 30 => 'd1_30',
                $daysPastDue <= 60 => 'd31_60',
                $daysPastDue <= 90 => 'd61_90',
                default            => 'd90_plus',
            };

            $aging[$currency][$bucket]['amount'] = bcadd($aging[$currency][$bucket]['amount'], $amount, 2);
            $aging[$currency][$bucket]['invoice_count']++;
            $aging[$currency]['total']['amount'] = bcadd($aging[$currency]['total']['amount'], $amount, 2);
            $aging[$currency]['total']['invoice_count']++;
            if ($bucket !== 'not_due') {
                $aging[$currency]['overdue_total']['amount'] = bcadd($aging[$currency]['overdue_total']['amount'], $amount, 2);
                $aging[$currency]['overdue_total']['invoice_count']++;
            }
        }

        return $aging;
    }

    private function agingBucketLabels(): array
    {
        return [
            'not_due'  => 'Not Due Yet',
            'd1_30'    => '1–30 Days',
            'd31_60'   => '31–60 Days',
            'd61_90'   => '61–90 Days',
            'd90_plus' => 'Over 90 Days',
        ];
    }

    // ── Monthly trend (invoiced vs collected, within the period) ──
    //    Grouped under the CONTRACT's currency, same basis as the
    //    rest of the page (H14).

    private function trend(Collection $projectsById, array $currencies, string $mainCurrency, string $from, string $to): array
    {
        $out = array_fill_keys($currencies, []);
        $months = array_fill_keys($currencies, []);

        $inPeriod = $this->postedInvoices->filter(fn ($inv) => $inv->invoice_date
            && $inv->invoice_date->toDateString() >= $from && $inv->invoice_date->toDateString() <= $to);

        foreach ($inPeriod as $inv) {
            $contract = $projectsById->get($inv->salesOrder?->project_id);
            if (! $contract || ! array_key_exists($contract['currency'], $months)) continue;

            $invoiced  = $this->nativeOrConverted($inv, $contract, 'subtotal_amount', 'subtotal_amount_functional', $mainCurrency);
            $collected = $this->toContractCurrency($inv, $contract, (string) $inv->amount_paid, $mainCurrency);
            if ($invoiced === null || $collected === null) continue;

            $code = $contract['currency'];
            $key = $inv->invoice_date->format('Y-m');
            $months[$code][$key]['invoiced']  = bcadd($months[$code][$key]['invoiced'] ?? '0', $invoiced, 2);
            $months[$code][$key]['collected'] = bcadd($months[$code][$key]['collected'] ?? '0', $collected, 2);
        }

        foreach ($currencies as $code) {
            ksort($months[$code]);
            $out[$code] = collect($months[$code])
                ->map(fn ($v, $k) => ['month' => $k, 'invoiced' => $v['invoiced'], 'collected' => $v['collected']])
                ->values()->all();
        }

        return $out;
    }

    // ── Data quality ─────────────────────────────────────────────

    private function dataQuality(Collection $rows, Collection $projectsById, string $mainCurrency): array
    {
        // 1. Invoices posted in a currency different from their contract's own.
        $mismatchedCurrencyInvoices = $this->postedInvoices
            ->filter(function ($inv) use ($projectsById) {
                $project = $projectsById->get($inv->salesOrder?->project_id);
                return $project && $inv->currency?->code && strtoupper($inv->currency->code) !== $project['currency'];
            })
            ->map(function ($inv) use ($projectsById, $mainCurrency) {
                $project = $projectsById->get($inv->salesOrder?->project_id);
                return [
                    'id'                => $inv->id,
                    'contract_code'     => $project['code'] ?? null,
                    'contract_name'     => $project['name'] ?? null,
                    'contract_currency' => $project['currency'] ?? null,
                    'invoice_number'    => $inv->invoice_code,
                    'invoice_currency'  => $inv->currency?->code,
                    'invoice_amount'    => (string) $inv->invoice_value,
                    // H14: true when no honest rate exists to show this
                    // invoice in its contract's currency — it is then
                    // left out of that contract's per-currency figures
                    // (but still counted in the main-currency cards).
                    'excluded_from_contract_figures' => $this->toContractCurrency($inv, $project, '1', $mainCurrency) === null,
                ];
            })->values()->all();

        // 2. Contracts excluded from the main-currency total (no trustworthy rate).
        $exchangeRateIssues = $rows->filter(fn ($r) => $this->trustworthyRate($r, $mainCurrency) === null)
            ->map(fn ($r) => [
                'id' => $r['id'], 'customer_name' => $r['customer_name'], 'code' => $r['code'],
                'currency' => $r['currency'], 'amount' => $r['amount'], 'exchange_rate' => $r['exchange_rate'],
            ])->values()->all();

        // 3. Billed − Collected − Withheld − (Credit − Debit notes) ≠ Uncollected.
        //    H14: all five figures in the MAIN currency — the old check
        //    compared a USD billed amount with EGP collections and so
        //    flagged every foreign-currency invoice.
        $unbalancedInvoices = $this->postedInvoices
            ->map(function ($inv) use ($projectsById) {
                $deductions = bcadd((string) $inv->amount_wht_deducted, $this->netAdjustmentsFunctional($inv), 2);
                $expected = bcsub(bcsub((string) $inv->invoice_value_functional, (string) $inv->amount_paid, 2), $deductions, 2);
                return [
                    'id' => $inv->id,
                    'contract_code' => $projectsById->get($inv->salesOrder?->project_id)['code'] ?? null,
                    'invoice_number' => $inv->invoice_code,
                    'net_invoice_amount' => (string) $inv->invoice_value_functional,
                    'total_collected_amount' => (string) $inv->amount_paid,
                    'total_deductions' => $deductions,
                    'net_balance' => (string) $inv->amount_outstanding,
                    '_gap' => bcsub($expected, (string) $inv->amount_outstanding, 2),
                ];
            })
            ->filter(fn ($row) => bccomp(ltrim($row['_gap'], '-'), '0.01', 2) > 0)
            ->map(fn ($row) => collect($row)->except('_gap')->all())
            ->values()->all();

        // 4. Contracts with no code — no invoice can be matched to them by code.
        $contractsWithoutCode = $rows->filter(fn ($r) => empty($r['code']))
            ->map(fn ($r) => ['id' => $r['id'], 'customer_name' => $r['customer_name'], 'name' => $r['name'], 'currency' => $r['currency'], 'amount' => $r['amount']])
            ->values()->all();

        return [
            'mismatched_currency_count'     => count($mismatchedCurrencyInvoices),
            'mismatched_currency_invoices'  => $mismatchedCurrencyInvoices,
            'exchange_rate_issue_count'     => count($exchangeRateIssues),
            'exchange_rate_issues'          => $exchangeRateIssues,
            'unbalanced_invoice_count'      => count($unbalancedInvoices),
            'unbalanced_invoices'           => $unbalancedInvoices,
            'contracts_without_code_count'  => count($contractsWithoutCode),
            'contracts_without_code'        => $contractsWithoutCode,
            'main_currency'                 => $mainCurrency,
        ];
    }

    // ── Details (full flat row list — feeds the Excel export) ────

    private function details(Collection $rows, array $currencies): array
    {
        $byCurrency = [];
        foreach ($currencies as $c) {
            $byCurrency[$c] = $rows->where('currency', $c)->values()->all();
        }
        return ['all' => $rows->values()->all(), 'byCurrency' => $byCurrency];
    }
}
