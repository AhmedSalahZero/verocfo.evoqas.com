<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PayrollRun
//
//  One monthly payroll run — see migration 000228 header for the
//  full table design. draft while lines/deductions are being
//  assembled; posted once every category's journal entry exists
//  (payrollRunPostings).
//
//  @layer    Model
//  @depends  BelongsToCompany, PayrollRunLine, PayrollRunPosting, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use BelongsToCompany;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'period_year', 'period_month', 'run_type', 'sequence', 'label', 'run_date',
        'status', 'created_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'period_year'  => 'integer',
        'period_month' => 'integer',
        'run_date'     => 'date',
        'posted_at'    => 'datetime',
    ];

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollRunLine::class);
    }

    public function postings(): HasMany
    {
        return $this->hasMany(PayrollRunPosting::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function periodLabel(): string
    {
        $label = \Carbon\Carbon::createFromDate($this->period_year, $this->period_month, 1)->format('F Y');

        // M-36: extra runs in the same month are told apart in every narration.
        return ($this->run_type ?? 'regular') === 'regular'
            ? $label
            : $label.' ('.($this->run_type === 'bonus' ? 'Bonus' : 'Final settlement').' #'.($this->sequence ?? 1).')';
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PayrollRunPayment::class);
    }

    public function isRegular(): bool
    {
        return ($this->run_type ?? 'regular') === 'regular';
    }
}
