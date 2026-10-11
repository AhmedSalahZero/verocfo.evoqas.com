<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CompanyActiveCurrency
//
//  Pivot between a company and the system currencies it has
//  activated for use. Each row means "this company uses this
//  currency in transactions."
//
//  is_functional = true marks the company's functional currency
//  (e.g. EGP for Egyptian companies). Only one per company.
//  The functional currency cannot be deactivated.
//
//  Exchange rates are entered per active currency (except the
//  functional currency — it always has a rate of 1.000000).
//
//  @layer    Model
//  @depends  BelongsToCompany, Currency, Company
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyActiveCurrency extends Model
{
    use BelongsToCompany;

    protected $table = 'company_active_currencies';

    protected $fillable = [
        'company_id',
        'currency_id',
        'is_functional',
        'is_active',
    ];

    protected $casts = [
        'is_functional' => 'boolean',
        'is_active'     => 'boolean',
    ];

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeFunctional(Builder $query): Builder
    {
        return $query->where('is_functional', true);
    }

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
