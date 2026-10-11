<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  SalesChannel
//
//  Master list of sales channels per company.
//  System channels (company_id = NULL) are available to all.
//  Company channels are visible only to that company.
//
//  Used by: Partner (Customer), Sales Orders (override per tx).
//  Critical for Business Analysis — channel must be a controlled
//  value, never free text, to ensure grouping accuracy.
//
//  scopeForCompany() is the correct scope for all dropdowns —
//  returns system channels + this company's custom channels.
//
//  AUDIT M1 FIX (Sept 2026): now uses BelongsToCompany, so one
//  company's custom channels are never visible to another company.
//  System channels (company_id = NULL) stay visible to all.
//
//  @layer    Model
//  @depends  BelongsToCompany
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class SalesChannel extends Model
{
    use BelongsToCompany;

    // Shared system rows (company_id = NULL) are visible to every
    // company — see CompanyScope (Audit M3). Do not add this constant
    // to ordinary tenant models.
    public const SHARES_SYSTEM_ROWS = true;

    protected $fillable = [
        'company_id',
        'name_en',
        'name_ar',
        'is_system',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_system'  => 'boolean',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    // ── Scopes ────────────────────────────────────────────────────

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
}
