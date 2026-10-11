<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  Partner
//
//  Central master data model used by every transaction module.
//  A partner can hold multiple simultaneous types — stored as a
//  JSON array in the `types` column:
//    ['customer', 'supplier']
//    ['employee', 'shareholder']
//    ['customer', 'supplier', 'other']
//
//  Valid types: customer | supplier | employee | shareholder |
//               sister_company | other
//
//  Type-specific fields are nullable — only populated when the
//  partner has that type active:
//    Customer  → credit_limit, collection_term_id, sales_zone,
//                sales_channel, business_sector_id
//    Supplier  → payment_term_id, expense_category,
//                business_sector_id
//    Employee  → department, job_title, cost_center_id,
//                bank_name, bank_account_number, iban
//    Shareholder → equity_pct
//    Sister Company → sister_company_name
//    Other     → base fields only
//
//  Auto-code: generated as P-00001, P-00002 … per company.
//  nextCode(int $companyId) returns the next available code.
//
//  Employee history: whenever department, job_title, or
//  cost_center_id changes, PartnerService writes a row to
//  partner_employee_history before saving.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope
//            PaymentTerm, BusinessSector, CostCenter
//            PartnerContact, PartnerEmployeeHistory
//            ChartOfAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;

class Partner extends Model
{
    use BelongsToCompany;

    // ── Type Constants ────────────────────────────────────────────

    const TYPE_CUSTOMER       = 'customer';
    const TYPE_SUPPLIER       = 'supplier';
    const TYPE_EMPLOYEE       = 'employee';
    const TYPE_SHAREHOLDER    = 'shareholder';
    const TYPE_SISTER_COMPANY = 'sister_company';
    const TYPE_OTHER          = 'other';

    const ALL_TYPES = [
        self::TYPE_CUSTOMER,
        self::TYPE_SUPPLIER,
        self::TYPE_EMPLOYEE,
        self::TYPE_SHAREHOLDER,
        self::TYPE_SISTER_COMPANY,
        self::TYPE_OTHER,
    ];

    // ── Fillable ──────────────────────────────────────────────────

    protected $fillable = [
        'company_id',
        'code',
        'name_en',
        'name_ar',
        'types',                  // JSON array

        // Shared contact fields
        'email',
        'phone',
        'address',
        'country',
        'tax_number',
        'notes',

        // Customer fields
        'credit_limit',
        'collection_term_id',
        'sales_zone',
        'sales_channel_id',

        // Supplier fields
        'payment_term_id',
        'expense_category',

        // Customer + Supplier
        'business_sector_id',

        // Employee fields
        'department',
        'job_title',
        'cost_center_id',
        'bank_name',
        'bank_branch',
        'bank_account_number',
        'iban',
        'swift_code',

        // Shareholder fields
        'equity_pct',

        // Sister Company fields
        'sister_company_name',

        // AR/AP ledger accounts
        'ar_account_id',
        'ap_account_id',

        // Landing Cost Supplier fields — see migration 000202 /
        // VeroCFO_Landing_Cost_Design_v1_0.md. Additive: a partner can
        // be both a normal Supplier and a Landing Cost Supplier.
        'is_landing_cost_supplier',
        'landing_cost_account_id',

        'is_active',
    ];

    // ── Casts ─────────────────────────────────────────────────────

    protected $casts = [
        'types'        => 'array',
        'credit_limit' => 'decimal:2',
        'equity_pct'   => 'decimal:2',
        'is_active'    => 'boolean',
        'is_landing_cost_supplier' => 'boolean',
    ];

    // ── Type Helpers ──────────────────────────────────────────────

    public function hasType(string $type): bool
    {
        return in_array($type, $this->types ?? [], true);
    }

    public function isCustomer(): bool    { return $this->hasType(self::TYPE_CUSTOMER); }
    public function isSupplier(): bool    { return $this->hasType(self::TYPE_SUPPLIER); }
    public function isEmployee(): bool    { return $this->hasType(self::TYPE_EMPLOYEE); }
    public function isShareholder(): bool { return $this->hasType(self::TYPE_SHAREHOLDER); }
    public function isSisterCompany(): bool { return $this->hasType(self::TYPE_SISTER_COMPANY); }
    public function isOther(): bool       { return $this->hasType(self::TYPE_OTHER); }

    /**
     * Returns human-readable comma-separated type labels.
     * e.g. "Customer, Supplier"
     */
    public function getTypeLabelAttribute(): string
    {
        $labels = [
            self::TYPE_CUSTOMER       => 'Customer',
            self::TYPE_SUPPLIER       => 'Supplier',
            self::TYPE_EMPLOYEE       => 'Employee',
            self::TYPE_SHAREHOLDER    => 'Shareholder',
            self::TYPE_SISTER_COMPANY => 'Sister Company',
            self::TYPE_OTHER          => 'Other',
        ];

        return collect($this->types ?? [])
            ->map(fn ($t) => $labels[$t] ?? $t)
            ->implode(', ');
    }

    // ── Auto Code ─────────────────────────────────────────────────

    /**
     * Generates the next partner code for a company.
     * Format: P-00001, P-00002, …
     * Reads the highest existing numeric suffix and increments by 1.
     * Safe to call inside DB::transaction().
     */
    public static function nextCode(int $companyId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('code', 'like', 'P-%')
            ->orderByRaw('CAST(SUBSTRING(code, 3) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->value('code');

        if (! $last) {
            return 'P-00001';
        }

        $number = (int) substr($last, 2);
        return 'P-' . str_pad($number + 1, 5, '0', STR_PAD_LEFT);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->whereJsonContains('types', $type);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name_en', 'like', "%{$term}%")
              ->orWhere('name_ar', 'like', "%{$term}%")
              ->orWhere('code', 'like', "%{$term}%")
              ->orWhere('email', 'like', "%{$term}%")
              ->orWhere('phone', 'like', "%{$term}%");
        });
    }

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function collectionTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'collection_term_id');
    }

    public function salesChannel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'sales_channel_id');
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    public function businessSector(): BelongsTo
    {
        return $this->belongsTo(BusinessSector::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function arAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'ar_account_id');
    }

    public function apAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'ap_account_id');
    }

    /** Suggested default 146xxx sub-account — see migration 000202 header. */
    public function landingCostAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'landing_cost_account_id');
    }

    /** Every Landing Cost this partner is the Landing Cost Supplier on. */
    public function landingCosts(): HasMany
    {
        return $this->hasMany(LandingCost::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(PartnerContact::class)->orderByDesc('is_primary');
    }

    public function primaryContact(): HasMany
    {
        return $this->hasMany(PartnerContact::class)->where('is_primary', true);
    }

    public function employeeHistory(): HasMany
    {
        return $this->hasMany(PartnerEmployeeHistory::class)->orderByDesc('effective_date');
    }

    /** The one salary/HR setup record for this Employee-type partner, if created. */
    public function payrollProfile(): HasOne
    {
        return $this->hasOne(EmployeePayrollProfile::class);
    }
}
