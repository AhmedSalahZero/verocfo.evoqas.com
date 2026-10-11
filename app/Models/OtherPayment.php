<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  OtherPayment
//
//  Other Payment — money paid to a Shareholder, Employee, Sister
//  Company, Customer (a refund of an overpayment), or Other partner.
//  Payable-side mirror of OtherCollection — see
//  VeroCFO_Other_Payment_Design_v1_0.md and migration 000174.
//
//  Posts IMMEDIATELY on save — no draft-then-post two-step, same
//  posture as Supplier Payment / Other Collection. 'status' exists
//  for schema consistency and to support "Set to Draft".
//
//  debit_account_id is the one genuinely different piece versus
//  Supplier Payment: no AP is ever involved. The user picks it
//  directly from the Chart of Accounts, typically:
//    Shareholder      → 230 Shareholders Current Accounts
//    Employee         → depends on employee_advance_type — see below
//    Sister Company   → 139 Intercompany Receivables
//    Customer         → 235 Advances from Customers
//    Other            → no default — user's own judgment
//
//  EMPLOYEE ADVANCE TYPE (2026-09, Advances & Settlements) — Employee
//  is the one partner type that isn't one single thing. When
//  partner_type = employee, employee_advance_type further decides
//  the actual default account:
//    custody         → 148 Custody Advances to Employees (receivable)
//    advance_salary  → 147 Advances to Employees (receivable)
//    per_diem        → no default — an outright travel expense, user
//                       picks any expense account, same as Other's
//                       "no default" treatment. Never a balance to
//                       settle.
//    reimbursement   → no default (added 2026-09, Employee Expense
//                       Reimbursements) — an outright expense same as
//                       per_diem, confirmed with Mahmoud: user always
//                       picks the specific expense account themselves
//                       rather than the system guessing one from the
//                       employee's department. 522/532/543 exist as
//                       suggested starting points in the Chart of
//                       Accounts but are never auto-applied.
//  See DEFAULT_DEBIT_ACCOUNT_CODE (unchanged, keyed by partner_type
//  for everyone else) and defaultDebitAccountCode() below, which is
//  the actual resolution entry point and layers employee_advance_type
//  on top of it.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, FinancialInstitutions\BankAccount,
//            FinancialInstitutions\CreditFacility, Currency, ChartOfAccount,
//            JournalEntry, ChequePayable, User
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OtherPayment extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    const PARTNER_TYPE_SHAREHOLDER    = 'shareholder';
    const PARTNER_TYPE_EMPLOYEE       = 'employee';
    const PARTNER_TYPE_SISTER_COMPANY = 'sister_company';
    const PARTNER_TYPE_CUSTOMER       = 'customer';
    const PARTNER_TYPE_OTHER          = 'other';

    const ALL_PARTNER_TYPES = [
        self::PARTNER_TYPE_SHAREHOLDER,
        self::PARTNER_TYPE_EMPLOYEE,
        self::PARTNER_TYPE_SISTER_COMPANY,
        self::PARTNER_TYPE_CUSTOMER,
        self::PARTNER_TYPE_OTHER,
    ];

    // Only meaningful when partner_type = employee — see class header.
    const EMPLOYEE_ADVANCE_CUSTODY = 'custody';
    const EMPLOYEE_ADVANCE_SALARY  = 'advance_salary';
    const EMPLOYEE_ADVANCE_PER_DIEM = 'per_diem';
    const EMPLOYEE_ADVANCE_REIMBURSEMENT = 'reimbursement';

    const ALL_EMPLOYEE_ADVANCE_TYPES = [
        self::EMPLOYEE_ADVANCE_CUSTODY,
        self::EMPLOYEE_ADVANCE_SALARY,
        self::EMPLOYEE_ADVANCE_PER_DIEM,
        self::EMPLOYEE_ADVANCE_REIMBURSEMENT,
    ];

    /**
     * Account code per Employee advance type — per_diem AND
     * reimbursement deliberately have no entry (no default; the user
     * always picks the account themselves — see class header and
     * migration 000234).
     */
    const EMPLOYEE_ADVANCE_ACCOUNT_CODE = [
        self::EMPLOYEE_ADVANCE_CUSTODY => '148',
        self::EMPLOYEE_ADVANCE_SALARY  => '147',
    ];

    /** Default Chart of Accounts code suggested per Partner Type — user can always override. Employee is superseded by employeeAdvanceAccountCode() when employee_advance_type is set — see that method. */
    const DEFAULT_DEBIT_ACCOUNT_CODE = [
        self::PARTNER_TYPE_SHAREHOLDER    => '230',
        self::PARTNER_TYPE_EMPLOYEE       => '147', // fallback only — see employeeAdvanceAccountCode()
        self::PARTNER_TYPE_SISTER_COMPANY => '139',
        self::PARTNER_TYPE_CUSTOMER       => '235',
        self::PARTNER_TYPE_OTHER          => null,
    ];

    const MONEY_CASH_PAYMENT      = 'cash_payment';
    const MONEY_OUTGOING_TRANSFER = 'outgoing_transfer';
    const MONEY_PAYABLE_CHEQUE    = 'payable_cheque';

    const SOURCE_BANK_ACCOUNT    = 'bank_account';
    const SOURCE_CREDIT_FACILITY = 'credit_facility';

    protected $fillable = [
        'company_id', 'payment_code', 'partner_id', 'partner_type', 'employee_advance_type',
        'payment_date', 'money_type', 'reference_number',
        'source_type', 'source_bank_account_id', 'source_credit_facility_id',
        'safe_account_id', 'receipt_number', 'debit_account_id',
        'currency_id', 'exchange_rate', 'amount', 'amount_functional',
        'status', 'journal_entry_id', 'internal_notes',
        'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'payment_date'      => 'date',
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

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'debit_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** Only present when money_type = 'payable_cheque'. Shares cheques_payable with Supplier Payment — see migration 000175. */
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

    public function getDisplayNameAttribute(): string
    {
        return $this->payment_code;
    }

    public static function partnerTypeLabel(string $type): string
    {
        return match ($type) {
            self::PARTNER_TYPE_SHAREHOLDER    => 'Shareholder',
            self::PARTNER_TYPE_EMPLOYEE       => 'Employee',
            self::PARTNER_TYPE_SISTER_COMPANY => 'Sister Company',
            self::PARTNER_TYPE_CUSTOMER       => 'Customer',
            self::PARTNER_TYPE_OTHER          => 'Other',
            default => $type,
        };
    }

    public static function employeeAdvanceTypeLabel(string $type): string
    {
        return match ($type) {
            self::EMPLOYEE_ADVANCE_CUSTODY  => 'Custody',
            self::EMPLOYEE_ADVANCE_SALARY   => 'Advance from Salary',
            self::EMPLOYEE_ADVANCE_PER_DIEM => 'Per Diem for Travel',
            self::EMPLOYEE_ADVANCE_REIMBURSEMENT => 'Expense Reimbursement',
            default => $type,
        };
    }

    /**
     * The actual default-account resolution entry point — this is
     * what OtherPaymentController's default-debit-account endpoint
     * should call instead of reading DEFAULT_DEBIT_ACCOUNT_CODE
     * directly, so Employee rows get routed by advance type instead
     * of always landing on 147. Returns null when there's no
     * sensible default (Other, or Employee + per_diem) — the frontend
     * leaves the account picker empty and the user chooses.
     */
    public static function defaultDebitAccountCode(string $partnerType, ?string $employeeAdvanceType = null): ?string
    {
        if ($partnerType === self::PARTNER_TYPE_EMPLOYEE && $employeeAdvanceType) {
            return self::EMPLOYEE_ADVANCE_ACCOUNT_CODE[$employeeAdvanceType] ?? null;
        }

        return self::DEFAULT_DEBIT_ACCOUNT_CODE[$partnerType] ?? null;
    }
}
