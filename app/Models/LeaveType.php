<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  LeaveType
//
//  One kind of leave a company grants — Annual, Sick, Unpaid, and so
//  on. Fully per-company; nothing is seeded, because no two companies
//  agree on what leave they offer.
//
//  Two fields carry logic, the rest is labelling — see migration
//  000279's header for the full reasoning:
//
//    is_paid = false  → LeaveRequestService counts approved days of
//                       this type as unpaid leave, which is what
//                       Payroll may raise an `earned_reduction`
//                       deduction for. Paid leave never reaches money.
//
//    annual_entitlement_days = null → not balance-tracked at all.
//                       A number → LeaveRequestService enforces it
//                       against the fiscal year the leave starts in.
//
//  deductionReason is the hand-off to Payroll for unpaid types.
//  Nothing in this module writes a payroll line; see
//  LeaveRequestService::unpaidLeaveDaysFor() for why that boundary
//  exists and where it is expected to be crossed later.
//
//  @layer    Model
//  @depends  BelongsToCompany, DeductionReason, LeaveRequest, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'name_en', 'name_ar', 'is_paid', 'skip_non_working_days',
        'annual_entitlement_days', 'senior_entitlement_days', 'senior_after_years', 'senior_age',
        'carry_forward_max_days', 'deduction_reason_id', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_paid'                 => 'boolean',
        'skip_non_working_days'   => 'boolean',
        'senior_entitlement_days' => 'decimal:2',
        'carry_forward_max_days'  => 'decimal:2',
        'is_active'               => 'boolean',
        'annual_entitlement_days' => 'decimal:2',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function deductionReason(): BelongsTo
    {
        return $this->belongsTo(DeductionReason::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Unpaid leave is the only kind Payroll ever needs to know about. */
    public function isUnpaid(): bool
    {
        return ! $this->is_paid;
    }

    /**
     * A type with no entitlement figure is approved case by case with
     * no allowance to consume — sick leave in many companies, and
     * unpaid leave by definition.
     */
    public function isBalanceTracked(): bool
    {
        return $this->annual_entitlement_days !== null;
    }
}
