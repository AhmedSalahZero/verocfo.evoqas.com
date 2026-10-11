<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  Bank
//
//  Bank master data. Multiple banks per company.
//  Multiple accounts per bank (BankAccount).
//  Multiple credit facilities per bank (CreditFacility).
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    use BelongsToCompany;

    protected $table = 'banks';

    protected $fillable = [
        'company_id', 'name_en', 'name_ar', 'branch_name', 'branch_address',
        'contact_person', 'phone', 'email', 'swift_code', 'is_active', 'created_by',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function accounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    public function creditFacilities(): HasMany
    {
        return $this->hasMany(CreditFacility::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name_en . ($this->branch_name ? ' — ' . $this->branch_name : '');
    }
}
