<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ExpenseClaim
//
//  An employee spent their own money for the company and wants it
//  back. This model carries the claim and its approval trail; the
//  receipts themselves are ExpenseClaimLine rows.
//
//  See migration 000281's header for the full design reasoning —
//  why this exists alongside Other Payment's existing
//  'reimbursement' type, why the liability lands in 232 Other
//  Creditors, and why VAT is deliberately out of scope.
//
//  TWO JOURNAL ENTRIES, AT TWO DIFFERENT MOMENTS:
//
//    approve()   DR each line's expense account / CR 232
//                → journal_entry_id
//    markPaid()  DR 232 / CR bank or cash safe
//                → payment_journal_entry_id
//
//  Nothing posts before approval. A submitted claim is a request,
//  not an obligation.
//
//  STATUS LIFECYCLE:
//
//      draft ──submit──▶ submitted ──approve──▶ approved ──pay──▶ paid
//        │                   │                     │
//        │                   └───reject───▶ rejected
//        └──cancel──▶ cancelled
//
//  `approved` is reversible via setToDraft(), which reverses the
//  accrual entry — the same reopen-to-draft shape Goods Receipt Note
//  and Supplier Bill already use. `paid` is NOT reversible here: once
//  money has left the company, unwinding it is a treasury correction,
//  not an HR one.
//
//  total_amount is the aggregate of the lines in the claim's own
//  currency; amount_functional is what actually reaches the ledger.
//  Both are recalculated on every save by ExpenseClaimService — never
//  written directly.
//
//  @layer    Model
//  @depends  BelongsToCompany, SerializesDateOnly, Partner, Currency,
//            ExpenseClaimLine, JournalEntry,
//            FinancialInstitutions\BankAccount, ChartOfAccount, User
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\BankAccount;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseClaim extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_SUBMITTED = 'submitted';
    const STATUS_APPROVED  = 'approved';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_PAID      = 'paid';

    const ALL_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
        self::STATUS_PAID,
    ];

    const SOURCE_BANK_ACCOUNT = 'bank_account';
    const SOURCE_CASH_SAFE    = 'cash_safe';

    /**
     * The account an approved-but-unpaid claim sits in. Chosen, not
     * inherited — see migration 000281's header for why 232 rather
     * than 221001, 228, 147 or 148. Flagged there as a decision to
     * confirm with the business owner.
     */
    const LIABILITY_ACCOUNT_CODE = '232';

    protected $fillable = [
        'company_id', 'claim_code', 'partner_id', 'claim_date', 'title', 'description',
        'currency_id', 'exchange_rate', 'total_amount', 'amount_functional', 'status',
        'submitted_at', 'submitted_by', 'approved_at', 'approved_by',
        'rejected_at', 'rejected_by', 'rejection_reason',
        'cancelled_at', 'cancelled_by', 'journal_entry_id',
        'payment_date', 'payment_source_type', 'payment_bank_account_id',
        'payment_safe_account_id', 'payment_reference', 'payment_journal_entry_id',
        'paid_at', 'paid_by', 'created_by',
    ];

    protected $casts = [
        'claim_date'        => 'date',
        'payment_date'      => 'date',
        'exchange_rate'     => 'decimal:6',
        'total_amount'      => 'decimal:2',
        'amount_functional' => 'decimal:2',
        'submitted_at'      => 'datetime',
        'approved_at'       => 'datetime',
        'rejected_at'       => 'datetime',
        'cancelled_at'      => 'datetime',
        'paid_at'           => 'datetime',
    ];

    // ── Relations ─────────────────────────────────────────────────

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseClaimLine::class)->orderBy('sort_order');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /** The accrual raised on approval. */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /** The settlement raised when the money left. */
    public function paymentJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'payment_journal_entry_id');
    }

    public function paymentBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'payment_bank_account_id');
    }

    public function paymentSafeAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'payment_safe_account_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ────────────────────────────────────────────────────

    /** Approved but not yet paid — what the company still owes staff. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    // ── State questions ───────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Lines may only be added, edited or removed while it is a draft. */
    public function isEditable(): bool
    {
        return $this->isDraft();
    }

    /** True once anything has reached the general ledger. */
    public function hasPosted(): bool
    {
        return $this->journal_entry_id !== null;
    }
}
