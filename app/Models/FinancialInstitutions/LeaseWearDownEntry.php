<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LeaseWearDownEntry
//
//  One row per period's automatic asset wear-down posting (ROU
//  amortization for Operating Lease Treatment B, or the stand-in
//  straight-line depreciation for Capital Lease — see
//  LeaseAmortizationService header). Posted automatically since it
//  never touches cash, but reversible by the user via 'status' —
//  never edited directly (Building Bible pattern: reverse, then
//  repost the correct version).
//
//  @layer    Model
//  @depends  BelongsToCompany, LeasingContract, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\JournalEntry;
use App\Models\Traits\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class LeaseWearDownEntry extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $fillable = [
        'company_id', 'leasing_contract_id', 'period_number', 'entry_date', 'amount',
        'journal_entry_id', 'reversal_journal_entry_id', 'status',
        'posted_by', 'reversed_by', 'reversed_at',
    ];

    protected $casts = [
        'entry_date'   => 'date',
        'amount'       => 'decimal:2',
        'reversed_at'  => 'datetime',
    ];

    public function leasingContract(): BelongsTo { return $this->belongsTo(LeasingContract::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function reversalJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id'); }
    public function postedBy(): BelongsTo { return $this->belongsTo(User::class, 'posted_by'); }
    public function reversedBy(): BelongsTo { return $this->belongsTo(User::class, 'reversed_by'); }
}
