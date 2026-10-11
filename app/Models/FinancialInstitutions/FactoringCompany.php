<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FactoringCompany
//
//  Factoring company master. Rates live on each FactoringAgreement.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FactoringCompany extends Model
{
    use BelongsToCompany;

    protected $table = 'factoring_companies';

    protected $fillable = [
        'company_id', 'name_en', 'name_ar', 'contact_person', 'phone', 'email', 'is_active', 'created_by',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function agreements(): HasMany
    {
        return $this->hasMany(FactoringAgreement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
