<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  RevenueScheduleLine
//
//  Audit H18 — one month's slice of a RevenueSchedule: released on its
//  recognition_date by Dr 236 Deferred Revenue / Cr revenue. See
//  migration 000304.
//
//  @layer    Model
//  @depends  BelongsToCompany, RevenueSchedule, JournalEntry
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueScheduleLine extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_PENDING   = 'pending';
    const STATUS_RELEASED  = 'released';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'revenue_schedule_id', 'period_month', 'recognition_date', 'days', 'amount',
        'status', 'journal_entry_id', 'released_at',
    ];

    protected $casts = [
        'period_month'     => 'date',
        'recognition_date' => 'date',
        'amount'           => 'decimal:2',
        'released_at'      => 'datetime',
    ];

    public function schedule(): BelongsTo     { return $this->belongsTo(RevenueSchedule::class, 'revenue_schedule_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
