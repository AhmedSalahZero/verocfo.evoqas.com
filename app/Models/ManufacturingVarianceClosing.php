<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ManufacturingVarianceClosing
//
//  One row per (company, period) — the header record for the monthly
//  Manufacturing Variance Closing action (Manufacturing Variance
//  Closing Design v1.0, Section 1/Step 3, piece 3). Plays the same
//  role PayrollRun plays for payroll: created the moment
//  VarianceClosingService::post() actually posts, and its own unique
//  constraint is what stops a period from being closed twice.
//
//  proposal_snapshot holds the exact calculation that was posted, as
//  JSON — an audit trail independent of whatever the underlying
//  orders/products look like later.
//
//  @layer    Model
//  @depends  BelongsToCompany, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManufacturingVarianceClosing extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'period_year', 'period_month',
        'journal_entry_id', 'proposal_snapshot', 'posted_by', 'posted_at',
        'override_reason', 'active_key', 'reversed_at', 'reversed_by', 'reversal_reason', 'reversal_journal_entry_id',
    ];

    protected $casts = [
        'period_year'       => 'integer',
        'period_month'      => 'integer',
        'proposal_snapshot' => 'array',
        'posted_at'         => 'datetime',
        'reversed_at'       => 'datetime',
    ];

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function periodLabel(): string
    {
        return \Carbon\Carbon::create($this->period_year, $this->period_month, 1)->format('F Y');
    }
}
