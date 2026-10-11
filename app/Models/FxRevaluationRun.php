<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  FxRevaluationRun
//
//  One month-end foreign-currency revaluation (Audit H12, IAS 21).
//  See migration 000300 for the design. Written exclusively through
//  FxRevaluationService — a run is never edited; it is either posted
//  or cancelled (both of its journal entries reversed).
//
//  @layer    Model
//  @depends  BelongsToCompany, FxRevaluationLine, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FxRevaluationRun extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'run_code', 'revaluation_date', 'reversal_date', 'status',
        'closing_rates', 'total_gain', 'total_loss', 'net_amount',
        'journal_entry_id', 'reversal_journal_entry_id', 'notes',
        'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'revaluation_date' => 'date',
        'reversal_date'    => 'date',
        'closing_rates'    => 'array',
        'total_gain'       => 'decimal:2',
        'total_loss'       => 'decimal:2',
        'net_amount'       => 'decimal:2',
        'cancelled_at'     => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(FxRevaluationLine::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }
}
