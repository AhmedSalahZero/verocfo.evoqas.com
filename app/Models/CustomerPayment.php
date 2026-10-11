<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CustomerPayment
//
//  Customer Collection redesign (July 2026) — see
//  VeroCFO_Customer_Collection_Design_v1_0.md, the authoritative
//  reference, and migration 000125 for the field-by-field rationale.
//
//  Posts IMMEDIATELY on save — no draft stage (unlike Customer
//  Invoice/Supplier Bill). Recording cash that's already arrived
//  isn't an unfinished document the way a draft invoice is; the
//  screenshots this design was built from show a single Save action,
//  not a separate draft-then-post flow. `status` stays for schema
//  consistency with the rest of the app (and to support a future
//  cancel/reversal action) but 'draft' is not a state this module's
//  UI ever leaves a record in.
//
//  collection_type ('settlement' | 'down_payment') does NOT change
//  the journal entry — both post `Dr destination / Cr AR` for the
//  full amount, identically. The only difference is whether the AR
//  credit is attributed to invoice(s) immediately (via
//  CustomerPaymentAllocation, unchanged from the original build) or
//  left unallocated with a down_payment_* tag for later.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, FinancialInstitutions\BankAccount,
//            FinancialInstitutions\CreditFacility, Currency, ChartOfAccount,
//            Project, SalesOrder, JournalEntry, CustomerPaymentAllocation,
//            ChequeReceivable, User
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

class CustomerPayment extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    const TYPE_SETTLEMENT   = 'settlement';
    const TYPE_DOWN_PAYMENT = 'down_payment';

    const MONEY_BANK_DEPOSIT       = 'bank_deposit';
    const MONEY_INCOMING_TRANSFER  = 'incoming_transfer';
    const MONEY_CASH_IN_SAFE       = 'cash_in_safe';
    const MONEY_CHEQUE             = 'cheque';

    const DESTINATION_BANK_ACCOUNT   = 'bank_account';
    const DESTINATION_CREDIT_FACILITY = 'credit_facility';

    protected $fillable = [
        'company_id', 'payment_code', 'partner_id', 'collection_type',
        'down_payment_project_id', 'down_payment_sales_order_id',
        'payment_date', 'money_type', 'reference_number',
        'destination_type', 'destination_bank_account_id', 'destination_credit_facility_id',
        'safe_account_id', 'receipt_number',
        'receive_currency_id', 'invoice_currency_id', 'invoice_to_receive_exchange_rate',
        'exchange_rate', 'amount', 'amount_functional', 'fx_gain_loss_functional',
        'allocated_amount', 'unallocated_amount',
        'status', 'journal_entry_id', 'internal_notes',
        'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'payment_date'                      => 'date',
        'exchange_rate'                      => 'decimal:6',
        'invoice_to_receive_exchange_rate'   => 'decimal:6',
        'amount'                              => 'decimal:2',
        'amount_functional'                   => 'decimal:2',
        'fx_gain_loss_functional'             => 'decimal:2',
        'allocated_amount'                    => 'decimal:2',
        'unallocated_amount'                  => 'decimal:2',
        'posted_at'                           => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** "Receive Currency" in the UI — the currency of the cash actually received. */
    public function receiveCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'receive_currency_id');
    }

    /** Filters which open invoices are eligible for settlement. */
    public function invoiceCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'invoice_currency_id');
    }

    public function destinationBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'destination_bank_account_id');
    }

    public function destinationCreditFacility(): BelongsTo
    {
        return $this->belongsTo(CreditFacility::class, 'destination_credit_facility_id');
    }

    public function safeAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'safe_account_id');
    }

    /** "Contract" in the UI — confirmed the same entity as Project. */
    public function downPaymentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'down_payment_project_id');
    }

    public function downPaymentSalesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'down_payment_sales_order_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
    }

    /** Only present when money_type = 'cheque'. */
    public function cheque(): HasOne
    {
        return $this->hasOne(ChequeReceivable::class)->oldestOfMany();
    }

    /** M-31: every cheque of the batch (a collection may hold several post-dated cheques). */
    public function cheques(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ChequeReceivable::class)->orderBy('due_date')->orderBy('id');
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
        return $query->where('collection_type', self::TYPE_SETTLEMENT);
    }

    public function scopeDownPayments(Builder $query): Builder
    {
        return $query->where('collection_type', self::TYPE_DOWN_PAYMENT);
    }

    /** Posted collections with cash still available to match against an invoice — the "Apply Down Payment" pool. */
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
        return $this->collection_type === self::TYPE_DOWN_PAYMENT;
    }

    public function isCheque(): bool
    {
        return $this->money_type === self::MONEY_CHEQUE;
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
