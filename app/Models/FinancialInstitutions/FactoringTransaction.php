<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FactoringTransaction
//
//  One invoice sold/advanced under a Factoring Agreement. See
//  migration 2026_01_01_000186 for the full CashVero → Vero CFO
//  field mapping and the reasoning for snapshotting invoice_amount /
//  contract_interest_rate rather than reading them live.
//
//  calculateAmounts() is ported from CashVero's
//  FactoringTransaction::calculateAmounts() with its already-fixed
//  Carbon 3 sign bug kept fixed (absolute day-count — the invoice's
//  due date is normally LATER than the factoring date, so an
//  unsigned diffInDays is correct here, not an edge case).
//
//  @layer    Model
//  @depends  FactoringAgreement, CustomerInvoice, Partner, BankAccount,
//            JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\CustomerInvoice;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactoringTransaction extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'factoring_transactions';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_COLLECTED = 'collected';
    public const STATUS_REJECTED  = 'rejected';

    protected $fillable = [
        'company_id', 'factoring_agreement_id', 'customer_invoice_id', 'partner_id',
        'factoring_date', 'invoice_amount', 'invoice_due_date', 'factoring_percentage',
        'factoring_amount', 'contract_interest_rate', 'diff_in_days',
        'factoring_interest_amount', 'other_charges', 'received_amount',
        'bank_account_id', 'journal_entry_id', 'status',
        'collection_date', 'collection_difference_amount', 'collection_journal_entry_id', 'collection_bank_account_id',
        'rejection_date', 'uncollected_invoice_charges', 'rejection_journal_entry_id', 'rejection_bank_account_id',
        'notes', 'created_by',
    ];

    protected $casts = [
        'factoring_date'                => 'date',
        'invoice_due_date'               => 'date',
        'invoice_amount'                 => 'decimal:2',
        'factoring_percentage'           => 'decimal:2',
        'factoring_amount'               => 'decimal:2',
        'contract_interest_rate'         => 'decimal:4',
        'diff_in_days'                   => 'integer',
        'factoring_interest_amount'      => 'decimal:2',
        'other_charges'                  => 'decimal:2',
        'received_amount'                => 'decimal:2',
        'collection_date'                => 'date',
        'collection_difference_amount'   => 'decimal:2',
        'rejection_date'                 => 'date',
        'uncollected_invoice_charges'    => 'decimal:2',
    ];

    /**
     * H-20 fix — the whole calculation now runs in bcmath (Rule 4),
     * no PHP float arithmetic on money. Same formula as before:
     *   advance  = invoice × % / 100
     *   interest = advance × rate% / 360 × days   (360-day Egyptian convention)
     *   received = advance − interest − other charges
     * Amounts are accepted as numeric strings (floats still work —
     * they are converted safely) and returned as 2-decimal strings.
     * Day count stays absolute (the due date is normally AFTER the
     * factoring date — that is the point of factoring).
     */
    public static function calculateAmounts(
        string|float|int $invoiceAmount,
        string|float|int $factoringPercentage,
        string|float|int $contractInterestRate,
        string|float|int $otherCharges,
        string $factoringDate,
        string $invoiceDueDate
    ): array {
        $invoiceAmount        = self::num($invoiceAmount);
        $factoringPercentage  = self::num($factoringPercentage);
        $contractInterestRate = self::num($contractInterestRate);
        $otherCharges         = self::num($otherCharges);

        $advance = bcdiv(bcmul($invoiceAmount, $factoringPercentage, 10), '100', 10);

        $dueDate    = Carbon::parse($invoiceDueDate)->startOfDay();
        $factorDate = Carbon::parse($factoringDate)->startOfDay();
        $diffInDays = (int) $dueDate->diffInDays($factorDate, true);

        $interest = bcmul(
            bcdiv(bcdiv(bcmul($advance, $contractInterestRate, 10), '100', 10), '360', 10),
            (string) $diffInDays,
            10
        );

        $factoringAmount = self::bcRound($advance);
        $interestAmount  = self::bcRound($interest);
        $received        = bcsub(bcsub($factoringAmount, $interestAmount, 2), self::bcRound($otherCharges), 2);

        return [
            'factoring_amount'          => $factoringAmount,
            'diff_in_days'              => $diffInDays,
            'factoring_interest_amount' => $interestAmount,
            'received_amount'           => $received,
        ];
    }

    /**
     * H-20 / M29 — interest is always recomputed from the agreed rate.
     * A typed-in figure is accepted only when it is within 1% of the
     * computed one (so a bank's own small rounding is fine); anything
     * further away is refused. Returns the 2-decimal figure to post.
     */
    public static function resolveInterest(string $computed, string|float|int|null $typed): string
    {
        if ($typed === null || $typed === '') {
            return $computed;
        }

        $typed = self::bcRound(self::num($typed));
        $diff  = ltrim(bcsub($typed, $computed, 2), '-');
        $tol   = bcmul($computed, '0.01', 4);

        // Always allow a difference of up to 1.00 for very small amounts.
        if (bccomp($diff, $tol, 4) > 0 && bccomp($diff, '1.00', 2) > 0) {
            throw new \DomainException(
                "The interest amount ({$typed}) does not match the agreement's rate. "
                ."The system calculates {$computed} from the agreed rate and days — a difference above 1% is not accepted."
            );
        }

        return $typed;
    }

    /** Safe numeric-string conversion (never scientific notation). */
    public static function num(string|float|int|null $v): string
    {
        if ($v === null || $v === '') {
            return '0';
        }
        if (is_float($v)) {
            return number_format($v, 10, '.', '');
        }

        return (string) $v;
    }

    /** Round half away from zero to $scale decimals using bcmath. */
    public static function bcRound(string $number, int $scale = 2): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';

        return bccomp($number, '0', $scale + 10) >= 0
            ? bcadd($number, $half, $scale)
            : bcsub($number, $half, $scale);
    }

    public function factoringAgreement(): BelongsTo { return $this->belongsTo(FactoringAgreement::class); }
    public function customerInvoice(): BelongsTo    { return $this->belongsTo(CustomerInvoice::class); }
    public function partner(): BelongsTo            { return $this->belongsTo(Partner::class); }
    public function bankAccount(): BelongsTo         { return $this->belongsTo(BankAccount::class); }
    public function collectionBankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'collection_bank_account_id'); }
    public function rejectionBankAccount(): BelongsTo  { return $this->belongsTo(BankAccount::class, 'rejection_bank_account_id'); }
    public function journalEntry(): BelongsTo        { return $this->belongsTo(JournalEntry::class); }
    public function collectionJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'collection_journal_entry_id'); }
    public function rejectionJournalEntry(): BelongsTo  { return $this->belongsTo(JournalEntry::class, 'rejection_journal_entry_id'); }
    public function createdBy(): BelongsTo           { return $this->belongsTo(User::class, 'created_by'); }

    public function scopePending(Builder $query): Builder { return $query->where('status', self::STATUS_PENDING); }

    public function isPending(): bool   { return $this->status === self::STATUS_PENDING; }
    public function isCollected(): bool { return $this->status === self::STATUS_COLLECTED; }
    public function isRejected(): bool  { return $this->status === self::STATUS_REJECTED; }
    public function isDraft(): bool     { return $this->status === self::STATUS_DRAFT; }
}
