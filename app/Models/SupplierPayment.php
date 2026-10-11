<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  SupplierPayment
//
//  Supplier Payment (August 2026) — payable-side mirror of
//  CustomerPayment. See migration 000137 for the full field-by-field
//  rationale. Every account role is reversed (Dr AP or 144 Advances /
//  Cr [source]) but the shape — dual currency, money_type +
//  polymorphic source, Reopen-to-Draft lifecycle — is identical by
//  design, confirmed by Mahmoud.
//
//  Posts IMMEDIATELY on save — no real draft stage, same as
//  CustomerPayment. 'draft' is only ever reached by reopening a
//  posted payment (setToDraft()), never a starting state.
//
//  money_type has only THREE values (not four) — Cash Payment /
//  Outgoing Transfer / Payable Cheque — confirmed by Mahmoud.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, FinancialInstitutions\BankAccount,
//            FinancialInstitutions\CreditFacility, Currency, ChartOfAccount,
//            Project, PurchaseOrder, JournalEntry, SupplierPaymentAllocation,
//            ChequePayable, User
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupplierPayment extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    const TYPE_SETTLEMENT   = 'settlement';
    const TYPE_DOWN_PAYMENT = 'down_payment';

    const MONEY_CASH_PAYMENT     = 'cash_payment';
    const MONEY_OUTGOING_TRANSFER = 'outgoing_transfer';
    const MONEY_PAYABLE_CHEQUE   = 'payable_cheque';

    const SOURCE_BANK_ACCOUNT    = 'bank_account';
    const SOURCE_CREDIT_FACILITY = 'credit_facility';

    protected $fillable = [
        'company_id', 'payment_code', 'partner_id', 'payment_type',
        'down_payment_project_id', 'down_payment_purchase_order_id',
        'payment_date', 'money_type', 'reference_number',
        'source_type', 'source_bank_account_id', 'source_credit_facility_id',
        'safe_account_id', 'receipt_number',
        'pay_currency_id', 'bill_currency_id', 'bill_to_pay_exchange_rate',
        'exchange_rate', 'amount', 'amount_functional', 'fx_gain_loss_functional',
        'allocated_amount', 'unallocated_amount',
        'status', 'journal_entry_id', 'internal_notes',
        'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'payment_date'                  => 'date',
        'exchange_rate'                  => 'decimal:6',
        'bill_to_pay_exchange_rate'      => 'decimal:6',
        'amount'                          => 'decimal:2',
        'amount_functional'               => 'decimal:2',
        'fx_gain_loss_functional'         => 'decimal:2',
        'allocated_amount'                => 'decimal:2',
        'unallocated_amount'              => 'decimal:2',
        'posted_at'                       => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** "Pay Currency" in the UI — the currency of the cash actually paid out. */
    public function payCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'pay_currency_id');
    }

    /** Filters which open bills are eligible for settlement. */
    public function billCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'bill_currency_id');
    }

    public function sourceBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'source_bank_account_id');
    }

    public function sourceCreditFacility(): BelongsTo
    {
        return $this->belongsTo(CreditFacility::class, 'source_credit_facility_id');
    }

    public function safeAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'safe_account_id');
    }

    public function downPaymentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'down_payment_project_id');
    }

    public function downPaymentPurchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'down_payment_purchase_order_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /** Only present when money_type = 'payable_cheque'. */
    public function cheque(): HasOne
    {
        return $this->hasOne(ChequePayable::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function scopeSettlements(Builder $query): Builder
    {
        return $query->where('payment_type', self::TYPE_SETTLEMENT);
    }

    public function scopeDownPayments(Builder $query): Builder
    {
        return $query->where('payment_type', self::TYPE_DOWN_PAYMENT);
    }

    /** Posted payments with cash still available to match against a bill — the "Apply Down Payment" pool. */
    public function scopeUnallocated(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED)->where('unallocated_amount', '>', 0);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isDownPayment(): bool
    {
        return $this->payment_type === self::TYPE_DOWN_PAYMENT;
    }

    public function isCheque(): bool
    {
        return $this->money_type === self::MONEY_PAYABLE_CHEQUE;
    }

    public function isFullyAllocated(): bool
    {
        return bccomp((string) $this->unallocated_amount, '0', 2) <= 0;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->payment_code;
    }
}
