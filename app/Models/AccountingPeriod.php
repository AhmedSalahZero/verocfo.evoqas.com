<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  AccountingPeriod
//
//  One of 12 monthly periods within a FiscalYear.
//  Period numbers run 1–12 regardless of fiscal year start month.
//
//  Status lifecycle:
//    open   → transactions can be posted, edited, reversed
//    closed → no new postings, no edits, no reversals.
//             Cost center tags permanently locked.
//             Can be reopened if fiscal year is still open.
//
//  Closing rule: periods must be closed in sequence.
//  Period N cannot be closed if Period N-1 is still open.
//  Enforced in FiscalYearService.
//
//  Reopening: allowed only if the parent fiscal year is open.
//  A reopened period sets was_reopened = true for audit trail.
//
//  canClose(): true if this is the earliest open period in its
//  fiscal year (no prior open periods exist).
//
//  canReopen(): true if fiscal year is open and period is closed.
//
//  @layer    Model
//  @depends  BelongsToCompany, FiscalYear, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class AccountingPeriod extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    // ── Status Constants ──────────────────────────────────────────

    const STATUS_OPEN   = 'open';
    const STATUS_CLOSED = 'closed';

    // ── Fillable ──────────────────────────────────────────────────

    protected $fillable = [
        'company_id',
        'fiscal_year_id',
        'name',
        'start_date',
        'end_date',
        'period_number',
        'status',
        'closed_at',
        'closed_by',
        'was_reopened',
        'reopened_at',
        'reopened_by',
        'reopen_reason', // Audit H4
    ];

    // ── Casts ─────────────────────────────────────────────────────

    protected $casts = [
        'start_date'    => 'date',
        'end_date'      => 'date',
        'closed_at'     => 'datetime',
        'reopened_at'   => 'datetime',
        'was_reopened'  => 'boolean',
        'period_number' => 'integer',
    ];

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CLOSED);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isOpen(): bool   { return $this->status === self::STATUS_OPEN; }
    public function isClosed(): bool { return $this->status === self::STATUS_CLOSED; }

    /**
     * Period can be closed if:
     * 1. It is currently open
     * 2. No earlier period in the same fiscal year is still open
     */
    public function canClose(): bool
    {
        if ($this->isClosed()) return false;

        $earlierOpenExists = static::where('company_id', $this->company_id)
            ->where('fiscal_year_id', $this->fiscal_year_id)
            ->where('period_number', '<', $this->period_number)
            ->where('status', self::STATUS_OPEN)
            ->exists();

        return ! $earlierOpenExists;
    }

    /**
     * Period can be reopened if:
     * 1. It is currently closed
     * 2. Its fiscal year is still open
     * 3. No later period in the same fiscal year is closed
     *    (months close in order, so only the most recently closed
     *    month can be reopened — newest first). A later month that is
     *    still OPEN is the normal case and never blocks (Audit M6).
     */
    public function canReopen(): bool
    {
        if ($this->isOpen()) return false;
        if (! $this->fiscalYear->isOpen()) return false;

        $laterClosedExists = static::where('company_id', $this->company_id)
            ->where('fiscal_year_id', $this->fiscal_year_id)
            ->where('period_number', '>', $this->period_number)
            ->where('status', self::STATUS_CLOSED)
            ->exists();

        return ! $laterClosedExists;
    }

    /**
     * Returns the month name for display.
     * e.g. "January 2026"
     */
    public function getMonthLabelAttribute(): string
    {
        return $this->start_date->format('F Y');
    }

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
