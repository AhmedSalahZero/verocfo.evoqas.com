<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FacilityPeriodEndEntry
//
//  One automatic month-end entry for a credit facility or a lease
//  (interest accrual, or current-portion reclass), with its reversal
//  on the 1st of the next month. See migration 000313 and
//  FacilityPeriodEndService. Never edited by hand — posted or
//  cancelled only.
//
//  Audit M21–M23 (Oct 2026).
//
//  @layer    Model
//  @depends  BelongsToCompany, CreditFacility, LeasingContract, JournalEntry
// ══════════════════════════════════════════════════════════════════

use App\Models\JournalEntry;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilityPeriodEndEntry extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const KIND_OD_INTEREST     = 'od_interest_accrual';
    const KIND_MTL_INTEREST    = 'mtl_interest_accrual';
    const KIND_MTL_CURRENT     = 'mtl_current_portion';
    const KIND_LEASE_CURRENT   = 'lease_current_portion';
    const KIND_TD_INTEREST     = 'td_interest_accrual';  // M-33

    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $table = 'facility_period_end_entries';

    protected $fillable = [
        'company_id', 'credit_facility_id', 'leasing_contract_id', 'bank_account_id', 'kind',
        'period_end', 'reversal_date', 'amount', 'amount_functional', 'status',
        'journal_entry_id', 'reversal_journal_entry_id', 'details',
        'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'period_end'        => 'date',
        'reversal_date'     => 'date',
        'amount'            => 'decimal:2',
        'amount_functional' => 'decimal:2',
        'details'           => 'array',
        'cancelled_at'      => 'datetime',
    ];

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }
}
