<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  Plan
//
//  One row per Annual Plan or Business Plan & Valuation — see
//  migration 000239 header for the full design rationale. Every
//  wizard-step table (PlanProduct, PlanSalesLine, PlanCogsAssumption,
//  PlanManpowerTitle, PlanExpenseLine, PlanFixedAsset) hangs off
//  plan_id.
//
//  @layer    Model
//  @depends  BelongsToCompany, Currency, User, PlanProduct,
//            PlanManpowerTitle, PlanExpenseLine, PlanFixedAsset
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    public const TYPE_ANNUAL        = 'annual';
    public const TYPE_BUSINESS_PLAN = 'business_plan';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_COMPLETED = 'completed';

    public const OB_SOURCE_AUTO   = 'auto';
    public const OB_SOURCE_MANUAL = 'manual';
    public const OB_SOURCE_NONE   = 'none';

    public const ANNUAL_DURATION_YEARS = 1;
    public const BUSINESS_PLAN_MIN_YEARS = 3;
    public const BUSINESS_PLAN_MAX_YEARS = 10;

    protected $fillable = [
        'company_id',
        'plan_code',
        'plan_type',
        'name',
        'currency_id',
        'start_date',
        'duration_years',
        'end_date',
        'business_type',
        'corporate_tax_rate',
        'wacc_pct',
        'perpetual_growth_pct',
        'manpower_annual_increase_pct',
        'valuation_basis',
        'valuation_multiplier',
        'opening_balance_source',
        'opening_balance_as_of_date',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_date'                  => 'date',
        'end_date'                    => 'date',
        'opening_balance_as_of_date'  => 'date',
        'duration_years'              => 'integer',
        'corporate_tax_rate'          => 'decimal:4',
        'wacc_pct'                    => 'decimal:4',
        'perpetual_growth_pct'        => 'decimal:4',
        'manpower_annual_increase_pct'=> 'decimal:4',
        'valuation_multiplier'        => 'decimal:2',
    ];

    // ── Auto-code ─────────────────────────────────────────────────

    public static function nextCode(int $companyId, string $planType): string
    {
        $prefix = $planType === self::TYPE_ANNUAL ? 'AP-' : 'BP-';

        $last = static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('plan_type', $planType)
            ->where('plan_code', 'like', $prefix . '%')
            ->orderByRaw('CAST(SUBSTRING(plan_code, ' . (strlen($prefix) + 1) . ') AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->value('plan_code');

        if (! $last) {
            return $prefix . '00001';
        }

        $number = (int) substr($last, strlen($prefix));

        return $prefix . str_pad($number + 1, 5, '0', STR_PAD_LEFT);
    }

    // ── Relationships ─────────────────────────────────────────────

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function products(): HasMany
    {
        return $this->hasMany(PlanProduct::class)->orderBy('sort_order');
    }

    public function manpowerTitles(): HasMany
    {
        return $this->hasMany(PlanManpowerTitle::class);
    }

    public function expenseLines(): HasMany
    {
        return $this->hasMany(PlanExpenseLine::class);
    }

    public function fixedAssets(): HasMany
    {
        return $this->hasMany(PlanFixedAsset::class);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isAnnual(): bool
    {
        return $this->plan_type === self::TYPE_ANNUAL;
    }

    public function isBusinessPlan(): bool
    {
        return $this->plan_type === self::TYPE_BUSINESS_PLAN;
    }

    public function totalMonths(): int
    {
        return (int) $this->duration_years * 12;
    }
}
