<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  LaborCategory
//
//  A company-defined labor category (e.g. "Skilled Operator,"
//  "Unskilled Packer," "Machine Technician") — replaces the single
//  company-wide blended labor rate (VeroCFO_Manufacturing_Costing_
//  Redesign_v1_0.md, Section 3). Confirmed with Mahmoud: any number
//  of categories. This table is just identity — the rate lives in
//  StandardRate (see StandardRateService), same split as OverheadPool.
//
//  Will be genuinely shared between BOM Routing and Job Order Routing
//  once Section 5/6 are built, the same deliberate point of reuse
//  OverheadPool already is between Mass Production and Job Order.
//
//  @layer    Model
//  @depends  BelongsToCompany, StandardRate
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaborCategory extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'name', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function standardRates(): HasMany
    {
        return $this->hasMany(StandardRate::class);
    }
}
