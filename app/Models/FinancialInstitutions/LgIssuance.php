<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LgIssuance
//
//  An individual issued Letter of Guarantee. See migration 000066
//  header for the full design rationale (issuance_type vs
//  posting_mode, current_amount vs lg_amount, computed status).
//
//  @layer    Model
//  @depends  BelongsToCompany, CreditFacility, FacilityLgType, Bank,
//            Currency, Partner, Project, SalesOrder, BankAccount,
//            LgIssuanceHistory, LgIssuanceMovement
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Partner;
use App\Models\Project;
use App\Models\SalesOrder;
use App\Models\Traits\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\SerializesDateOnly;

class LgIssuance extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'lg_issuances';

    // Beneficiary rule reference (enforced in LgIssuanceService, not here) —
    // Bid Bond: customer or other. Final/Advanced/Performance: customer only,
    // with an active Project or Sales Order.
    public const TYPES_REQUIRING_CUSTOMER_ONLY = ['Final LG', 'Advanced Payment LG', 'Performance LG'];

    public const POSTING_STATUS_DRAFT  = 'draft';
    public const POSTING_STATUS_POSTED = 'posted';

    public const ISSUANCE_TYPES = [
        'facility'     => 'LG Against LG Facility',
        'cash_cover'   => 'LG 100% Cash Cover',
        'time_deposit' => 'LG Against Time Deposit',
    ];

    protected $fillable = [
        'company_id',
        'issuance_type',
        'posting_mode',
        'credit_facility_id',
        'facility_lg_type_id',
        'lg_type',
        'transaction_name',
        'bank_id',
        'currency_id',
        'lg_code',
        'beneficiary_partner_id',
        'contract_reference_project_id',
        'purchase_order_so_id',
        'purchase_order_date',
        'issuance_date',
        'end_date',
        'renewed_at',
        'duration_months',
        'lg_amount',
        'current_amount',
        'cash_cover_pct',
        'cash_cover_amount',
        'cash_cover_currency_id',
        'commission_fees_currency_id',
        'commission_rate_pct',
        'commission_amount',
        'min_commission_fee_amount',
        'issuance_fee_amount',
        'commission_interval',
        'next_commission_accrual_date',
        'last_commission_accrual_date',
        'cash_cover_account_id',
        'fees_commission_account_id',
        'time_deposit_account_id',
        'status',
        'posting_status',
        'cancelled_at',
        'cancelled_reversal_account_id',
        'liquidated_at',
        'liquidation_shortfall_amount',
        'liquidation_expense_account_id',
        'liquidation_shortfall_account_id',
        'comment',
        'created_by',
    ];

    protected $casts = [
        'purchase_order_date'   => 'date',
        'issuance_date'         => 'date',
        'end_date'              => 'date',
        'renewed_at'            => 'date',
        'next_commission_accrual_date' => 'date',
        'last_commission_accrual_date' => 'date',
        'duration_months'       => 'integer',
        'lg_amount'             => 'decimal:2',
        'current_amount'        => 'decimal:2',
        'cash_cover_pct'        => 'decimal:2',
        'cash_cover_amount'     => 'decimal:2',
        'commission_rate_pct'   => 'decimal:4',
        'commission_amount'     => 'decimal:2',
        'min_commission_fee_amount' => 'decimal:2',
        'issuance_fee_amount'   => 'decimal:2',
        'cancelled_at'          => 'date',
        'liquidated_at'         => 'date',
        'liquidation_shortfall_amount' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function creditFacility(): BelongsTo   { return $this->belongsTo(CreditFacility::class); }
    public function facilityLgType(): BelongsTo   { return $this->belongsTo(FacilityLgType::class); }
    public function bank(): BelongsTo             { return $this->belongsTo(Bank::class); }
    public function currency(): BelongsTo         { return $this->belongsTo(Currency::class); }
    public function cashCoverCurrency(): BelongsTo { return $this->belongsTo(Currency::class, 'cash_cover_currency_id'); }
    public function commissionFeesCurrency(): BelongsTo { return $this->belongsTo(Currency::class, 'commission_fees_currency_id'); }
    public function beneficiary(): BelongsTo      { return $this->belongsTo(Partner::class, 'beneficiary_partner_id'); }
    public function contractProject(): BelongsTo  { return $this->belongsTo(Project::class, 'contract_reference_project_id'); }
    public function purchaseOrder(): BelongsTo    { return $this->belongsTo(SalesOrder::class, 'purchase_order_so_id'); }
    public function cashCoverAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'cash_cover_account_id'); }
    public function feesCommissionAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'fees_commission_account_id'); }
    public function timeDepositAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'time_deposit_account_id'); }
    public function cancelledReversalAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'cancelled_reversal_account_id'); }
    public function liquidationShortfallAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'liquidation_shortfall_account_id'); }
    public function liquidationExpenseAccount(): BelongsTo { return $this->belongsTo(\App\Models\ChartOfAccount::class, 'liquidation_expense_account_id'); }
    public function createdBy(): BelongsTo        { return $this->belongsTo(User::class, 'created_by'); }

    public function history(): HasMany
    {
        return $this->hasMany(LgIssuanceHistory::class)->orderByDesc('event_date');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(LgIssuanceMovement::class)->orderBy('movement_date');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeOfLgType(Builder $query, string $lgType): Builder
    {
        return $query->where('lg_type', $lgType);
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', 'running');
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isAdvancedPaymentLg(): bool
    {
        return $this->lg_type === 'Advanced Payment LG';
    }

    public function hasBeenRenewed(): bool
    {
        return $this->renewed_at !== null;
    }

    public function isPosted(): bool
    {
        return $this->posting_status === self::POSTING_STATUS_POSTED;
    }

    public function isDraft(): bool
    {
        return $this->posting_status === self::POSTING_STATUS_DRAFT;
    }

    /**
     * "Untouched since issuance" — no Renewal / Decrease / Cancel /
     * Liquidate has ever happened (or every one that did has since
     * been undone). This is the ONLY state "Set to Draft" is allowed
     * from — once real lifecycle history exists, correcting a
     * mistake goes through that specific action's own Undo instead
     * (see LgIssuanceService::undoRenewal()/undoDecrease()/
     * undoCancel()/undoLiquidate()), never by reopening the whole
     * original issuance underneath it.
     */
    public function isPristine(): bool
    {
        return ! $this->history()->whereNull('undone_at')->exists();
    }

    /**
     * The single history row eligible for "Undo" right now — the
     * most recent event that hasn't already been undone. Correction
     * only ever peels back one layer at a time, in reverse order.
     */
    public function undoableHistoryEntry(): ?LgIssuanceHistory
    {
        // Deliberately NOT $this->history() — that relation orders by
        // event_date, which a user can backdate. "Most recent action"
        // for undo purposes means insertion order (id desc), so a
        // backdated event never jumps the correction queue.
        return LgIssuanceHistory::where('lg_issuance_id', $this->id)
            ->whereNull('undone_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Re-derives running/expired from end_date. Cancelled is a
     * manual, sticky status — never overwritten by this check.
     * Called on read (accessor), not stored automatically on a
     * schedule — see LgIssuanceService::syncStatus() for where
     * this actually gets persisted back to the row.
     */
    public function computedStatus(): string
    {
        // Audit L-14: a liquidated LG is stored as status 'cancelled' with
        // liquidated_at set — report it as what it is.
        if ($this->status === 'cancelled') {
            return $this->liquidated_at ? 'liquidated' : 'cancelled';
        }

        return $this->end_date && $this->end_date->isPast() ? 'expired' : 'running';
    }
}
