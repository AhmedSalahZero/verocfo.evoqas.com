<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  OtherCollection
//
//  Other Collection — money received from a Shareholder, Employee,
//  Sister Company, Supplier (a refund of an overpayment), or Other
//  partner. See VeroCFO_Other_Collection_Design_v1_0.md and migration
//  000172 for the full field-by-field rationale.
//
//  Posts IMMEDIATELY on save — no draft-then-post two-step, same
//  posture as Customer Collection (CustomerPayment). 'status' exists
//  for schema consistency and to support "Set to Draft" (reopen +
//  re-post), not because this module ever leaves a fresh record
//  sitting as a draft.
//
//  credit_account_id is the one genuinely different piece versus
//  Customer/Supplier Collection: there is no single system-resolved
//  credit account here (no AR, no AP) — the user picks it directly
//  from the Chart of Accounts, typically:
//    Shareholder      → 230 Shareholders Current Accounts
//    Employee         → depends on employee_advance_type — see below
//    Sister Company   → 139 Intercompany Receivables
//    Supplier         → 144 Advances to Suppliers
//    Other            → no default — user's own judgment
//
//  BUG FIX (2026-09, Advances & Settlements) — Employee used to
//  default to 140 Other Debtors unconditionally, which meant a
//  repayment recorded here never actually reduced the 147/148
//  balance the advance itself was sitting on (it silently created an
//  unrelated balance on a completely different account instead). Now
//  routed the same way as OtherPayment — see below.
//
//  EMPLOYEE ADVANCE TYPE — when partner_type = employee,
//  employee_advance_type decides the actual account:
//    custody         → 148 Custody Advances to Employees
//    advance_salary  → 147 Advances to Employees
//  (per_diem deliberately has no collection-side equivalent — you
//  don't "collect" a per diem back; it's an outright expense with no
//  balance to settle. See OtherPayment for that side.)
//  See DEFAULT_CREDIT_ACCOUNT_CODE (unchanged, keyed by partner_type
//  for everyone else) and defaultCreditAccountCode() below, the
//  actual resolution entry point.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, FinancialInstitutions\BankAccount,
//            FinancialInstitutions\CreditFacility, Currency, ChartOfAccount,
//            JournalEntry, ChequeReceivable, User
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OtherCollection extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    const PARTNER_TYPE_SHAREHOLDER    = 'shareholder';
    const PARTNER_TYPE_EMPLOYEE       = 'employee';
    const PARTNER_TYPE_SISTER_COMPANY = 'sister_company';
    const PARTNER_TYPE_SUPPLIER       = 'supplier';
    const PARTNER_TYPE_OTHER          = 'other';

    const ALL_PARTNER_TYPES = [
        self::PARTNER_TYPE_SHAREHOLDER,
        self::PARTNER_TYPE_EMPLOYEE,
        self::PARTNER_TYPE_SISTER_COMPANY,
        self::PARTNER_TYPE_SUPPLIER,
        self::PARTNER_TYPE_OTHER,
    ];

    // Only meaningful when partner_type = employee — see class header.
    // Deliberately no PER_DIEM here — see header, "you don't collect
    // a per diem back."
    const EMPLOYEE_ADVANCE_CUSTODY = 'custody';
    const EMPLOYEE_ADVANCE_SALARY  = 'advance_salary';

    const ALL_EMPLOYEE_ADVANCE_TYPES = [
        self::EMPLOYEE_ADVANCE_CUSTODY,
        self::EMPLOYEE_ADVANCE_SALARY,
    ];

    /** Account code per Employee advance type. */
    const EMPLOYEE_ADVANCE_ACCOUNT_CODE = [
        self::EMPLOYEE_ADVANCE_CUSTODY => '148',
        self::EMPLOYEE_ADVANCE_SALARY  => '147',
    ];

    /** Default Chart of Accounts code suggested per Partner Type — user can always override. Employee is superseded by employeeAdvanceAccountCode() when employee_advance_type is set — see that method. */
    const DEFAULT_CREDIT_ACCOUNT_CODE = [
        self::PARTNER_TYPE_SHAREHOLDER    => '230',
        self::PARTNER_TYPE_EMPLOYEE       => '147', // fallback only — see defaultCreditAccountCode()
        self::PARTNER_TYPE_SISTER_COMPANY => '139',
        self::PARTNER_TYPE_SUPPLIER       => '144',
        self::PARTNER_TYPE_OTHER          => null,
    ];

    const MONEY_BANK_DEPOSIT      = 'bank_deposit';
    const MONEY_INCOMING_TRANSFER = 'incoming_transfer';
    const MONEY_CASH_IN_SAFE      = 'cash_in_safe';
    const MONEY_CHEQUE            = 'cheque';

    const DESTINATION_BANK_ACCOUNT    = 'bank_account';
    const DESTINATION_CREDIT_FACILITY = 'credit_facility';

    protected $fillable = [
        'company_id', 'collection_code', 'partner_id', 'partner_type', 'employee_advance_type',
        'collection_date', 'money_type', 'reference_number',
        'destination_type', 'destination_bank_account_id', 'destination_credit_facility_id',
        'safe_account_id', 'receipt_number', 'credit_account_id',
        'currency_id', 'exchange_rate', 'amount', 'amount_functional',
        'status', 'journal_entry_id', 'internal_notes',
        'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'collection_date'   => 'date',
        'exchange_rate'      => 'decimal:6',
        'amount'              => 'decimal:2',
        'amount_functional'   => 'decimal:2',
        'posted_at'           => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
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

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'credit_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** Only present when money_type = 'cheque'. Shares cheques_receivable with Customer Collection — see migration 000173. */
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
        return $this->money_type === self::MONEY_CHEQUE;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->collection_code;
    }

    public static function partnerTypeLabel(string $type): string
    {
        return match ($type) {
            self::PARTNER_TYPE_SHAREHOLDER    => 'Shareholder',
            self::PARTNER_TYPE_EMPLOYEE       => 'Employee',
            self::PARTNER_TYPE_SISTER_COMPANY => 'Sister Company',
            self::PARTNER_TYPE_SUPPLIER       => 'Supplier',
            self::PARTNER_TYPE_OTHER          => 'Other',
            default => $type,
        };
    }

    public static function employeeAdvanceTypeLabel(string $type): string
    {
        return match ($type) {
            self::EMPLOYEE_ADVANCE_CUSTODY => 'Custody',
            self::EMPLOYEE_ADVANCE_SALARY  => 'Advance from Salary',
            default => $type,
        };
    }

    /**
     * The actual default-account resolution entry point — see
     * OtherPayment::defaultDebitAccountCode() for the mirror-image
     * version and the fuller explanation. This is the fix for the
     * 140-default bug described in the class header.
     */
    public static function defaultCreditAccountCode(string $partnerType, ?string $employeeAdvanceType = null): ?string
    {
        if ($partnerType === self::PARTNER_TYPE_EMPLOYEE && $employeeAdvanceType) {
            return self::EMPLOYEE_ADVANCE_ACCOUNT_CODE[$employeeAdvanceType] ?? null;
        }

        return self::DEFAULT_CREDIT_ACCOUNT_CODE[$partnerType] ?? null;
    }
}
