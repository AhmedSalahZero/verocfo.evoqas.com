<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  Process
//
//  A company-defined category of manufacturing work — Welding,
//  Cutting, Assembly, Painting, Packing (VeroCFO_Manufacturing_
//  Costing_Redesign_v1_0.md, Section 4.1). Simple identity only, same
//  shape as LaborCategory. NOT the same thing as Machine — a Machine
//  is tagged to exactly one Process, but several Machines can share
//  the same Process (e.g. two welding machines).
//
//  Will be consumed directly by BOM Routing and Job Order Routing
//  once Sections 5/6 are built — an Operation specifies a Process
//  (with a default Machine for standard costing).
//
//  @layer    Model
//  @depends  BelongsToCompany, Machine
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Process extends Model
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

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }
}
