<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  ReverseFactoringTransaction
//
//  A Supplier Bill paid early by a factoring company on the company's
//  behalf (COA doc Section 8.3). See migration 2026_01_01_000190 for
//  the full posting rule and field mapping. Sibling of
//  FactoringTransaction (AR side) — same calculateAmounts() formula,
//  reused as-is (percentage of the bill, interest by rate × days,
//  charges, net vs gross), just pointed at AP instead of AR.
//
//  @layer    Model
//  @depends  FactoringAgreement, SupplierBill, Partner, BankAccount,
//            JournalEntry, ChequePayable, User
// ══════════════════════════════════════════════════════════════════

use App\Models\ChequePayable;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\SupplierBill;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReverseFactoringTransaction extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'reverse_factoring_transactions';

    public const STATUS_DRAFT   = 'draft';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SETTLED = 'settled';

    public const SETTLEMENT_DUE_DATE = 'due_date';
    public const SETTLEMENT_CHEQUES  = 'cheques';

    protected $fillable = [
        'company_id', 'factoring_agreement_id', 'supplier_bill_id', 'partner_id',
        'reverse_factoring_date', 'bill_amount', 'reverse_due_date',
        'reverse_factoring_percentage', 'reverse_factoring_amount', 'contract_interest_rate',
        'diff_in_days', 'reverse_factoring_interest_amount', 'reverse_factoring_charges',
        'ap_clear_amount', 'settlement_method', 'source_bank_account_id', 'journal_entry_id',
        'status', 'settlement_date', 'settlement_journal_entry_id', 'notes', 'created_by',
    ];

    protected $casts = [
        'reverse_factoring_date'             => 'date',
        'bill_amount'                        => 'decimal:2',
        'reverse_due_date'                   => 'date',
        'reverse_factoring_percentage'       => 'decimal:2',
        'reverse_factoring_amount'           => 'decimal:2',
        'contract_interest_rate'             => 'decimal:4',
        'diff_in_days'                       => 'integer',
        'reverse_factoring_interest_amount'  => 'decimal:2',
        'reverse_factoring_charges'          => 'decimal:2',
        'ap_clear_amount'                    => 'decimal:2',
        'settlement_date'                    => 'date',
    ];

    /** Same formula as FactoringTransaction::calculateAmounts() — see that method's own docblock. */
    public static function calculateAmounts(
        string|float|int $billAmount,
        string|float|int $reverseFactoringPercentage,
        string|float|int $contractInterestRate,
        string|float|int $charges,
        string $reverseFactoringDate,
        string $reverseDueDate
    ): array {
        return FactoringTransaction::calculateAmounts(
            $billAmount, $reverseFactoringPercentage, $contractInterestRate, $charges, $reverseFactoringDate, $reverseDueDate
        );
    }

    /**
     * H-20 — what the company owes the factoring company at settlement:
     * the advance PLUS the interest and charges (the company bears the
     * finance cost). Always derived with bcmath, never stored twice.
     */
    public function totalRepayable(): string
    {
        return bcadd(
            bcadd((string) $this->reverse_factoring_amount, (string) $this->reverse_factoring_interest_amount, 2),
            (string) $this->reverse_factoring_charges,
            2
        );
    }

    public function factoringAgreement(): BelongsTo { return $this->belongsTo(FactoringAgreement::class); }
    public function supplierBill(): BelongsTo        { return $this->belongsTo(SupplierBill::class); }
    public function partner(): BelongsTo              { return $this->belongsTo(Partner::class); }
    public function sourceBankAccount(): BelongsTo    { return $this->belongsTo(BankAccount::class, 'source_bank_account_id'); }
    public function journalEntry(): BelongsTo         { return $this->belongsTo(JournalEntry::class); }
    public function settlementJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'settlement_journal_entry_id'); }
    public function createdBy(): BelongsTo            { return $this->belongsTo(User::class, 'created_by'); }
    public function cheques(): HasMany                { return $this->hasMany(ChequePayable::class, 'reverse_factoring_transaction_id'); }

    public function scopePending(Builder $query): Builder { return $query->where('status', self::STATUS_PENDING); }

    public function isDraft(): bool   { return $this->status === self::STATUS_DRAFT; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
    public function isSettled(): bool { return $this->status === self::STATUS_SETTLED; }
    public function isDueDateSettlement(): bool { return $this->settlement_method === self::SETTLEMENT_DUE_DATE; }
    public function isChequeSettlement(): bool  { return $this->settlement_method === self::SETTLEMENT_CHEQUES; }
}
