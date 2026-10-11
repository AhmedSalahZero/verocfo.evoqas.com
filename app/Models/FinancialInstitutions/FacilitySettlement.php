<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FacilitySettlement
//
//  One credit event applied against an OD-family facility (Clean OD /
//  Commercial Paper OD / Contract-Assignment OD / Fully Secured OD).
//  See migration 000216 header for the full design note — most
//  importantly: journal_entry_id points at the entry the SOURCE
//  transaction (Customer Payment / Internal Transfer) already
//  posted. This model never posts a second entry for the same money.
//
//  How the amount was actually split across interest / HDB / admin
//  charges and withdrawals lives in allocations() — see
//  FacilitySettlementAllocation.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope, CreditFacility,
//            BankAccount, JournalEntry, User, FacilitySettlementAllocation
// ══════════════════════════════════════════════════════════════════

use App\Models\JournalEntry;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacilitySettlement extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'facility_settlements';

    const SOURCE_CUSTOMER_PAYMENT       = 'customer_payment';
    const SOURCE_INTERNAL_MONEY_TRANSFER = 'internal_money_transfer';
    const SOURCE_OTHER_COLLECTION       = 'other_collection';

    // TRS-002 — a Medium-Term Loan instalment. Only the PRINCIPAL half
    // of the instalment ever reaches here: interest is a cost of
    // carrying the debt, not a repayment of it, so it never reduces
    // what the facility has drawn or replenishes its room.
    const SOURCE_MTL_SETTLEMENT         = 'mtl_settlement';

    const STATUS_POSTED   = 'posted';
    const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'company_id',
        'credit_facility_id',
        'bank_account_id',
        'settlement_date',
        'amount',
        'source_type',
        'source_id',
        'journal_entry_id',
        'status',
        'reversed_by',
        'reversed_at',
        'created_by',
    ];

    protected $casts = [
        'settlement_date' => 'date',
        'amount'          => 'decimal:2',
        'reversed_at'     => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function creditFacility(): BelongsTo { return $this->belongsTo(CreditFacility::class); }
    public function bankAccount(): BelongsTo    { return $this->belongsTo(BankAccount::class); }
    public function journalEntry(): BelongsTo   { return $this->belongsTo(JournalEntry::class); }
    public function reversedBy(): BelongsTo     { return $this->belongsTo(User::class, 'reversed_by'); }
    public function createdBy(): BelongsTo      { return $this->belongsTo(User::class, 'created_by'); }

    public function allocations(): HasMany
    {
        return $this->hasMany(FacilitySettlementAllocation::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }
}
