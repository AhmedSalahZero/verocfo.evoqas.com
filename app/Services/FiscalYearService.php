<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  FiscalYearService
//
//  All business logic for Fiscal Year and Accounting Period
//  management.
//
//  AUTO-CREATION DESIGN (Rules 25.10, 26.1–26.3):
//
//  createFirst(Company):
//    Called automatically when a company is created by Super Admin.
//    Uses opening_balance_date + fiscal_year_start_month to
//    calculate the first fiscal year dates.
//    May be a stub period (< 12 months) if opening date is mid-year.
//
//  createNext(int $companyId, User $actor):
//    Called by "Create Next Fiscal Year" button.
//    Company Admin only — enforced in controller.
//    Always exactly 12 months / 12 periods.
//    Multiple open FYs allowed (Rule 26.1).
//
//  newerFiscalYearExists():
//    Helper for the posting engine to enforce Rules 26.4 / 26.5.
//    Hard blocks revenue invoices and inventory receipts from
//    being back-dated once a newer fiscal year exists.
//
//  @layer    Service
//  @depends  App\Models\FiscalYear, AccountingPeriod, Company, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FiscalYearService
{
    // ── List ──────────────────────────────────────────────────────

    public function list(int $companyId): Collection
    {
        return FiscalYear::with(['periods.closedBy', 'periods.reopenedBy', 'closedBy'])
            ->orderByDesc('start_date')
            ->get();
    }

    // ── Create First Fiscal Year ──────────────────────────────────

    /**
     * Auto-creates the first fiscal year at company setup.
     * Called by CompanyService after company is saved.
     *
     * Example A — stub period:
     *   opening_balance_date    = 01 Jul 2026
     *   fiscal_year_start_month = 1 (January)
     *   → First FY: 01 Jul 2026 → 31 Dec 2026  (6 months)
     *
     * Example B — full year alignment:
     *   opening_balance_date    = 01 Jan 2026
     *   fiscal_year_start_month = 1
     *   → First FY: 01 Jan 2026 → 31 Dec 2026  (12 months)
     *
     * Example C — mid-year alignment:
     *   opening_balance_date    = 01 Jul 2026
     *   fiscal_year_start_month = 7 (July)
     *   → First FY: 01 Jul 2026 → 30 Jun 2027  (12 months)
     */
    public function createFirst(Company $company): FiscalYear
    {
        if (! $company->opening_balance_date) {
            throw new \DomainException(
                'Company must have an opening_balance_date before a fiscal year can be created.'
            );
        }

        $startDate  = Carbon::parse($company->opening_balance_date)->startOfDay();
        $startMonth = $company->fiscal_year_start_month ?? 1;
        $endDate    = $this->calculateFirstFYEnd($startDate, $startMonth);

        return DB::transaction(function () use ($company, $startDate, $endDate) {
            $fiscalYear = FiscalYear::create([
                'company_id' => $company->id,
                'name'       => $this->generateName($startDate, $endDate),
                'start_date' => $startDate->toDateString(),
                'end_date'   => $endDate->toDateString(),
                'status'     => FiscalYear::STATUS_OPEN,
            ]);

            $this->generatePeriods($fiscalYear, $company->id);

            AuditLog::create([
                'company_id'     => $company->id,
                'user_id'        => null,
                'action'         => 'create_first_fiscal_year',
                'auditable_type' => FiscalYear::class,
                'auditable_id'   => $fiscalYear->id,
                'before'         => null,
                'after'          => $fiscalYear->load('periods')->toArray(),
            ]);

            return $fiscalYear->load('periods');
        });
    }

    // ── Create Next Fiscal Year ───────────────────────────────────

    /**
     * Creates the next fiscal year — Company Admin only.
     * Starts the day after the most recent FY ends.
     * Always exactly 12 months and 12 periods.
     * Multiple open fiscal years are allowed (Rule 26.1).
     */
    public function createNext(int $companyId, User $actor): FiscalYear
    {
        // Bug fix: this used to be FiscalYear::orderByDesc('end_date')->first()
        // with no company filter. FiscalYear's global CompanyScope only
        // engages when auth()->check() is true (see CompanyScope::apply()),
        // which is never the case in an Artisan command — so this query
        // was silently picking the latest fiscal year across EVERY
        // company in the database, not this one. In a multi-tenant app
        // this must never rely on the scope alone; $companyId is right
        // here as a parameter, so it's used explicitly now.
        $latest = FiscalYear::where('company_id', $companyId)->orderByDesc('end_date')->first();

        if (! $latest) {
            throw new \DomainException(
                'No fiscal year found. The first fiscal year is created automatically at company setup.'
            );
        }

        $startDate = Carbon::parse($latest->end_date)->addDay();
        $endDate   = $startDate->copy()->addYear()->subDay();

        $this->validateNonOverlapping($companyId, $startDate->toDateString(), $endDate->toDateString());

        return DB::transaction(function () use ($companyId, $startDate, $endDate, $actor) {
            $fiscalYear = FiscalYear::create([
                'company_id' => $companyId,
                'name'       => $this->generateName($startDate, $endDate),
                'start_date' => $startDate->toDateString(),
                'end_date'   => $endDate->toDateString(),
                'status'     => FiscalYear::STATUS_OPEN,
            ]);

            $this->generatePeriods($fiscalYear, $companyId);

            AuditLog::create([
                'company_id'     => $companyId,
                'user_id'        => $actor->id,
                'action'         => 'create_next_fiscal_year',
                'auditable_type' => FiscalYear::class,
                'auditable_id'   => $fiscalYear->id,
                'before'         => null,
                'after'          => $fiscalYear->load('periods')->toArray(),
            ]);

            return $fiscalYear->load('periods');
        });
    }

    // ── Close Period ──────────────────────────────────────────────

    public function closePeriod(AccountingPeriod $period, User $actor): AccountingPeriod
    {
        if ($period->isClosed()) {
            throw new \DomainException("Period [{$period->name}] is already closed.");
        }

        if (! $period->canClose()) {
            throw new \DomainException(
                "Cannot close [{$period->name}] — earlier periods in this fiscal year are still open. Close periods in sequence."
            );
        }

        return DB::transaction(function () use ($period, $actor) {
            $before = $period->toArray();
            $period->update([
                'status'    => AccountingPeriod::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ]);

            AuditLog::create([
                'company_id'     => $period->company_id,
                'user_id'        => $actor->id,
                'action'         => 'close_period',
                'auditable_type' => AccountingPeriod::class,
                'auditable_id'   => $period->id,
                'before'         => $before,
                'after'          => $period->fresh()->toArray(),
            ]);

            return $period->fresh();
        });
    }

    // ── Reopen Period ─────────────────────────────────────────────

    public function reopenPeriod(AccountingPeriod $period, User $actor, ?string $reason = null): AccountingPeriod
    {
        // Audit H4 (Sept 2026): reopening a closed month is an exception,
        // not a routine step. It now needs a Company Admin (or the Super
        // Admin) — not just the close-period right — and a written reason
        // of at least 10 characters. The reason is stored on the period,
        // written to the audit log under its own action, and every other
        // admin of the company is notified.
        if (! $actor->isSuperAdmin() && ! $actor->isCompanyAdmin()) {
            throw new \DomainException('Only a Company Admin can reopen a closed month.');
        }
        $reason = trim((string) $reason);
        if (mb_strlen($reason) < 10) {
            throw new \DomainException('Please give a reason (at least 10 characters) for reopening this month.');
        }

        if ($period->isOpen()) {
            throw new \DomainException("Period [{$period->name}] is already open.");
        }

        if ($period->fiscalYear->isClosed()) {
            throw new \DomainException(
                "Cannot reopen [{$period->name}] — its fiscal year is permanently closed."
            );
        }

        // Audit M6 — months close in order, so the closed ones are an
        // unbroken run from the start. Reopening is refused only when a
        // LATER month is still closed; a later month that is open is the
        // normal case. (This check used to be the reverse of its message.)
        $laterClosedExists = AccountingPeriod::where('company_id', $period->company_id)
            ->where('fiscal_year_id', $period->fiscal_year_id)
            ->where('period_number', '>', $period->period_number)
            ->where('status', AccountingPeriod::STATUS_CLOSED)
            ->exists();

        if ($laterClosedExists) {
            throw new \DomainException(
                "Cannot reopen [{$period->name}] — reopen periods in reverse sequence (most recently closed first)."
            );
        }

        return DB::transaction(function () use ($period, $actor, $reason) {
            $before = $period->toArray();
            $period->update([
                'status'        => AccountingPeriod::STATUS_OPEN,
                'was_reopened'  => true,
                'reopened_at'   => now(),
                'reopened_by'   => $actor->id,
                'reopen_reason' => $reason,
            ]);

            $this->notifyAdminsOfReopen($period, $actor, $reason);

            AuditLog::create([
                'company_id'     => $period->company_id,
                'user_id'        => $actor->id,
                'action'         => 'CLOSED_PERIOD_REOPENED', // H4: its own, unmistakable action
                'auditable_type' => AccountingPeriod::class,
                'auditable_id'   => $period->id,
                'before'         => $before,
                'after'          => $period->fresh()->toArray(),
            ]);

            return $period->fresh();
        });
    }

    /** Audit H4 — every other active admin of the company is told a closed month was reopened, and why. */
    private function notifyAdminsOfReopen(AccountingPeriod $period, User $actor, string $reason): void
    {
        $admins = User::withoutGlobalScopes()
            ->where('company_id', $period->company_id)
            ->where('role', User::ROLE_COMPANY_ADMIN)
            ->where('is_active', true)
            ->where('id', '!=', $actor->id)
            ->pluck('id');

        foreach ($admins as $adminId) {
            \App\Models\Notification::create([
                'company_id' => $period->company_id,
                'user_id'    => $adminId,
                'type'       => 'warning',
                'severity'   => 'high',
                'title'      => 'Closed month reopened',
                'message'    => "{$actor->name} reopened {$period->name}. Reason: {$reason}",
                'source'     => 'accounting_period:'.$period->id,
                'is_read'    => false,
            ]);
        }
    }

    // ── Close Fiscal Year ─────────────────────────────────────────

    public function closeFiscalYear(FiscalYear $fiscalYear, User $actor): FiscalYear
    {
        if ($fiscalYear->isClosed()) {
            throw new \DomainException("Fiscal year [{$fiscalYear->name}] is already closed.");
        }

        if (! $fiscalYear->canClose()) {
            $openCount = $fiscalYear->periods()
                ->where('status', AccountingPeriod::STATUS_OPEN)
                ->count();
            throw new \DomainException(
                "Cannot close [{$fiscalYear->name}] — {$openCount} period(s) are still open. Close all periods first."
            );
        }

        return DB::transaction(function () use ($fiscalYear, $actor) {
            $before = $fiscalYear->toArray();
            $fiscalYear->update([
                'status'    => FiscalYear::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ]);

            AuditLog::create([
                'company_id'     => $fiscalYear->company_id,
                'user_id'        => $actor->id,
                'action'         => 'close_fiscal_year',
                'auditable_type' => FiscalYear::class,
                'auditable_id'   => $fiscalYear->id,
                'before'         => $before,
                'after'          => $fiscalYear->fresh()->toArray(),
            ]);

            return $fiscalYear->fresh();
        });
    }

    // ── Posting Engine Helper ─────────────────────────────────────

    /**
     * Returns true if a fiscal year starts AFTER the given date.
     * Enforces Rules 26.4 and 26.5: hard block on back-dating
     * revenue invoices (CustomerInvoiceService::post()) and
     * inventory receipts (GoodsReceiptNoteService::post()) once a
     * newer fiscal year exists. Wired up September 2026 — this
     * method previously had zero callers.
     */
    public function newerFiscalYearExists(int $companyId, string $transactionDate): bool
    {
        return FiscalYear::where('company_id', $companyId)
            ->where('start_date', '>', $transactionDate)
            ->exists();
    }

    /**
     * The AccountingPeriod a given date falls into, if one exists.
     * First real usage of AccountingPeriod::isClosed() outside its own
     * model — confirmed gap (August 2026 audit): the model has always
     * correctly documented "closed → no new postings, no edits, no
     * reversals," but nothing in the app ever actually called it. This
     * is the shared lookup every "reopen a posted document" action
     * (Customer Collection first, then everywhere else this pattern
     * gets rolled out) uses to enforce that rule for real.
     */
    public function periodForDate(int $companyId, string $date): ?AccountingPeriod
    {
        return AccountingPeriod::where('company_id', $companyId)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();
    }

    /**
     * True if the period a date falls in is closed — OR if no period
     * exists at all for that date (fails safe: an unrecognized date
     * should never be treated as "obviously fine to reopen").
     */
    public function isPeriodClosed(int $companyId, string $date): bool
    {
        $period = $this->periodForDate($companyId, $date);

        return $period === null || $period->isClosed();
    }

    // ── Private Helpers ───────────────────────────────────────────

    /**
     * Calculates the end date of the first fiscal year.
     * Finds the next fiscal year start month after startDate,
     * then subtracts 1 day.
     */
    private function calculateFirstFYEnd(Carbon $startDate, int $startMonth): Carbon
    {
        $candidate = Carbon::create($startDate->year, $startMonth, 1);

        // If candidate is on or before startDate, move to next year
        if ($candidate->lte($startDate)) {
            $candidate->addYear();
        }

        return $candidate->subDay()->startOfDay();
    }

    /**
     * Generates monthly accounting periods for a fiscal year.
     * Handles stub periods (fewer than 12 months).
     */
    private function generatePeriods(FiscalYear $fiscalYear, int $companyId): void
    {
        $current      = Carbon::parse($fiscalYear->start_date)->startOfMonth();
        $fyEnd        = Carbon::parse($fiscalYear->end_date);
        $periodNumber = 1;

        while ($current->lte($fyEnd) && $periodNumber <= 12) {
            $periodEnd = $current->copy()->endOfMonth();
            if ($periodEnd->gt($fyEnd)) {
                $periodEnd = $fyEnd->copy();
            }

            AccountingPeriod::create([
                'company_id'     => $companyId,
                'fiscal_year_id' => $fiscalYear->id,
                'name'           => $current->format('F Y'),
                'start_date'     => $current->copy()->startOfMonth()->toDateString(),
                'end_date'       => $periodEnd->toDateString(),
                'period_number'  => $periodNumber,
                'status'         => AccountingPeriod::STATUS_OPEN,
            ]);

            $periodNumber++;
            $current->addMonth();
        }
    }

    /**
     * Human-readable fiscal year name.
     * Same year:  "FY2026"
     * Split year: "FY2026/27"
     */
    private function generateName(Carbon $startDate, Carbon $endDate): string
    {
        if ($startDate->year === $endDate->year) {
            return 'FY' . $startDate->year;
        }
        return 'FY' . $startDate->year . '/' . substr($endDate->year, 2);
    }

    /**
     * Validates no date overlap with existing fiscal years.
     */
    private function validateNonOverlapping(int $companyId, string $startDate, string $endDate): void
    {
        $overlap = FiscalYear::where('company_id', $companyId)
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('start_date', [$startDate, $endDate])
                  ->orWhereBetween('end_date', [$startDate, $endDate])
                  ->orWhere(function ($i) use ($startDate, $endDate) {
                      $i->where('start_date', '<=', $startDate)
                        ->where('end_date', '>=', $endDate);
                  });
            })->first();

        if ($overlap) {
            throw new \DomainException(
                "Date range overlaps with existing fiscal year [{$overlap->name}]."
            );
        }
    }
}
