<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LeasingContract
//
//  Leasing contracts — operational (Treatment A or B) or capital.
//
//  Treatment rules (Rule 29):
//    operational + expense_a  → pure expense, no Balance Sheet entries
//    operational + rou_b      → IFRS 16 ROU asset + lease liability
//    capital (no treatment)   → always IFRS 16 finance lease
//
//  accounting_treatment is LOCKED once status changes to 'active'.
//
//  v2 (migration 000075) — form corrections confirmed by Mahmoud:
//    - entry_type ('new'/'existing') added. NOT the same concept as
//      contract_type (operational/capital) — entry_type instead
//      drives whether a liability-recognition posting happens at
//      all. 'existing' → no action ever. 'new' → real event, needs
//      a pending-GL-posting entry once the Accounting Engine exists
//      (see ContractForm.vue for the full plan).
//    - monthly_payment renamed to installment_amount; new
//      installment_interval column added alongside it.
//    - bank_liability_account_id added. NOTE: lease_liability_lt_
//      account_id and lease_liability_current_account_id already
//      existed on this table, unused by any form — flagged to
//      Mahmoud as a possible overlap, not silently resolved.
//
//  @layer    Model
//  @depends  LeasingCompany, BankAccount, ChartOfAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\SerializesDateOnly;

class LeasingContract extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'leasing_contracts';

    protected $fillable = [
        'company_id', 'contract_reference', 'leasing_company_id', 'contract_type',
        'entry_type', // NEW — 'new'/'existing', drives future liability posting, see migration 000075 header
        'asset_description', 'currency_id', 'exchange_rate',
        'accounting_treatment', 'expense_account_id',
        'total_contract_value', 'installment_interval', 'installment_amount', 'duration_months', 'borrowing_rate',
        'start_date', 'end_date', 'first_payment_date', 'purchase_date',
        'total_financed_value', 'payment_frequency', 'asset_category_id',
        'opening_balance_amount', 'opening_balance_as_of_date', 'opening_remaining_installments',
        'rou_asset_account_id', 'rou_amort_account_id', 'fixed_asset_account_id',
        'accumulated_depreciation_account_id',
        'lease_liability_lt_account_id', 'lease_liability_current_account_id',
        'bank_liability_account_id', // NEW — see migration 000075 header re: the two dormant fields above
        'bank_account_id', 'initial_rou_value', 'initial_lease_liability',
        'status', 'activated_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'total_contract_value'     => 'decimal:2',
        'installment_amount'       => 'decimal:2', // renamed from monthly_payment — migration 000075
        'exchange_rate'            => 'decimal:6',
        'borrowing_rate'           => 'decimal:4',
        'total_financed_value'     => 'decimal:2',
        'opening_balance_amount'   => 'decimal:2',
        'opening_balance_as_of_date' => 'date',
        'initial_rou_value'        => 'decimal:2',
        'initial_lease_liability'  => 'decimal:2',
        'start_date'               => 'date',
        'end_date'                 => 'date',
        'first_payment_date'       => 'date',
        'purchase_date'            => 'date',
        'activated_at'             => 'datetime',
    ];

    public function leasingCompany(): BelongsTo { return $this->belongsTo(LeasingCompany::class); }
    public function bankAccount(): BelongsTo    { return $this->belongsTo(BankAccount::class); }
    public function currency(): BelongsTo       { return $this->belongsTo(Currency::class); }
    public function amortizationLines(): HasMany { return $this->hasMany(LeaseAmortizationLine::class)->orderBy('period_number'); }
    public function wearDownEntries(): HasMany   { return $this->hasMany(LeaseWearDownEntry::class)->orderBy('period_number'); }
    public function cheques(): HasMany          { return $this->hasMany(LeaseCheque::class, 'leasing_contract_id')->orderBy('payment_date'); }

    /** The liability account chosen on the form's new 4-column row (Start/End Date/Liability Account/Payment Account). */
    public function bankLiabilityAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'bank_liability_account_id');
    }

    public function scopeActive(Builder $query): Builder { return $query->where('status', 'active'); }

    public function isCapital(): bool      { return $this->contract_type === 'capital'; }
    public function isOperational(): bool  { return $this->contract_type === 'operational'; }
    public function isTreatmentA(): bool   { return $this->accounting_treatment === 'expense_a'; }
    public function isTreatmentB(): bool   { return $this->accounting_treatment === 'rou_b'; }
    public function usesIfrs16(): bool     { return $this->isCapital() || $this->isTreatmentB(); }

    public function isDraft(): bool  { return $this->status === 'draft'; }
    public function isActive(): bool { return $this->status === 'active'; }

    /** NEW entry — see migration 000075 header. 'existing' contracts never trigger a liability posting. */
    public function isNewEntry(): bool { return $this->entry_type === 'new'; }
    public function isExistingEntry(): bool { return $this->entry_type === 'existing'; }
}
