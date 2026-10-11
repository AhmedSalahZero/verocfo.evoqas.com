<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BuySellCurrency
//
//  A currency conversion — one currency sold, a different currency
//  bought, at a bank's negotiated deal rate. Same four-leg shape as
//  InternalMoneyTransfer (Bank or Safe on each side), but the two
//  legs are in different currencies, which is what makes this a
//  realization event under IAS 21 rather than a same-currency
//  transfer: the spread between the deal rate and the day's system
//  reference rate is a real, immediately-recognized realized FX
//  gain/loss. See BuySellCurrencyService for the full posting logic.
//
//  draft/posted lifecycle — never posts instantly like Customer
//  Payment/Internal Money Transfer's "cleared" default, since a
//  conversion always needs the reference-rate valuation confirmed
//  (and possibly adjusted) before it can post. setToDraft() reverses
//  everything post() did, same reverse-then-repost pattern used
//  throughout the app (Customer Payment, Supplier Payment, Lease
//  wear-down reversal).
//
//  @layer    Model
//  @depends  BelongsToCompany, BankAccount, ChartOfAccount, Currency,
//            JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuySellCurrency extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const LEG_BANK = 'bank';
    const LEG_SAFE = 'safe';

    const STATUS_DRAFT  = 'draft';
    const STATUS_POSTED = 'posted';

    protected $fillable = [
        'company_id', 'transaction_code', 'transaction_date',
        'from_leg_type', 'from_bank_account_id', 'from_safe_account_id',
        'to_leg_type', 'to_bank_account_id', 'to_safe_account_id',
        'currency_to_sell_id', 'currency_to_sell_amount',
        'currency_to_buy_id', 'currency_to_buy_amount',
        'deal_rate', 'sell_reference_rate', 'buy_reference_rate',
        'sell_fair_value_functional', 'buy_fair_value_functional',
        'realized_fx_gain_loss',
        'status', 'journal_entry_id',
        'comment', 'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'transaction_date'            => 'date',
        'currency_to_sell_amount'     => 'decimal:2',
        'currency_to_buy_amount'      => 'decimal:2',
        'deal_rate'                   => 'decimal:10',
        'sell_reference_rate'         => 'decimal:10',
        'buy_reference_rate'          => 'decimal:10',
        'sell_fair_value_functional'  => 'decimal:2',
        'buy_fair_value_functional'   => 'decimal:2',
        'realized_fx_gain_loss'       => 'decimal:2',
        'posted_at'                   => 'datetime',
    ];

    public function fromBankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'from_bank_account_id'); }
    public function fromSafeAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'from_safe_account_id'); }
    public function toBankAccount(): BelongsTo   { return $this->belongsTo(BankAccount::class, 'to_bank_account_id'); }
    public function toSafeAccount(): BelongsTo   { return $this->belongsTo(ChartOfAccount::class, 'to_safe_account_id'); }
    public function currencyToSell(): BelongsTo  { return $this->belongsTo(Currency::class, 'currency_to_sell_id'); }
    public function currencyToBuy(): BelongsTo   { return $this->belongsTo(Currency::class, 'currency_to_buy_id'); }
    public function journalEntry(): BelongsTo    { return $this->belongsTo(JournalEntry::class); }
    public function createdBy(): BelongsTo       { return $this->belongsTo(User::class, 'created_by'); }
    public function postedBy(): BelongsTo        { return $this->belongsTo(User::class, 'posted_by'); }

    public function isDraft(): bool  { return $this->status === self::STATUS_DRAFT; }
    public function isPosted(): bool { return $this->status === self::STATUS_POSTED; }

    public function isGain(): bool { return bccomp((string) $this->realized_fx_gain_loss, '0', 2) > 0; }
    public function isLoss(): bool { return bccomp((string) $this->realized_fx_gain_loss, '0', 2) < 0; }
}
