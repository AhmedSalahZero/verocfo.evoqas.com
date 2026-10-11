<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  Project
//
//  Project / Contract master. Optional top-level entity above Sales Orders.
//  All company types can use Projects — it is always available but optional.
//
//  On creation: a project_site Inventory Location is auto-created by ProjectService.
//  On close: the project_site location is auto-deactivated by ProjectService.
//
//  Contract code is auto-generated and immutable: CC/[CU][PR]/YYYY/MM/0001
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope, Partner, Currency, InventoryLocation
// ══════════════════════════════════════════════════════════════════

use App\Models\Scopes\CompanyScope;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\SerializesDateOnly;

class Project extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $fillable = [
        'company_id', 'contract_code', 'name_en', 'name_ar', 'description',
        'partner_id', 'contract_value', 'currency_id', 'exchange_rate',
        'start_date', 'end_date', 'status', 'project_site_location_id',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'contract_value' => 'decimal:2',
        'exchange_rate'  => 'decimal:6',
        'start_date'     => 'date',
        'end_date'       => 'date',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function projectSiteLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'project_site_location_id');
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Eligible as collateral for OD Against Contract/SO — active + end_date in future */
    public function scopeEligibleCollateral(Builder $query, string $afterDate): Builder
    {
        return $query->where('status', 'active')
                     ->where('end_date', '>', $afterDate);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCloseable(): bool
    {
        return $this->salesOrders()
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->doesntExist();
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->name_en . ' (' . $this->contract_code . ')';
    }
}
