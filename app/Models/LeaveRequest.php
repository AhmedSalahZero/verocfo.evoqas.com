<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  LeaveRequest
//
//  One employee's request to be away between two dates.
//
//  NO ACCOUNTING, ever — not even for unpaid leave. See migration
//  000280's header for why that is deliberate rather than missing.
//
//  STATUS is the whole model. Only `draft` is editable; only
//  `approved` consumes entitlement and is visible to Payroll; only
//  `submitted` and `approved` block an overlapping request. Every
//  transition is enforced in LeaveRequestService, never here — this
//  class only names the states and answers questions about them.
//
//      draft ──submit──▶ submitted ──approve──▶ approved
//        │                   │                     │
//        │                   └───reject───▶ rejected
//        └──cancel──▶ cancelled ◀────cancel────────┘
//
//  days_count is INCLUSIVE CALENDAR DAYS, computed and stored by the
//  service rather than derived on read: entitlement arithmetic has to
//  stay stable even if the definition of a day changes later. There
//  is no working-day calendar in this repository to subtract weekends
//  or holidays from.
//
//  @layer    Model
//  @depends  BelongsToCompany, SerializesDateOnly, Partner, LeaveType,
//            EmployeePayrollProfile, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED  = 'approved';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    const ALL_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /**
     * The statuses that hold a claim on the employee's calendar. A
     * draft is only an intention, and rejected/cancelled requests
     * release their days — so neither blocks a new request over the
     * same dates. Used by the overlap check and by every balance
     * calculation.
     */
    const LIVE_STATUSES = [
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
    ];

    protected $fillable = [
        'company_id', 'request_code', 'partner_id', 'employee_payroll_profile_id',
        'leave_type_id', 'from_date', 'to_date', 'days_count', 'reason', 'status',
        'submitted_at', 'submitted_by', 'approved_at', 'approved_by',
        'rejected_at', 'rejected_by', 'rejection_reason',
        'cancelled_at', 'cancelled_by', 'created_by',
    ];

    protected $casts = [
        'from_date'    => 'date',
        'to_date'      => 'date',
        'days_count'   => 'decimal:2',
        'submitted_at' => 'datetime',
        'approved_at'  => 'datetime',
        'rejected_at'  => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function employeePayrollProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeePayrollProfile::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ────────────────────────────────────────────────────

    /** Requests that hold the employee's calendar — see LIVE_STATUSES. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /** Requests touching any part of [$from, $to] — inclusive both ends. */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->where('from_date', '<=', $to)
            ->where('to_date', '>=', $from);
    }

    // ── State questions ───────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** Only a draft may be edited or deleted. */
    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    /**
     * Rejected and cancelled requests are terminal. A refused request
     * is not re-opened — the employee raises a new one, so the
     * refusal stays on the record.
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_REJECTED, self::STATUS_CANCELLED], true);
    }
}
