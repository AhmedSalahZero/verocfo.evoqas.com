<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  RevenueScheduleService
//
//  Audit H18 (Sept 2026) — spreading a service contract's revenue
//  over its service period (IFRS 15.35(a): the customer receives the
//  service as it is performed, so revenue is recognised over time).
//
//  Scope confirmed with Mahmoud: annual maintenance contracts. When a
//  contract is invoiced monthly nothing changes. When it is invoiced
//  for the whole period at once, the user fills in the SERVICE PERIOD
//  on the invoice line, and:
//
//    ON POSTING (called by CustomerInvoiceService::post())
//      needsSchedule() — true for a service (non-stock) line whose
//        period runs beyond the invoice month. That line is credited
//        to 236 Deferred Revenue instead of revenue;
//      createSchedule() — one slice per calendar month, pro-rated by
//        days (e.g. 1 Mar – 28 Feb: March = 31/365 of the amount); the
//        last slice absorbs rounding so the slices add up exactly.
//        Each slice is recognised on its month-end — or on the invoice
//        date, for months already past when the invoice was raised,
//        which are released straight away.
//
//    MONTHLY RUN (Accounting › Revenue Schedules › Release)
//      releaseUpTo($date) — every pending slice due on or before the
//        date: Dr 236 Deferred Revenue / Cr the line's revenue account
//        (same cost centre). One journal entry per slice.
//
//    SET TO DRAFT (called by CustomerInvoiceService::setToDraft())
//      cancelForInvoice() — reverses every released slice and cancels
//        the schedule; nothing is deleted.
//
//  Amounts are EGP at the invoice's own rate (exactly what was credited
//  to 236). A credit note on such an invoice still reduces revenue
//  directly, as today — the total over the contract is right, only the
//  month it lands in differs.
//
//  bcmath (Rule 4), AuditLog (Rule 5), company_id explicit (H9).
//
//  @layer    Service
//  @depends  RevenueSchedule, RevenueScheduleLine, CustomerInvoice,
//            CustomerInvoiceLine, JournalEntryService, ChartOfAccount,
//            AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CustomerInvoice;
use App\Models\CustomerInvoiceLine;
use App\Models\JournalEntry;
use App\Models\RevenueSchedule;
use App\Models\RevenueScheduleLine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RevenueScheduleService
{
    public const CODE_DEFERRED_REVENUE = '236';

    public function __construct(private JournalEntryService $journalEntryService) {}

    // ── Called by CustomerInvoiceService ─────────────────────────

    /** True when this line's revenue must be spread (see class header). */
    public static function needsSchedule(CustomerInvoice $invoice, CustomerInvoiceLine $line): bool
    {
        if ($line->product && $line->product->has_stock) {
            return false; // goods follow the delivery rules (143 / 236), not this
        }
        if (! $line->service_period_start || ! $line->service_period_end) {
            return false;
        }

        $invoiceMonthEnd = Carbon::parse($invoice->invoice_date)->endOfMonth()->toDateString();

        return Carbon::parse($line->service_period_end)->toDateString() > $invoiceMonthEnd
            || Carbon::parse($line->service_period_start)->startOfMonth()->lt(Carbon::parse($invoice->invoice_date)->startOfMonth());
    }

    public function deferredRevenueAccountId(int $companyId): int
    {
        $account = ChartOfAccount::findByCode($companyId, self::CODE_DEFERRED_REVENUE)
            ?? throw new \DomainException('Chart of Accounts is missing system account 236 (Deferred Revenue) — check the seeder.');

        return (int) $account->id;
    }

    /**
     * Creates the monthly slices for a line just credited to 236 and
     * releases those already due by the invoice date.
     */
    public function createSchedule(CustomerInvoice $invoice, CustomerInvoiceLine $line, string $amountFunctional, User $actor): RevenueSchedule
    {
        $start = Carbon::parse($line->service_period_start)->startOfDay();
        $end   = Carbon::parse($line->service_period_end)->startOfDay();
        if ($end->lt($start)) {
            throw new \DomainException("Line \"{$line->description}\": the service period ends before it starts.");
        }

        $invoiceDate = Carbon::parse($invoice->invoice_date)->toDateString();
        $totalDays = (int) $start->diffInDays($end) + 1;

        $schedule = RevenueSchedule::create([
            'company_id' => $invoice->company_id, 'customer_invoice_id' => $invoice->id,
            'customer_invoice_line_id' => $line->id, 'revenue_account_id' => $line->revenue_account_id,
            'cost_center_id' => $line->cost_center_id, 'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(), 'total_amount' => $amountFunctional,
            'status' => RevenueSchedule::STATUS_ACTIVE,
        ]);

        $allocated = '0.00';
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $monthEnd = $cursor->copy()->endOfMonth()->startOfDay();
            $sliceEnd = $monthEnd->lt($end) ? $monthEnd : $end->copy();
            $days = (int) $cursor->diffInDays($sliceEnd) + 1;
            $isLast = $sliceEnd->eq($end);

            $amount = $isLast
                ? bcsub($amountFunctional, $allocated, 2)
                : $this->round2(bcdiv(bcmul($amountFunctional, (string) $days, 8), (string) $totalDays, 8));
            $allocated = bcadd($allocated, $amount, 2);

            RevenueScheduleLine::create([
                'company_id' => $invoice->company_id, 'revenue_schedule_id' => $schedule->id,
                'period_month' => $cursor->copy()->startOfMonth()->toDateString(),
                'recognition_date' => max($monthEnd->toDateString(), $invoiceDate),
                'days' => $days, 'amount' => $amount, 'status' => RevenueScheduleLine::STATUS_PENDING,
            ]);

            $cursor = $sliceEnd->copy()->addDay();
        }

        // Months already over when the contract was invoiced: release now.
        $this->releaseSchedule($schedule, $invoiceDate, $actor, true);

        return $schedule->fresh('lines');
    }

    /** Reverses released slices and cancels every schedule of this invoice. */
    public function cancelForInvoice(CustomerInvoice $invoice, User $actor): void
    {
        $schedules = RevenueSchedule::where('company_id', $invoice->company_id)
            ->where('customer_invoice_id', $invoice->id)
            ->where('status', '!=', RevenueSchedule::STATUS_CANCELLED)
            ->lockForUpdate()->get();

        foreach ($schedules as $schedule) {
            foreach ($schedule->lines as $slice) {
                if ($slice->status === RevenueScheduleLine::STATUS_RELEASED && $slice->journal_entry_id) {
                    $je = JournalEntry::find($slice->journal_entry_id);
                    if ($je && $je->status === JournalEntry::STATUS_POSTED) {
                        $this->journalEntryService->reverse($je, $actor, 'Invoice reopened — revenue release reversed');
                    }
                }
                $slice->update(['status' => RevenueScheduleLine::STATUS_CANCELLED]);
            }
            $before = $schedule->toArray();
            $schedule->update(['status' => RevenueSchedule::STATUS_CANCELLED, 'released_amount' => '0.00']);
            AuditLog::record($actor, 'revenue_schedule.cancelled', $schedule, $before, $schedule->fresh()->toArray());
        }
    }

    // ── Screen ───────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return RevenueSchedule::where('company_id', $companyId)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->with(['customerInvoice:id,invoice_code,partner_id,invoice_date', 'customerInvoice.partner:id,name_en',
                    'customerInvoiceLine:id,description', 'revenueAccount:id,code,name_en', 'lines'])
            ->orderByDesc('period_start')->orderByDesc('id')
            ->paginate(20)->withQueryString();
    }

    /** Slices due on or before $date, not yet released — the run's preview. */
    public function dueSummary(int $companyId, string $date): array
    {
        $due = RevenueScheduleLine::where('company_id', $companyId)
            ->where('status', RevenueScheduleLine::STATUS_PENDING)
            ->whereDate('recognition_date', '<=', $date)
            ->whereHas('schedule', fn ($q) => $q->where('status', RevenueSchedule::STATUS_ACTIVE))
            ->get(['id', 'amount']);

        return [
            'date'   => $date,
            'count'  => $due->count(),
            'amount' => $due->reduce(fn ($c, $l) => bcadd($c, (string) $l->amount, 2), '0.00'),
        ];
    }

    /** The monthly run: releases every slice due on or before $date. */
    public function releaseUpTo(int $companyId, string $date, User $actor): array
    {
        $date = substr($date, 0, 10);
        $schedules = RevenueSchedule::where('company_id', $companyId)
            ->where('status', RevenueSchedule::STATUS_ACTIVE)
            ->whereHas('lines', fn ($q) => $q->where('status', RevenueScheduleLine::STATUS_PENDING)->whereDate('recognition_date', '<=', $date))
            ->get();

        $count = 0;
        $amount = '0.00';
        foreach ($schedules as $schedule) {
            [$c, $a] = DB::transaction(fn () => $this->releaseSchedule(
                RevenueSchedule::lockForUpdate()->findOrFail($schedule->id), $date, $actor, false
            ));
            $count += $c;
            $amount = bcadd($amount, $a, 2);
        }

        return ['count' => $count, 'amount' => $amount];
    }

    // ── Private ──────────────────────────────────────────────────

    /**
     * Posts every pending slice of one schedule due on or before $date.
     * $monthEndOnly (at invoice posting): release only months whose
     * month-end has passed, never the invoice month itself early.
     */
    private function releaseSchedule(RevenueSchedule $schedule, string $date, User $actor, bool $monthEndOnly): array
    {
        $deferred = $this->deferredRevenueAccountId($schedule->company_id);
        $invoice = $schedule->customerInvoice()->first(['id', 'invoice_code']);
        $count = 0;
        $amount = '0.00';

        $slices = $schedule->lines()->where('status', RevenueScheduleLine::STATUS_PENDING)
            ->whereDate('recognition_date', '<=', $date)->get();

        foreach ($slices as $slice) {
            if ($monthEndOnly && Carbon::parse($slice->period_month)->endOfMonth()->toDateString() >= $date) {
                continue;
            }

            $label = 'Revenue for '.Carbon::parse($slice->period_month)->format('M Y').' — '.$invoice->invoice_code;
            $je = $this->journalEntryService->post(
                companyId: $schedule->company_id,
                entryDate: Carbon::parse($slice->recognition_date)->toDateString(),
                lines: [
                    ['chart_of_account_id' => $deferred, 'debit' => (string) $slice->amount, 'credit' => '0', 'description' => $label],
                    ['chart_of_account_id' => $schedule->revenue_account_id, 'cost_center_id' => $schedule->cost_center_id, 'debit' => '0', 'credit' => (string) $slice->amount, 'description' => $label],
                ],
                sourceType: 'revenue_schedule_release', sourceId: $slice->id,
                narration: $label, actor: $actor,
            );

            $slice->update(['status' => RevenueScheduleLine::STATUS_RELEASED, 'journal_entry_id' => $je->id, 'released_at' => now()]);
            $count++;
            $amount = bcadd($amount, (string) $slice->amount, 2);
        }

        if ($count > 0) {
            $before = $schedule->toArray();
            $released = bcadd((string) $schedule->released_amount, $amount, 2);
            $done = ! $schedule->lines()->where('status', RevenueScheduleLine::STATUS_PENDING)->exists();
            $schedule->update(['released_amount' => $released, 'status' => $done ? RevenueSchedule::STATUS_COMPLETED : RevenueSchedule::STATUS_ACTIVE]);
            AuditLog::record($actor, 'revenue_schedule.released', $schedule, $before, $schedule->fresh()->toArray());
        }

        return [$count, $amount];
    }

    private function round2(string $value): string
    {
        return bcadd($value, str_starts_with($value, '-') ? '-0.005' : '0.005', 2);
    }
}
