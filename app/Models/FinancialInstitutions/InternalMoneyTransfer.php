<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  InternalMoneyTransfer
//
//  Moves cash between two of the company's own accounts — Bank or
//  Safe (a 131xxx Chart of Accounts sub-account) on either side.
//  Four leg combinations via from_leg_type/to_leg_type: bank↔bank,
//  bank↔safe, safe↔bank, safe↔safe. See InternalMoneyTransferService
//  for the posting logic (instant vs. in-transit via COA 145).
//
//  @layer    Model
//  @depends  BelongsToCompany, BankAccount, ChartOfAccount, Currency,
//            JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\Traits\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalMoneyTransfer extends Model
{
    use BelongsToCompany;

    const LEG_BANK     = 'bank';
    const LEG_SAFE     = 'safe';
    // v1.1 (migration 000219) — a leg can now be an OD-family credit
    // facility. from_leg_type = facility → a drawdown (the facility
    // funds the other leg). to_leg_type = facility → a settlement
    // (the other leg pays the facility down). See
    // FacilitySettlementEngine.
    const LEG_FACILITY = 'facility';

    const STATUS_IN_TRANSIT = 'in_transit';
    const STATUS_CLEARED    = 'cleared';
    const STATUS_CANCELLED  = 'cancelled';

    protected $fillable = [
        'company_id', 'transfer_code',
        'from_leg_type', 'from_bank_account_id', 'from_safe_account_id', 'from_credit_facility_id',
        'to_leg_type', 'to_bank_account_id', 'to_safe_account_id', 'to_credit_facility_id',
        'currency_id', 'amount', 'amount_functional', 'exchange_rate',
        'transfer_date', 'transfer_days', 'expected_clearing_date', 'cleared_date',
        'status', 'journal_entry_id', 'clearing_journal_entry_id',
        'comment', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'amount'             => 'decimal:2',
        'amount_functional'  => 'decimal:2',
        'exchange_rate'      => 'decimal:10',
        'transfer_date'      => 'date',
        'expected_clearing_date' => 'date',
        'cleared_date'       => 'date',
    ];

    public function fromBankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'from_bank_account_id'); }
    public function fromSafeAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'from_safe_account_id'); }
    public function fromCreditFacility(): BelongsTo { return $this->belongsTo(CreditFacility::class, 'from_credit_facility_id'); }
    public function toBankAccount(): BelongsTo   { return $this->belongsTo(BankAccount::class, 'to_bank_account_id'); }
    public function toSafeAccount(): BelongsTo   { return $this->belongsTo(ChartOfAccount::class, 'to_safe_account_id'); }
    public function toCreditFacility(): BelongsTo   { return $this->belongsTo(CreditFacility::class, 'to_credit_facility_id'); }
    public function currency(): BelongsTo        { return $this->belongsTo(Currency::class); }
    public function journalEntry(): BelongsTo    { return $this->belongsTo(JournalEntry::class); }
    public function clearingJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'clearing_journal_entry_id'); }
    public function createdBy(): BelongsTo       { return $this->belongsTo(User::class, 'created_by'); }

    public function isInTransit(): bool { return $this->status === self::STATUS_IN_TRANSIT; }
    public function isCleared(): bool   { return $this->status === self::STATUS_CLEARED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }
}
