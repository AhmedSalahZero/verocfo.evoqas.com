<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  BusinessSector Model
//
//  Platform-level reference model — no company_id, no CompanyScope.
//  Shared across all companies on the platform.
//
//  Used by:
//    - Company → belongsTo BusinessSector
//    - Partner → belongsTo BusinessSector (customers & suppliers)
//
//  Managed by Super Admin only.
//  Seeded by BusinessSectorSeeder.
//
//  @layer    Model
//  @table    business_sectors
//  @depends  nothing — platform level, no tenant scope
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessSector extends Model
{
    protected $fillable = [
        'name_en',
        'name_ar',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
