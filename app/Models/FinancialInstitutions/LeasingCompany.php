<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LeasingCompany
//
//  Leasing company master — FRA-regulated non-bank financial institutions.
//  Interest rates live on each LeasingContract, not here.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeasingCompany extends Model
{
    use BelongsToCompany;

    protected $table = 'leasing_companies';

    protected $fillable = [
        'company_id', 'name_en', 'name_ar', 'contact_person', 'phone', 'email', 'is_active', 'created_by',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function contracts(): HasMany
    {
        return $this->hasMany(LeasingContract::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
