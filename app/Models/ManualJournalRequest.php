<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ManualJournalRequest
//
//  A manual journal entry WAITING FOR APPROVAL (Audit H3, Sept 2026 —
//  confirmed with Mahmoud: every manual journal needs a second
//  person). Created by JournalEntryController::store(); the real
//  JournalEntry is only posted when a DIFFERENT user with the Approve
//  right approves it (ManualJournalApprovalService). Kept out of
//  journal_entries on purpose — every report sums that table.
//
//  status: pending → approved (journal_entry_id set) | rejected
//
//  @layer    Model
//  @depends  BelongsToCompany, User, JournalEntry
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualJournalRequest extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'company_id', 'entry_date', 'narration', 'debit_lines', 'credit_lines', 'total_amount',
        'status', 'requested_by', 'decided_by', 'decided_at', 'rejection_reason', 'journal_entry_id',
    ];

    protected $casts = [
        'entry_date'   => 'date',
        'debit_lines'  => 'array',
        'credit_lines' => 'array',
        'total_amount' => 'decimal:2',
        'decided_at'   => 'datetime',
    ];

    public function requestedBy(): BelongsTo  { return $this->belongsTo(User::class, 'requested_by'); }
    public function decidedBy(): BelongsTo    { return $this->belongsTo(User::class, 'decided_by'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
}
