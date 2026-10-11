<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ChequePayable
//
//  See migration 000139 for the full design rationale. One row per
//  cheque WE write, 1:1 with WHICHEVER payment issued it. Deliberately
//  simpler state machine than ChequeReceivable: issued → paid only
//  (Mark as Paid + Reset, confirmed by Mahmoud — no send-for-
//  collection or bounce handling).
//
//  THREE POSSIBLE SOURCES (migration 000175 added other_payment_id;
//  migration 000178, August 2026, added cash_expense_payment_id) —
//  per Mahmoud: a cheque from Other Payment or Cash Expense Payment
//  is not a separate list, it belongs in this exact same table/screen
//  as a cheque issued to a supplier.
//
//  FOURTH SOURCE (migration 000191, August 2026) — Reverse Factoring.
//  Different from the first three in two ways: NOT 1:1 (a single
//  Reverse Factoring transaction can issue several cheques covering
//  one liability — reverse_factoring_transaction_id is a plain,
//  non-unique FK), and the payee is a Factoring Company rather than
//  necessarily a Partner (factoring_company_id, with partner_id now
//  nullable for this source only). Exactly one of
//  supplier_payment_id / other_payment_id / cash_expense_payment_id /
//  reverse_factoring_transaction_id is set on any given row (enforced
//  in ChequePayableService).
//
//  The source account (which of our own accounts this draws from)
//  lives on WHICHEVER parent issued it (source_bank_account_id /
//  source_credit_facility_id) — fixed at issue time, not duplicated
//  here. Unlike a Receivable cheque's drawee, which genuinely isn't
//  known until the customer's bank clears it.
//
//  FIFTH SOURCE (Audit C7, Sept 2026) — Opening Balances. A cheque
//  already issued and not yet cleared on go-live day is created here
//  when opening balances are posted (ob_note_payable_id set, no
//  parent payment). The bank it is drawn on is kept directly on the
//  row as ob_source_account_id (a 131/132 GL account), since there is
//  no parent payment to read it from.
//
//  CURRENCY (Audit C8, Sept 2026) — currency_id / exchange_rate /
//  amount_functional record the cheque's own currency and the EGP
//  value it was booked into 222 at. Older rows leave them NULL;
//  currencyIdResolved() / bookedRate() / bookedFunctional() fall back
//  to the parent payment.
//
//  @layer    Model
//  @depends  BelongsToCompany, SupplierPayment, OtherPayment,
//            CashExpensePayment, FinancialInstitutions\ReverseFactoringTransaction,
//            FinancialInstitutions\FactoringCompany, Partner, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\FactoringCompany;
use App\Models\FinancialInstitutions\ReverseFactoringTransaction;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChequePayable extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    // Fix: same class of issue as ChequeReceivable — Laravel's default
    // table-name guess would be 'cheque_payables'; the actual table
    // (migration 000139) is 'cheques_payable', pluralizing "cheque"
    // only, matching "Cheques Payable" everywhere else in the UI.
    protected $table = 'cheques_payable';

    const STATUS_ISSUED = 'issued';
    const STATUS_PAID   = 'paid';
    const STATUS_VOID    = 'void';     // M-31: cheque cancelled/torn up, never presented
    const STATUS_STOPPED = 'stopped';  // M-31: payment stopped at the bank (lost / disputed)

    protected $fillable = [
        'company_id', 'supplier_payment_id', 'other_payment_id', 'cash_expense_payment_id',
        'reverse_factoring_transaction_id', 'partner_id', 'factoring_company_id',
        'ob_note_payable_id', 'ob_source_account_id',
        'currency_id', 'exchange_rate', 'amount_functional',
        'cheque_amount', 'cheque_number', 'due_date', 'status',
        'paid_journal_entry_id', 'paid_at', 'void_reason', 'voided_at', 'voided_by',
        'created_by',
    ];

    protected $casts = [
        'cheque_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'amount_functional' => 'decimal:2',
        'due_date'      => 'date',
        'paid_at'       => 'datetime',
    ];

    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    public function otherPayment(): BelongsTo
    {
        return $this->belongsTo(OtherPayment::class);
    }

    public function cashExpensePayment(): BelongsTo
    {
        return $this->belongsTo(CashExpensePayment::class);
    }

    public function reverseFactoringTransaction(): BelongsTo
    {
        return $this->belongsTo(ReverseFactoringTransaction::class);
    }

    public function factoringCompany(): BelongsTo
    {
        return $this->belongsTo(FactoringCompany::class);
    }

    /** True when this cheque was issued through Other Payment rather than Supplier Payment or Cash Expense Payment. */
    public function isFromOtherPayment(): bool
    {
        return ! is_null($this->other_payment_id);
    }

    /** True when this cheque was issued through Cash Expense Payment. */
    public function isFromCashExpensePayment(): bool
    {
        return ! is_null($this->cash_expense_payment_id);
    }

    /** True when this cheque was issued through a Reverse Factoring transaction — payee is a Factoring Company, not a Partner. */
    public function isFromReverseFactoring(): bool
    {
        return ! is_null($this->reverse_factoring_transaction_id);
    }

    /** True when this cheque was created from the Opening Balances screen (Audit C7). */
    public function isFromOpeningBalance(): bool
    {
        return ! is_null($this->ob_note_payable_id);
    }

    public function obSourceAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'ob_source_account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
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
            $source instanceof SupplierPayment    => $source->pay_currency_id ? (int) $source->pay_currency_id : null,
            $source instanceof OtherPayment,
            $source instanceof CashExpensePayment => $source->currency_id ? (int) $source->currency_id : null,
            default                               => null, // reverse factoring & unknown: booked in functional
        };
    }

    /** Rate the cheque was booked into 222 at ('1' if functional / unknown). */
    public function bookedRate(): string
    {
        if ($this->exchange_rate && bccomp((string) $this->exchange_rate, '0', 6) > 0) {
            return (string) $this->exchange_rate;
        }

        $source = $this->source();
        if ($source instanceof ReverseFactoringTransaction || ! $source) {
            return '1';
        }

        $rate = $source->exchange_rate;

        return $rate && bccomp((string) $rate, '0', 6) > 0 ? (string) $rate : '1';
    }

    /** Functional (EGP) value this cheque sits in 222 at. */
    public function bookedFunctional(): string
    {
        if ($this->amount_functional !== null) {
            return (string) $this->amount_functional;
        }

        // Older rows: the parent payment credited 222 with its own
        // functional figure — use it whenever the cheque is the whole
        // payment, so we clear exactly what was booked.
        $source = $this->source();
        $parentAmount = match (true) {
            $source instanceof CashExpensePayment => [(string) $source->net_amount, (string) $source->net_amount_functional],
            $source instanceof SupplierPayment,
            $source instanceof OtherPayment       => [(string) $source->amount, (string) $source->amount_functional],
            default                               => null,
        };
        if ($parentAmount && bccomp($parentAmount[0], (string) $this->cheque_amount, 2) === 0 && $parentAmount[1] !== '') {
            return $parentAmount[1];
        }

        return bcadd(bcmul((string) $this->cheque_amount, $this->bookedRate(), 8), '0.005', 2);
    }

    /** Source-agnostic accessor — whichever parent record actually issued this cheque (null for an opening-balance cheque). */
    public function source(): SupplierPayment|OtherPayment|CashExpensePayment|ReverseFactoringTransaction|null
    {
        if ($this->isFromOpeningBalance()) {
            return null;
        }

        return match (true) {
            $this->isFromReverseFactoring()   => $this->reverseFactoringTransaction,
            $this->isFromCashExpensePayment() => $this->cashExpensePayment,
            $this->isFromOtherPayment()       => $this->otherPayment,
            default                            => $this->supplierPayment,
        };
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function paidJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'paid_journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ISSUED);
    }

    public function isIssued(): bool { return $this->status === self::STATUS_ISSUED; }
    public function isPaid(): bool   { return $this->status === self::STATUS_PAID; }
    public function isVoided(): bool { return in_array($this->status, [self::STATUS_VOID, self::STATUS_STOPPED], true); }
}
