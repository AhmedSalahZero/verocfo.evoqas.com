<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  Currency
//
//  System currencies have company_id = NULL and are available
//  to all companies on the platform.
//
//  Company-specific currencies (rare) have company_id set.
//
//  scopeForCompany() returns system currencies + company's own.
//  This is the correct scope for all currency dropdowns.
//
//  is_base: marks the functional currency of the company.
//  Only one currency per company should have is_base = true.
//
//  @layer    Model
//  @depends  BelongsToCompany, ExchangeRate
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    use BelongsToCompany;

    // Shared system rows (company_id = NULL) are visible to every
    // company — see CompanyScope (Audit M3). Do not add this constant
    // to ordinary tenant models.
    public const SHARES_SYSTEM_ROWS = true;

    protected $fillable = [
        'company_id',
        'code',
        'name_en',
        'name_ar',
        'symbol',
        'is_base',
        'is_active',
    ];

    protected $casts = [
        'is_base'   => 'boolean',
        'is_active' => 'boolean',
    ];

    // ── Scopes ────────────────────────────────────────────────────

    /**
     * Returns system currencies (company_id = NULL) AND this
     * company's own currencies. Use for all dropdowns.
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where(function ($q) use ($companyId) {
            $q->whereNull('company_id')
              ->orWhere('company_id', $companyId);
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function exchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }

    public function latestRate(): HasMany
    {
        return $this->hasMany(ExchangeRate::class)
                    ->orderByDesc('rate_date')
                    ->limit(1);
    }
}
