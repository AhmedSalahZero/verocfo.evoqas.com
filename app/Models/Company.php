<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  Company
//
//  Core tenant model for Vero CFO multi-tenancy architecture.
//  Every authenticated request is scoped to one Company.
//
//  Key additions in v1.1:
//  — opening_balance_date: required field set by Super Admin at
//    company creation. Defines the cut-off between historical
//    data and live Vero CFO transactions. Used to auto-generate
//    the first fiscal year.
//
//  @layer    Model
//  @table    companies
//  @depends  App\Models\BusinessSector
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Company extends Model
{
    use \App\Models\Traits\SerializesDateOnly;

    protected $fillable = [
        'name_en',
        'name_ar',
        'logo_path',
        'tax_registration_number',
        'business_sector_id',
        'legal_structure',
        'company_type',
        'base_currency',
        'fiscal_year_start_month',
        'opening_balance_date',
        'grn_policy',
        'country',
        'timezone',
        'phone',
        'address',
        'parent_company_id',
        'subscription_tier',
        'additional_users',
        'subscription_expires_at',
        'is_active',
    ];

    protected $casts = [
        'fiscal_year_start_month' => 'integer',
        'additional_users'        => 'integer',
        'is_active'               => 'boolean',
        'subscription_expires_at' => 'date',
        'company_type'            => 'array',
        'opening_balance_date'    => 'date',
    ];

    // ── Company Type Constants ────────────────────────────────────

    const TYPE_SERVICE          = 'Service';
    const TYPE_TRADING          = 'Trading';
    const TYPE_MANUFACTURING    = 'Manufacturing';
    const TYPE_SERVICE_WITH_INV = 'Service with Inventory';

    const ALL_TYPES = [
        self::TYPE_SERVICE,
        self::TYPE_TRADING,
        self::TYPE_MANUFACTURING,
        self::TYPE_SERVICE_WITH_INV,
    ];

    // ── GRN Policy Constants (Super Admin, set at company creation) ─

    const GRN_POLICY_PO_REQUIRED = 'po_required';
    const GRN_POLICY_ALLOW_AD_HOC = 'allow_ad_hoc';

    // ── Legal Structure Constants ─────────────────────────────────

    const LEGAL_JSC         = 'Joint Stock Company';
    const LEGAL_LLC         = 'Limited Liability Company';
    const LEGAL_SOLE        = 'Sole Proprietorship';
    const LEGAL_PARTNERSHIP = 'Partnership';
    const LEGAL_LIMITED_P   = 'Limited Partnership';
    const LEGAL_BRANCH      = 'Branch of Foreign Company';
    const LEGAL_HOLDING     = 'Holding Company';
    const LEGAL_FREE_ZONE   = 'Free Zone Company';

    // ── Relationships ─────────────────────────────────────────────

    public function businessSector(): BelongsTo
    {
        return $this->belongsTo(BusinessSector::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'parent_company_id');
    }

    public function subsidiaries(): HasMany
    {
        return $this->hasMany(Company::class, 'parent_company_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function currencies(): HasMany
    {
        return $this->hasMany(Currency::class);
    }

    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class)->orderByDesc('start_date');
    }

    public function accountingPeriods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class);
    }

    public function chartOfAccounts(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class);
    }

    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class);
    }

    // ── Computed ──────────────────────────────────────────────────

    public function maxUsers(): int
    {
        $tierLimits = [
            'Starter'    => 3,
            'Growth'     => 5,
            'Pro'        => 10,
            'Enterprise' => 20,
        ];
        return ($tierLimits[$this->subscription_tier] ?? 3) + $this->additional_users;
    }

    // ── Subscription expiry (Audit C5, Sept 2026) ────────────────
    // True from the day AFTER subscription_expires_at. No expiry date
    // set = never expires. Used by ActiveCompany (read-only mode) and
    // HandleInertiaRequests (the red banner).
    public function isSubscriptionExpired(): bool
    {
        return $this->subscription_expires_at !== null
            && $this->subscription_expires_at->copy()->endOfDay()->isPast();
    }

    public function hasInventory(): bool
    {
        $types = $this->company_type ?? [];
        return (bool) array_intersect(
            [self::TYPE_TRADING, self::TYPE_MANUFACTURING, self::TYPE_SERVICE_WITH_INV],
            $types
        );
    }

    public function isManufacturing(): bool
    {
        return in_array(self::TYPE_MANUFACTURING, $this->company_type ?? []);
    }

    public function hasMixedInvoices(): bool
    {
        return in_array(self::TYPE_SERVICE_WITH_INV, $this->company_type ?? []);
    }

    public function companyTypeLabel(): string
    {
        return implode(', ', $this->company_type ?? []);
    }

    /**
     * Whether this company allows a Goods Receipt Note to be
     * created with no parent Purchase Order (confirmed by Mahmoud:
     * a per-company preference set by Super Admin at creation, not
     * a global platform rule).
     */
    public function allowsAdHocGrn(): bool
    {
        return $this->grn_policy === self::GRN_POLICY_ALLOW_AD_HOC;
    }

    /**
     * Returns the current open fiscal year, or null.
     * Used by the posting engine to determine the active period.
     */
    public function currentFiscalYear(): ?FiscalYear
    {
        return $this->fiscalYears()
            ->where('status', FiscalYear::STATUS_OPEN)
            ->orderByDesc('start_date')
            ->first();
    }
}
