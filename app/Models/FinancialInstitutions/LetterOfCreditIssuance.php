<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LetterOfCreditIssuance
//
//  An individual issued Letter of Credit. See migration 000145 header
//  for the full design rationale — ported from CashVero's
//  LetterOfCreditIssuance, adapted for VeroCFO's real GL and the
//  Purchase-cycle linkage CashVero never had. No Odoo, no statement
//  ledgers — real JournalEntry postings via LetterOfCreditIssuanceService.
//
//  @layer    Model
//  @depends  BelongsToCompany, CreditFacility, FacilityLcType, Bank,
//            Currency, Partner, PurchaseOrder, SupplierBill,
//            BankAccount, LcIssuanceHistory, LcIssuanceMovement,
//            LcIssuanceExpense
// ══════════════════════════════════════════════════════════════════

use App\Models\Currency;
use App\Models\Partner;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LetterOfCreditIssuance extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'letter_of_credit_issuances';

    public const ISSUANCE_TYPES = [
        'facility'   => 'LC Against LC Facility',
        'cash_cover' => 'LC 100% Cash Cover',
    ];

    public const STATUS_RUNNING = 'running';
    public const STATUS_PAID    = 'paid';
    public const STATUS_EXPIRED = 'expired'; // H-21: closed unused — cash cover released

    public const FINANCED_BY_BANK = 'bank';
    public const FINANCED_BY_SELF = 'self';

    public const POSTING_STATUS_DRAFT  = 'draft';
    public const POSTING_STATUS_POSTED = 'posted';

    protected $fillable = [
        'company_id',
        'issuance_type',
        'posting_mode',
        'posting_status',
        'credit_facility_id',
        'facility_lc_type_id',
        'lc_type',
        'transaction_name',
        'bank_id',
        'currency_id',
        'exchange_rate',
        'amount_in_payment_currency',
        'lc_code',
        'supplier_partner_id',
        'purchase_order_id',
        'supplier_bill_id',
        'issuance_date',
        'due_date',
        'duration_days',
        'lc_amount',
        'cash_cover_pct',
        'cash_cover_amount',
        'cash_cover_currency_id',
        'commission_rate_pct',
        'commission_amount',
        'min_commission_fee_amount',
        'issuance_fee_amount',
        'cash_cover_account_id',
        'cash_cover_released_amount',
        'cash_cover_released_at',
        'cash_cover_release_mode',
        'fees_commission_account_id',
        'financed_by',
        'financing_duration_days',
        'bank_debt_outstanding',
        'status',
        'paid_at',
        'payment_account_id',
        'comment',
        'created_by',
    ];

    protected $casts = [
        'issuance_date'             => 'date',
        'due_date'                  => 'date',
        'duration_days'             => 'integer',
        'lc_amount'                 => 'decimal:2',
        'exchange_rate'             => 'decimal:6',
        'amount_in_payment_currency' => 'decimal:2',
        'cash_cover_pct'            => 'decimal:2',
        'cash_cover_amount'         => 'decimal:2',
        'commission_rate_pct'       => 'decimal:4',
        'commission_amount'         => 'decimal:2',
        'min_commission_fee_amount' => 'decimal:2',
        'issuance_fee_amount'       => 'decimal:2',
        'financing_duration_days'   => 'integer',
        'bank_debt_outstanding'     => 'decimal:2',
        'paid_at'                   => 'date',
        'cash_cover_released_amount' => 'decimal:2',
        'cash_cover_released_at'    => 'date',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function creditFacility(): BelongsTo  { return $this->belongsTo(CreditFacility::class); }
    public function facilityLcType(): BelongsTo  { return $this->belongsTo(FacilityLcType::class); }
    public function bank(): BelongsTo            { return $this->belongsTo(Bank::class); }
    public function currency(): BelongsTo        { return $this->belongsTo(Currency::class); }
    public function cashCoverCurrency(): BelongsTo { return $this->belongsTo(Currency::class, 'cash_cover_currency_id'); }
    public function supplier(): BelongsTo        { return $this->belongsTo(Partner::class, 'supplier_partner_id'); }
    public function purchaseOrder(): BelongsTo   { return $this->belongsTo(PurchaseOrder::class); }
    public function supplierBill(): BelongsTo    { return $this->belongsTo(SupplierBill::class); }
    public function cashCoverAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'cash_cover_account_id'); }
    public function feesCommissionAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'fees_commission_account_id'); }
    public function paymentAccount(): BelongsTo  { return $this->belongsTo(BankAccount::class, 'payment_account_id'); }
    public function createdBy(): BelongsTo       { return $this->belongsTo(User::class, 'created_by'); }

    public function history(): HasMany
    {
        return $this->hasMany(LcIssuanceHistory::class, 'lc_issuance_id')->orderByDesc('event_date');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(LcIssuanceMovement::class, 'lc_issuance_id')->orderBy('movement_date');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(LcIssuanceExpense::class, 'lc_issuance_id')->orderByDesc('expense_date');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeOfLcType(Builder $query, string $lcType): Builder
    {
        return $query->where('lc_type', $lcType);
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RUNNING);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isFinancedByBank(): bool { return $this->financed_by === self::FINANCED_BY_BANK; }
    public function isFinancedBySelf(): bool { return $this->financed_by === self::FINANCED_BY_SELF; }
    public function isPaid(): bool           { return $this->status === self::STATUS_PAID; }

    public function isPosted(): bool { return $this->posting_status === self::POSTING_STATUS_POSTED; }
    public function isDraft(): bool  { return $this->posting_status === self::POSTING_STATUS_DRAFT; }

    /**
     * "Untouched since issuance" — no Mark as Paid / Settlement has
     * ever happened (or every one that did has since been undone).
     * The ONLY state Set to Draft is allowed from — see
     * LgIssuance::isPristine() for the identical rule on LG.
     */
    public function isPristine(): bool
    {
        return ! $this->history()->whereNull('undone_at')->exists();
    }

    /**
     * The single history row eligible for Undo right now — most
     * recent non-undone event, by insertion order (not event_date,
     * which a user can backdate) — same rule as LgIssuance.
     */
    public function undoableHistoryEntry(): ?LcIssuanceHistory
    {
        return LcIssuanceHistory::where('lc_issuance_id', $this->id)
            ->whereNull('undone_at')
            ->orderByDesc('id')
            ->first();
    }
}
