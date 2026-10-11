<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  EosbProvisionRun
//
//  One monthly EOSB Provision run — see migration 000235 header for
//  the full design (a separate, dedicated run from Payroll Run;
//  confirmed with Mahmoud, September 2026). draft while lines are
//  assembled; posted once the single journal entry
//  (Dr 542 / Cr 241) exists.
//
//  @layer    Model
//  @depends  BelongsToCompany, EosbProvisionRunLine, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EosbProvisionRun extends Model
{
    use BelongsToCompany;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'period_year', 'period_month', 'run_date',
        'status', 'journal_entry_id', 'created_by', 'posted_by', 'posted_at',
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
        return $this->hasMany(EosbProvisionRunLine::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
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
        return \Carbon\Carbon::createFromDate($this->period_year, $this->period_month, 1)->format('F Y');
    }
}
