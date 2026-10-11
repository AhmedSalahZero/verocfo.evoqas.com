<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankAccount
//
//  One account per record. Links to a COA 132 sub-account.
//  Time Deposits carry maturity, rate, and lifecycle fields.
//  LG/LC Cash Cover accounts tagged with is_cash_cover = true.
//
//  Account types: current | time_deposit | escrow | lg_cash_cover | lc_cash_cover
//
//  Fix (July 2026 — Time Deposit Lifecycle, confirmed with Mahmoud):
//    - td_auto_renewal removed — replaced by a real Renew action
//      (TimeDepositService::renew()) that keeps its own history row
//      per renewal, rather than a single static flag.
//    - td_entry_type ('new'/'existing') + td_funding_bank_account_id
//      added — same 'new' vs 'existing' principle as the Leasing
//      engine. A 'new' TD posts a real opening entry, moving cash out
//      of a real funding account; an 'existing' TD just records where
//      things stand today, no opening entry.
//    - account_number is now unique per (company_id, bank_id) at the
//      database level — see migration 000116.
//
//  @layer    Model
//  @depends  Bank, Currency, ChartOfAccount, CreditFacility, BankAccountTdEvent
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\SerializesDateOnly;

class BankAccount extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'bank_accounts';

    protected $fillable = [
        'company_id', 'bank_id', 'account_name', 'account_number', 'account_type',
        'currency_id', 'coa_account_id', 'interest_rate', 'minimum_balance_for_interest',
        'is_cash_cover', 'linked_facility_id',
        'td_entry_type', 'td_funding_bank_account_id',
        'td_start_date', 'td_maturity_date', 'td_interest_rate', 'td_wht_rate',
        'td_amount', 'is_active', 'created_by',
    ];

    protected $casts = [
        'is_cash_cover'                 => 'boolean',
        'is_active'                     => 'boolean',
        'td_start_date'                 => 'date',
        'td_maturity_date'               => 'date',
        'td_interest_rate'               => 'decimal:4',
        'td_amount'                      => 'decimal:2',
        'interest_rate'                  => 'decimal:4',
        'minimum_balance_for_interest'   => 'decimal:2',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function coaAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'coa_account_id');
    }

    public function termsHistory(): HasMany
    {
        return $this->hasMany(BankAccountTermsHistory::class)->orderByDesc('effective_date');
    }

    /** The Current account this TD's cash was deducted from at opening — 'new' entries only. */
    public function fundingAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'td_funding_bank_account_id');
    }

    /** Full lifecycle history for this TD — interest applications, break, maturity, renewals. */
    public function tdEvents(): HasMany
    {
        return $this->hasMany(BankAccountTdEvent::class)->orderByDesc('event_date')->orderByDesc('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('account_type', 'current');
    }

    public function scopeTimeDeposits(Builder $query): Builder
    {
        return $query->where('account_type', 'time_deposit');
    }

    public function isTimeDeposit(): bool { return $this->account_type === 'time_deposit'; }
    public function isCurrent(): bool     { return $this->account_type === 'current'; }
    public function isEscrow(): bool      { return $this->account_type === 'escrow'; }

    public function isNewEntry(): bool      { return $this->td_entry_type === 'new'; }
    public function isExistingEntry(): bool { return $this->td_entry_type === 'existing'; }
}

