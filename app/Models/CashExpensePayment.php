<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CashExpensePayment
//
//  A direct expense payment with no Supplier Bill behind it — see
//  migration 000177 for full design rationale. Structural sibling of
//  OtherPayment: posts immediately, no allocation/settlement concept,
//  Set to Draft is the only way back to an editable state.
//
//  MULTI-LINE (August 2026, see migration 000181) — one payment (one
//  payee, one date, one money type, one source account) carries
//  MULTIPLE expense lines via lines(), each with its own Expense
//  Account, Cost Center, subtotal, VAT rate (+ is_deductible), and
//  WHT rate. subtotal/vat_amount/withholding_tax_amount/net_amount on
//  THIS model are now AGGREGATE TOTALS (sum of every line, recalculated
//  on every save — see CashExpensePaymentService) for fast list-page
//  display only; the real detail lives on each CashExpensePaymentLine.
//
//  partner_id is OPTIONAL — many of these payments have no registered
//  Partner at all. payee_description carries a free-text label for
//  the journal narration when partner_id is null.
//
//  "Allocating with Customer Contracts" (August 2026) — allocations()
//  is a reporting-only split across the payee's/any customer's
//  Projects/Contracts (see migration 000179), now also tagged to
//  WHICH line it was drawn from (migration 000183). Confirmed with
//  Mahmoud: zero effect on the journal entry; does not have to add up
//  to the full payment; feeds the future Project P&L and Project Cash
//  Flow reports once those exist.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, CashExpensePaymentLine,
//            Currency, FinancialInstitutions\BankAccount,
//            FinancialInstitutions\CreditFacility, JournalEntry,
//            ChequePayable, CashExpensePaymentAllocation, User
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

class CashExpensePayment extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    const MONEY_CASH_PAYMENT      = 'cash_payment';
    const MONEY_OUTGOING_TRANSFER = 'outgoing_transfer';
    const MONEY_PAYABLE_CHEQUE    = 'payable_cheque';

    const SOURCE_BANK_ACCOUNT    = 'bank_account';
    const SOURCE_CREDIT_FACILITY = 'credit_facility';

    protected $fillable = [
        'company_id', 'payment_code', 'partner_id', 'payee_description',
        'payment_date', 'money_type', 'reference_number',
        'source_type', 'source_bank_account_id', 'source_credit_facility_id',
        'safe_account_id', 'receipt_number',
        'currency_id', 'exchange_rate',
        'subtotal', 'vat_amount', 'withholding_tax_amount', 'net_amount',
        'subtotal_functional', 'vat_amount_functional',
        'withholding_tax_amount_functional', 'net_amount_functional',
        'status', 'journal_entry_id', 'internal_notes',
        'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'payment_date'                       => 'date',
        'exchange_rate'                       => 'decimal:6',
        'subtotal'                             => 'decimal:2',
        'vat_amount'                           => 'decimal:2',
        'withholding_tax_amount'               => 'decimal:2',
        'net_amount'                           => 'decimal:2',
        'subtotal_functional'                  => 'decimal:2',
        'vat_amount_functional'                => 'decimal:2',
        'withholding_tax_amount_functional'    => 'decimal:2',
        'net_amount_functional'                => 'decimal:2',
        'posted_at'                            => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** Multi-line detail — see migration 000181. Ordered by sort_order for display. */
    public function lines(): HasMany
    {
        return $this->hasMany(CashExpensePaymentLine::class)->orderBy('sort_order');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
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

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** Only present when money_type = 'payable_cheque'. Shares cheques_payable with Supplier Payment / Other Payment — see migration 000178. */
    public function cheque(): HasOne
    {
        return $this->hasOne(ChequePayable::class);
    }

    /** Reporting-only splits across customer Projects/Contracts — see migration 000179. Never affects the journal entry. */
    public function allocations(): HasMany
    {
        return $this->hasMany(CashExpensePaymentAllocation::class);
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

    // ── Helpers ───────────────────────────────────────────────────

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isCheque(): bool
    {
        return $this->money_type === self::MONEY_PAYABLE_CHEQUE;
    }

    /** Display label for the payee — the Partner's name if one is linked, otherwise the free-text description. */
    public function getPayeeNameAttribute(): string
    {
        return $this->partner?->name_en ?? $this->payee_description ?? '—';
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->payment_code;
    }
}
