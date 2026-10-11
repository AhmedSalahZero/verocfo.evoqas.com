<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  UnitOfMeasure
//
//  System UOMs have company_id = NULL — available to all companies.
//  Company custom UOMs have company_id = SET.
//
//  IMPORTANT: Does NOT use BelongsToCompany trait because that
//  trait's CompanyScope filters by company_id = X, which would
//  exclude system UOMs (company_id = NULL) when loaded through
//  Eloquent relationships (e.g. product->primaryUom).
//
//  Instead, scopeForCompany() returns both system + company UOMs.
//  The CompanyController and any direct queries must use this scope.
//
//  @layer    Model
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UnitOfMeasure extends Model
{
    // No BelongsToCompany — intentional. See header comment.

    protected $table = 'units_of_measure';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_ar',
        'type', 'is_system', 'is_active',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Returns system UOMs + company-specific UOMs.
     * Use this scope for all dropdown and list queries.
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where(function ($q) use ($companyId) {
            $q->whereNull('company_id')->orWhere('company_id', $companyId);
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}