<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ChequeReceivable
//
//  See migration 000126 for the full design rationale. One row per
//  cheque physically received by the company, 1:1 with WHICHEVER
//  collection received it. Tracks the cheque's own state from here:
//  received → sent_for_collection → collected / rejected.
//
//  TWO POSSIBLE SOURCES (migration 000173, August 2026) — per
//  Mahmoud: a cheque from Other Collection is not a separate list, it
//  belongs in this exact same table/screen as a cheque from Customer
//  Collection. Exactly one of customer_payment_id / other_collection_id
//  is set on any given row (enforced in ChequeReceivableService, see
//  source()/sourcePartner() below for the source-agnostic accessors
//  the rest of the app should use instead of reaching for one FK
//  directly).
//
//  THIRD SOURCE (Audit C7, Sept 2026) — Opening Balances. A cheque
//  that was already in the safe or under collection on go-live day is
//  created here when opening balances are posted (ob_note_receivable_id
//  set, no parent collection). Its drawee bank is kept as text
//  (drawee_bank_name) because the opening screen never asked for a
//  Bank record.
//
//  CURRENCY (Audit C8, Sept 2026) — currency_id / exchange_rate /
//  amount_functional record the cheque's own currency and the EGP
//  value it was booked into 134 at. Rows created before migration
//  000293 leave them NULL; currencyIdResolved(), bookedRate() and
//  bookedFunctional() fall back to the parent collection, so every
//  caller gets one consistent answer.
//
//  @layer    Model
//  @depends  BelongsToCompany, CustomerPayment, OtherCollection, Partner,
//            Bank, ChartOfAccount, FinancialInstitutions\BankAccount,
//            FinancialInstitutions\CreditFacility, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChequeReceivable extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    // Fix: Laravel's default table-name guess for this model would be
    // 'cheque_receivables' (auto-pluralizing the whole class name) —
    // the actual table, per migration 000126, is 'cheques_receivable'
    // (pluralizing "cheque" only, matching how the feature is named
    // everywhere else — "Cheques Receivable", not "Cheque
    // Receivables"). Without this, every query against this model
    // fails with "table not found".
    protected $table = 'cheques_receivable';

    const STATUS_RECEIVED             = 'received';
    const STATUS_SENT_FOR_COLLECTION  = 'sent_for_collection';
    const STATUS_COLLECTED            = 'collected';
    const STATUS_REJECTED             = 'rejected';

    protected $fillable = [
        'company_id', 'customer_payment_id', 'other_collection_id', 'partner_id',
        'drawee_bank_id', 'drawee_bank_name', 'ob_note_receivable_id',
        'currency_id', 'exchange_rate', 'amount_functional',
        'cheque_amount', 'cheque_number', 'due_date',
        'receiving_safe_account_id', 'status',
        'collection_destination_type', 'collection_destination_bank_account_id',
        'collection_destination_credit_facility_id', 'sent_for_collection_at',
        'collected_journal_entry_id', 'collected_at',
        'rejected_journal_entry_id', 'rejected_at',
        'created_by',
    ];

    protected $casts = [
        'cheque_amount'          => 'decimal:2',
        'exchange_rate'          => 'decimal:6',
        'amount_functional'      => 'decimal:2',
        'due_date'               => 'date',
        'sent_for_collection_at' => 'datetime',
        'collected_at'           => 'datetime',
        'rejected_at'            => 'datetime',
    ];

    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class);
    }

    public function otherCollection(): BelongsTo
    {
        return $this->belongsTo(OtherCollection::class);
    }

    /** True when this cheque came from Other Collection rather than Customer Collection. */
    public function isFromOtherCollection(): bool
    {
        return ! is_null($this->other_collection_id);
    }

    /** True when this cheque was created from the Opening Balances screen (Audit C7). */
    public function isFromOpeningBalance(): bool
    {
        return ! is_null($this->ob_note_receivable_id);
    }

    /** Source-agnostic accessor — whichever parent record actually received this cheque (null for an opening-balance cheque). */
    public function source(): CustomerPayment|OtherCollection|null
    {
        if ($this->isFromOpeningBalance()) {
            return null;
        }

        return $this->isFromOtherCollection() ? $this->otherCollection : $this->customerPayment;
    }

    // ── Currency (Audit C8) ───────────────────────────────────────

    /** The cheque's own currency; null means functional. */
    public function currencyIdResolved(): ?int
    {
        if ($this->currency_id) {
            return (int) $this->currency_id;
        }

        $source = $this->source();

        return match (true) {
            $source instanceof CustomerPayment => $source->receive_currency_id ? (int) $source->receive_currency_id : null,
            $source instanceof OtherCollection => $source->currency_id ? (int) $source->currency_id : null,
            default                            => null,
        };
    }

    /** Rate the cheque was booked into 134 at ('1' if functional / unknown). */
    public function bookedRate(): string
    {
        if ($this->exchange_rate && bccomp((string) $this->exchange_rate, '0', 6) > 0) {
            return (string) $this->exchange_rate;
        }

        $rate = $this->source()?->exchange_rate;

        return $rate && bccomp((string) $rate, '0', 6) > 0 ? (string) $rate : '1';
    }

    /** Functional (EGP) value this cheque sits in 134 at. */
    public function bookedFunctional(): string
    {
        if ($this->amount_functional !== null) {
            return (string) $this->amount_functional;
        }

        $source = $this->source();
        if ($source && $source->amount_functional !== null && bccomp((string) $source->amount, (string) $this->cheque_amount, 2) === 0) {
            return (string) $source->amount_functional;
        }

        return bcadd(bcmul((string) $this->cheque_amount, $this->bookedRate(), 8), '0.005', 2);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function draweeBank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'drawee_bank_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function obNoteReceivable(): BelongsTo
    {
        return $this->belongsTo(ObNoteReceivable::class);
    }

    /** Tag only — see migration 000126 header. Not a posting relation. */
    public function receivingSafeAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'receiving_safe_account_id');
    }

    public function collectionDestinationBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'collection_destination_bank_account_id');
    }

    public function collectionDestinationCreditFacility(): BelongsTo
    {
        return $this->belongsTo(CreditFacility::class, 'collection_destination_credit_facility_id');
    }

    public function collectedJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'collected_journal_entry_id');
    }

    public function rejectedJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'rejected_journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeInSafe(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RECEIVED);
    }

    public function scopeSentForCollection(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT_FOR_COLLECTION);
    }

    public function isReceived(): bool           { return $this->status === self::STATUS_RECEIVED; }
    public function isSentForCollection(): bool  { return $this->status === self::STATUS_SENT_FOR_COLLECTION; }
    public function isCollected(): bool          { return $this->status === self::STATUS_COLLECTED; }
    public function isRejected(): bool           { return $this->status === self::STATUS_REJECTED; }
}
