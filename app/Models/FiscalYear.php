<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  FiscalYear
//
//  Represents one fiscal year for a company.
//  Each fiscal year has exactly 12 AccountingPeriods.
//
//  Status lifecycle:
//    open   → active fiscal year, periods can be opened/closed
//    closed → all periods closed, year-end entry posted,
//             no further changes allowed
//
//  Only ONE fiscal year per company can have status = 'open'
//  at any time. This is enforced in FiscalYearService.
//
//  Fiscal years cannot overlap — start/end dates must be
//  contiguous with no gaps between years.
//
//  canClose(): true only when all 12 periods are closed.
//  canCreateNext(): true only when this year is closed and
//  the next year's dates don't overlap with existing years.
//
//  @layer    Model
//  @depends  BelongsToCompany, AccountingPeriod
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\SerializesDateOnly;

class FiscalYear extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    // ── Status Constants ──────────────────────────────────────────

    const STATUS_OPEN   = 'open';
    const STATUS_CLOSED = 'closed';

    // ── Fillable ──────────────────────────────────────────────────

    protected $fillable = [
        'company_id',
        'name',
        'start_date',
        'end_date',
        'status',
        'closed_at',
        'closed_by',
    ];

    // ── Casts ─────────────────────────────────────────────────────

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'closed_at'  => 'datetime',
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
     * A fiscal year can be closed only when ALL its periods are closed.
     */
    public function canClose(): bool
    {
        if ($this->isClosed()) return false;
        return $this->periods()->where('status', AccountingPeriod::STATUS_OPEN)->count() === 0;
    }

    /**
     * Returns the first open period in this fiscal year.
     * Periods must be closed in sequence — only the earliest
     * open period can be closed at any time.
     */
    public function firstOpenPeriod(): ?AccountingPeriod
    {
        return $this->periods()
            ->where('status', AccountingPeriod::STATUS_OPEN)
            ->orderBy('period_number')
            ->first();
    }

    /**
     * Returns stats for display: open count, closed count.
     */
    public function getPeriodStatsAttribute(): array
    {
        $periods = $this->periods;
        return [
            'total'  => $periods->count(),
            'open'   => $periods->where('status', AccountingPeriod::STATUS_OPEN)->count(),
            'closed' => $periods->where('status', AccountingPeriod::STATUS_CLOSED)->count(),
        ];
    }

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class)->orderBy('period_number');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
