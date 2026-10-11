<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  LeaveRequestService
//
//  Every rule Leave Management has. The model names the states; this
//  class decides who may move between them and what has to be true
//  first.
//
//  ══ NO ACCOUNTING, BY DESIGN ══
//  Nothing here posts a journal entry, and that is not an omission.
//  Paid leave costs nothing extra — the salary is accrued by Payroll
//  whether the employee is at their desk or not. Unpaid leave is a
//  REDUCTION of what was earned, and migration 000227 already built
//  the mechanism for that: a DeductionReason with behavior
//  `earned_reduction`, applied by PayrollRunService when the month is
//  run. That migration's header anticipated this module by name:
//  "Once full HR eventually adds Leave Management, that module
//  becomes a new SOURCE writing the same generic line shape."
//
//  This service therefore EXPOSES unpaid leave through
//  unpaidLeaveDaysFor() and stops there. It does not reach into
//  PayrollRunService to create the deduction, for two reasons: that
//  would mean editing an existing accounting path, which this work is
//  not authorised to do; and a deduction raised behind the payroll
//  operator's back is worse than one they attach themselves. The
//  seam is documented rather than crossed.
//
//  ══ THE FOUR RULES THAT ACTUALLY MATTER ══
//
//  1. Dates must make sense — from_date <= to_date, and the day count
//     is inclusive of both ends. 5 March to 7 March is three days.
//
//  2. No employee may hold two live requests over the same day.
//     "Live" means submitted or approved (LeaveRequest::LIVE_STATUSES)
//     — a draft is only an intention, and rejected or cancelled
//     requests release their dates. Enforced here rather than by a
//     database constraint because overlap is a range predicate and
//     MySQL has no exclusion constraint to express it.
//
//  3. A balance-tracked type may not be overdrawn. Entitlement is
//     annual; the year used is the company's own FISCAL year covering
//     from_date, not the calendar year, because fiscal_year_start_month
//     is configurable per company and every other periodic concept in
//     this system keys off fiscal years. Recorded as a decision to
//     confirm with the business owner, not a settled fact. Types with
//     a null entitlement are not tracked at all.
//
//  4. Only a draft is editable. Approval is the binding moment, so
//     the balance and overlap checks run AGAIN at approve() — another
//     request may have been approved in the meantime, and the check
//     that was true at submission is not necessarily true now.
//
//  @layer    Service
//  @depends  LeaveRequest, LeaveType, Partner, EmployeePayrollProfile,
//            FiscalYear, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Models\AuditLog;
use App\Models\EmployeePayrollProfile;
use App\Models\FiscalYear;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Partner;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class LeaveRequestService
{
    // ── Read ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return LeaveRequest::with(['partner:id,name_en,name_ar', 'leaveType:id,name_en,name_ar,is_paid'])
            ->where('company_id', $companyId)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q
                ->where(fn ($q) => $q
                    ->where('request_code', 'like', "%{$s}%")
                    ->orWhereHas('partner', fn ($p) => $p->where('name_en', 'like', "%{$s}%"))
                )
            )
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['partner_id'] ?? null, fn ($q, $v) => $q->where('partner_id', $v))
            ->when($filters['leave_type_id'] ?? null, fn ($q, $v) => $q->where('leave_type_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->where('to_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->where('from_date', '<=', $v))
            ->orderByDesc('from_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create / update / delete — draft only ─────────────────────

    public function create(int $companyId, array $data, User $actor): LeaveRequest
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $partner   = $this->assertEmployee($companyId, (int) $data['partner_id']);
            $leaveType = $this->assertLeaveType($companyId, (int) $data['leave_type_id']);

            $days = $this->calculateDays($data['from_date'], $data['to_date'], $companyId, $leaveType);

            $this->assertNoOverlap($companyId, $partner->id, $data['from_date'], $data['to_date'], null);
            $this->assertWithinEntitlement($companyId, $partner->id, $leaveType, $data['from_date'], $days, null);

            $request = LeaveRequest::create([
                'company_id'                  => $companyId,
                'request_code'                => $this->generateRequestCode($companyId, $data['from_date'] ?? null),
                'partner_id'                  => $partner->id,
                'employee_payroll_profile_id' => $this->resolveProfileId($companyId, $partner->id),
                'leave_type_id'               => $leaveType->id,
                'from_date'                   => $data['from_date'],
                'to_date'                     => $data['to_date'],
                'days_count'                  => $days,
                'reason'                      => $data['reason'] ?? null,
                'status'                      => LeaveRequest::STATUS_DRAFT,
                'created_by'                  => $actor->id,
            ]);

            AuditLog::record($actor, 'leave_request.created', $request, null, $request->toArray());

            return $request->fresh(['partner', 'leaveType']);
        });
    }

    public function update(LeaveRequest $request, array $data, User $actor): LeaveRequest
    {
        if (! $request->isEditable()) {
            throw new \DomainException(
                "Leave request {$request->request_code} can no longer be edited — it is {$request->status}. "
                .'Cancel it and raise a new one.'
            );
        }

        return DB::transaction(function () use ($request, $data, $actor) {
            $before = $request->toArray();

            $partner   = $this->assertEmployee($request->company_id, (int) $data['partner_id']);
            $leaveType = $this->assertLeaveType($request->company_id, (int) $data['leave_type_id']);

            $days = $this->calculateDays($data['from_date'], $data['to_date'], $request->company_id, $leaveType);

            $this->assertNoOverlap($request->company_id, $partner->id, $data['from_date'], $data['to_date'], $request->id);
            $this->assertWithinEntitlement($request->company_id, $partner->id, $leaveType, $data['from_date'], $days, $request->id);

            $request->update([
                'partner_id'                  => $partner->id,
                'employee_payroll_profile_id' => $this->resolveProfileId($request->company_id, $partner->id),
                'leave_type_id'               => $leaveType->id,
                'from_date'                   => $data['from_date'],
                'to_date'                     => $data['to_date'],
                'days_count'                  => $days,
                'reason'                      => $data['reason'] ?? null,
            ]);

            AuditLog::record($actor, 'leave_request.updated', $request, $before, $request->fresh()->toArray());

            return $request->fresh(['partner', 'leaveType']);
        });
    }

    public function delete(LeaveRequest $request, User $actor): void
    {
        if (! $request->isEditable()) {
            throw new \DomainException(
                "Leave request {$request->request_code} can no longer be deleted — it is {$request->status}."
            );
        }

        AuditLog::record($actor, 'leave_request.deleted', $request, $request->toArray(), null);

        $request->delete();
    }

    // ── Transitions ───────────────────────────────────────────────

    public function submit(LeaveRequest $request, User $actor): LeaveRequest
    {
        $this->assertStatus($request, [LeaveRequest::STATUS_DRAFT], 'submitted');

        return DB::transaction(function () use ($request, $actor) {
            $before = $request->toArray();

            // Re-checked here as well as at create: a draft can sit
            // for weeks while other requests are approved around it.
            $this->assertNoOverlap($request->company_id, $request->partner_id,
                $request->from_date->toDateString(), $request->to_date->toDateString(), $request->id);

            $request->update([
                'status'       => LeaveRequest::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
            ]);

            AuditLog::record($actor, 'leave_request.submitted', $request, $before, $request->fresh()->toArray());

            return $request->fresh(['partner', 'leaveType']);
        });
    }

    /**
     * The binding moment. Both the overlap and the entitlement check
     * run again here, because approving is what actually consumes the
     * days and another request may have been approved since this one
     * was submitted.
     */
    public function approve(LeaveRequest $request, User $actor): LeaveRequest
    {
        $this->assertStatus($request, [LeaveRequest::STATUS_SUBMITTED], 'approved');

        return DB::transaction(function () use ($request, $actor) {
            $before = $request->toArray();

            $leaveType = $this->assertLeaveType($request->company_id, $request->leave_type_id);

            $this->assertNoOverlap($request->company_id, $request->partner_id,
                $request->from_date->toDateString(), $request->to_date->toDateString(), $request->id);

            $this->assertWithinEntitlement($request->company_id, $request->partner_id, $leaveType,
                $request->from_date->toDateString(), (string) $request->days_count, $request->id);

            $request->update([
                'status'      => LeaveRequest::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ]);

            AuditLog::record($actor, 'leave_request.approved', $request, $before, $request->fresh()->toArray());

            return $request->fresh(['partner', 'leaveType']);
        });
    }

    public function reject(LeaveRequest $request, User $actor, ?string $reason = null): LeaveRequest
    {
        $this->assertStatus($request, [LeaveRequest::STATUS_SUBMITTED], 'rejected');

        return DB::transaction(function () use ($request, $actor, $reason) {
            $before = $request->toArray();

            $request->update([
                'status'           => LeaveRequest::STATUS_REJECTED,
                'rejected_at'      => now(),
                'rejected_by'      => $actor->id,
                'rejection_reason' => $reason,
            ]);

            AuditLog::record($actor, 'leave_request.rejected', $request, $before, $request->fresh()->toArray());

            return $request->fresh(['partner', 'leaveType']);
        });
    }

    /**
     * Cancellation is available from draft, submitted OR approved —
     * plans change, and an approved holiday that is not taken must
     * release its days back to the employee's balance. Because the
     * balance is computed from approved requests rather than stored,
     * moving the status is all it takes.
     */
    public function cancel(LeaveRequest $request, User $actor): LeaveRequest
    {
        $this->assertStatus($request, [
            LeaveRequest::STATUS_DRAFT,
            LeaveRequest::STATUS_SUBMITTED,
            LeaveRequest::STATUS_APPROVED,
        ], 'cancelled');

        return DB::transaction(function () use ($request, $actor) {
            $before = $request->toArray();

            $request->update([
                'status'       => LeaveRequest::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ]);

            AuditLog::record($actor, 'leave_request.cancelled', $request, $before, $request->fresh()->toArray());

            return $request->fresh(['partner', 'leaveType']);
        });
    }

    // ── Balances ──────────────────────────────────────────────────

    /**
     * One employee's standing on one leave type, for the fiscal year
     * containing $onDate.
     *
     * Computed from approved requests every time rather than stored,
     * so cancelling an approved request releases its days with no
     * extra bookkeeping and no chance of a cached balance drifting
     * away from the requests behind it.
     *
     * @return array{entitlement: ?string, taken: string, remaining: ?string, tracked: bool}
     */
    public function balanceFor(int $companyId, int $partnerId, LeaveType $leaveType, string $onDate): array
    {
        [$yearStart, $yearEnd] = $this->fiscalYearBounds($companyId, $onDate);

        $taken = $this->approvedDaysInRange($companyId, $partnerId, $leaveType->id, $yearStart, $yearEnd, null);

        if (! $leaveType->isBalanceTracked()) {
            return [
                'entitlement' => null,
                'taken'       => $taken,
                'remaining'   => null,
                'tracked'     => false,
            ];
        }

        $entitlement = bcadd(
            $this->entitlementFor($companyId, $partnerId, $leaveType, $yearStart),
            $this->carriedInFor($companyId, $partnerId, $leaveType, $yearStart),
            2
        );

        return [
            'entitlement' => $entitlement,
            'taken'       => $taken,
            'remaining'   => bcsub($entitlement, $taken, 2),
            'tracked'     => true,
        ];
    }

    /**
     * Every leave type's balance for one employee — what the employee
     * screen shows.
     */
    public function balanceSummary(int $companyId, int $partnerId, ?string $onDate = null): array
    {
        $onDate = $onDate ?? now()->toDateString();

        return LeaveType::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->active()
            ->orderBy('name_en')
            ->get()
            ->map(fn (LeaveType $type) => [
                'leave_type_id' => $type->id,
                'name_en'       => $type->name_en,
                'name_ar'       => $type->name_ar,
                'is_paid'       => $type->is_paid,
            ] + $this->balanceFor($companyId, $partnerId, $type, $onDate))
            ->all();
    }

    /**
     * THE PAYROLL SEAM.
     *
     * Approved days of UNPAID leave for one employee whose request
     * falls inside [$from, $to]. This is the figure Payroll needs in
     * order to raise an `earned_reduction` deduction, and it is the
     * whole of this module's contribution to money.
     *
     * Nothing calls this yet. It is deliberately a pull, not a push:
     * PayrollRunService is existing accounting code and this work does
     * not modify it. Wiring it up is a small, separate, clearly
     * scoped change once the business owner has confirmed how a part
     * month of unpaid leave should be valued — a question this
     * repository does not currently answer anywhere.
     *
     * Requests are counted by their start date, so a leave period
     * spanning a month boundary lands wholly in the month it began.
     * That is the simplest defensible rule and it is flagged as a
     * decision to confirm, not presented as the obvious answer.
     */
    public function unpaidLeaveDaysFor(int $companyId, int $partnerId, string $from, string $to): string
    {
        // M-35: a request spanning a month boundary is split — each month
        // is charged only for the unpaid days that fall inside it (the
        // old rule put the whole request in the month it began).
        $requests = LeaveRequest::withoutGlobalScopes()
            ->where('leave_requests.company_id', $companyId)
            ->where('leave_requests.partner_id', $partnerId)
            ->where('leave_requests.status', LeaveRequest::STATUS_APPROVED)
            ->where('leave_requests.from_date', '<=', $to)
            ->where('leave_requests.to_date', '>=', $from)
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->where('leave_types.is_paid', false)
            ->get(['leave_requests.from_date', 'leave_requests.to_date', 'leave_requests.leave_type_id']);

        $total = '0';
        foreach ($requests as $r) {
            $overlapFrom = max(substr((string) $r->from_date, 0, 10), $from);
            $overlapTo   = min(substr((string) $r->to_date, 0, 10), $to);
            if ($overlapFrom > $overlapTo) {
                continue;
            }
            $type  = LeaveType::withoutGlobalScopes()->find($r->leave_type_id);
            $total = bcadd($total, $this->calculateDays($overlapFrom, $overlapTo, $companyId, $type), 2);
        }

        return Money::round($total, 2);
    }

    // ── Rules ─────────────────────────────────────────────────────

    /**
     * Inclusive days. 5 March to 7 March is 3, not 2.
     *
     * M-35: for a leave type with "skip non-working days" switched on
     * (annual leave under the Labour Law), the company's weekly rest
     * days (default Friday + Saturday) and its public holidays inside
     * the period are NOT counted. Other types count calendar days.
     */
    public function calculateDays(string $from, string $to, ?int $companyId = null, ?LeaveType $leaveType = null): string
    {
        $start = Carbon::parse($from)->startOfDay();
        $end   = Carbon::parse($to)->startOfDay();

        if ($end->lt($start)) {
            throw new \DomainException('The end date of a leave request cannot be before its start date.');
        }

        if (! $leaveType?->skip_non_working_days || ! $companyId) {
            return number_format((int) abs($start->diffInDays($end)) + 1, 2, '.', '');
        }

        [$weekend, $holidays] = $this->nonWorkingCalendar($companyId, $start->toDateString(), $end->toDateString());

        $count = 0;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if (in_array($d->dayOfWeekIso, $weekend, true) || isset($holidays[$d->toDateString()])) {
                continue;
            }
            $count++;
        }

        return number_format($count, 2, '.', '');
    }

    /** @return array{0: int[], 1: array<string,bool>} weekly rest ISO weekdays, holiday dates */
    private function nonWorkingCalendar(int $companyId, string $from, string $to): array
    {
        $weekendCsv = (string) (\Illuminate\Support\Facades\DB::table('companies')->where('id', $companyId)->value('weekend_days') ?: '5,6');
        $weekend    = array_values(array_filter(array_map('intval', explode(',', $weekendCsv))));

        $holidays = \App\Models\CompanyHoliday::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereBetween('holiday_date', [$from, $to])
            ->pluck('holiday_date')
            ->mapWithKeys(fn ($d) => [substr((string) $d, 0, 10) => true])
            ->all();

        return [$weekend, $holidays];
    }

    /**
     * M-35: the entitlement for one employee in one fiscal year.
     * The senior figure applies once the employee has served the
     * required years (counted from the first salary record = hire
     * date) or reached the age on the type, by the START of the year.
     */
    public function entitlementFor(int $companyId, int $partnerId, LeaveType $leaveType, string $yearStart): string
    {
        $base = (string) $leaveType->annual_entitlement_days;
        if ($leaveType->senior_entitlement_days === null) {
            return $base;
        }

        $profile = EmployeePayrollProfile::withoutGlobalScopes()
            ->where('company_id', $companyId)->where('partner_id', $partnerId)->first();
        if (! $profile) {
            return $base;
        }

        $asOf = Carbon::parse($yearStart);
        $hire = $profile->salaryRevisions()->orderBy('effective_date')->value('effective_date');
        $years = $hire ? Carbon::parse($hire)->diffInYears($asOf, false) : 0;
        $age   = $profile->date_of_birth ? Carbon::parse($profile->date_of_birth)->diffInYears($asOf, false) : 0;

        if ($years >= (int) $leaveType->senior_after_years || ($profile->date_of_birth && $age >= (int) $leaveType->senior_age)) {
            return bccomp((string) $leaveType->senior_entitlement_days, $base, 2) > 0
                ? (string) $leaveType->senior_entitlement_days : $base;
        }

        return $base;
    }

    /** M-35: unused days carried in from the previous fiscal year, capped by the type's maximum. */
    public function carriedInFor(int $companyId, int $partnerId, LeaveType $leaveType, string $yearStart): string
    {
        if ($leaveType->carry_forward_max_days === null || ! $leaveType->isBalanceTracked()) {
            return '0.00';
        }

        $prev = FiscalYear::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('end_date', '<', $yearStart)
            ->orderByDesc('end_date')
            ->first();
        if (! $prev) {
            return '0.00';
        }

        $prevStart = $prev->start_date->toDateString();
        $prevEnd   = $prev->end_date->toDateString();
        $prevEntitlement = $this->entitlementFor($companyId, $partnerId, $leaveType, $prevStart);
        $prevTaken = $this->approvedDaysInRange($companyId, $partnerId, $leaveType->id, $prevStart, $prevEnd, null);
        $unused = bcsub($prevEntitlement, $prevTaken, 2);

        if (bccomp($unused, '0', 2) <= 0) {
            return '0.00';
        }
        $cap = (string) $leaveType->carry_forward_max_days;

        return bccomp($unused, $cap, 2) > 0 ? bcadd($cap, '0', 2) : $unused;
    }

    private function assertNoOverlap(int $companyId, int $partnerId, string $from, string $to, ?int $exceptId): void
    {
        $clash = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->live()
            ->overlapping($from, $to)
            ->when($exceptId, fn ($q, $id) => $q->where('id', '<>', $id))
            ->first();

        if ($clash) {
            throw new \DomainException(
                "This employee already has leave booked between {$clash->from_date->toDateString()} and "
                ."{$clash->to_date->toDateString()} ({$clash->request_code}, {$clash->status}). "
                .'Two leave requests cannot cover the same day.'
            );
        }
    }

    private function assertWithinEntitlement(
        int $companyId,
        int $partnerId,
        LeaveType $leaveType,
        string $fromDate,
        string $days,
        ?int $exceptId
    ): void {
        if (! $leaveType->isBalanceTracked()) {
            return;
        }

        [$yearStart, $yearEnd] = $this->fiscalYearBounds($companyId, $fromDate);

        $alreadyTaken = $this->approvedDaysInRange($companyId, $partnerId, $leaveType->id, $yearStart, $yearEnd, $exceptId);
        $entitlement  = bcadd(
            $this->entitlementFor($companyId, $partnerId, $leaveType, $yearStart),
            $this->carriedInFor($companyId, $partnerId, $leaveType, $yearStart),
            2
        );
        $wouldTotal   = bcadd($alreadyTaken, $days, 2);

        if (bccomp($wouldTotal, $entitlement, 2) > 0) {
            $remaining = bcsub($entitlement, $alreadyTaken, 2);

            throw new \DomainException(
                "This request is for {$days} day(s) of {$leaveType->name_en}, but only {$remaining} day(s) remain "
                ."of the {$entitlement}-day entitlement for the fiscal year starting {$yearStart}."
            );
        }
    }

    private function approvedDaysInRange(
        int $companyId,
        int $partnerId,
        int $leaveTypeId,
        string $yearStart,
        string $yearEnd,
        ?int $exceptId
    ): string {
        $days = LeaveRequest::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('leave_type_id', $leaveTypeId)
            ->approved()
            ->whereBetween('from_date', [$yearStart, $yearEnd])
            ->when($exceptId, fn ($q, $id) => $q->where('id', '<>', $id))
            ->sum('days_count');

        return Money::round($days, 2);
    }

    /**
     * The company's own fiscal year containing $date. Throws rather
     * than falling back to a calendar year — the same treatment
     * JobOrderService::resolveFiscalYear() gives a missing year,
     * because silently using a different year than the rest of the
     * system would produce a balance nobody can reconcile.
     *
     * @return array{0: string, 1: string}
     */
    private function fiscalYearBounds(int $companyId, string $date): array
    {
        $fy = FiscalYear::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();

        if (! $fy) {
            throw new \DomainException(
                "No fiscal year covers {$date} — leave entitlement is tracked per fiscal year, "
                .'so one must exist before leave can be booked on that date.'
            );
        }

        return [$fy->start_date->toDateString(), $fy->end_date->toDateString()];
    }

    private function assertStatus(LeaveRequest $request, array $allowed, string $target): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw new \DomainException(
                "Leave request {$request->request_code} cannot be {$target} from status '{$request->status}'. "
                .'Allowed from: '.implode(', ', $allowed).'.'
            );
        }
    }

    private function assertEmployee(int $companyId, int $partnerId): Partner
    {
        $partner = Partner::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('id', $partnerId)
            ->first();

        if (! $partner) {
            throw new \DomainException('The selected employee does not belong to this company.');
        }

        $types = $partner->types ?? [];
        if (! in_array(Partner::TYPE_EMPLOYEE, is_array($types) ? $types : [], true)) {
            throw new \DomainException("\"{$partner->name_en}\" is not registered as an employee — leave can only be booked for employees.");
        }

        return $partner;
    }

    private function assertLeaveType(int $companyId, int $leaveTypeId): LeaveType
    {
        $type = LeaveType::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('id', $leaveTypeId)
            ->first();

        if (! $type) {
            throw new \DomainException('The selected leave type does not belong to this company.');
        }

        if (! $type->is_active) {
            throw new \DomainException("Leave type \"{$type->name_en}\" is no longer active.");
        }

        return $type;
    }

    private function resolveProfileId(int $companyId, int $partnerId): ?int
    {
        return EmployeePayrollProfile::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->value('id');
    }

    private function generateRequestCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\LeaveRequest::class, 'request_code', 'LV', $date);
    }
}
