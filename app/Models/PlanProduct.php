<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PlanProduct
//
//  One product line inside a Plan — either a real Product (product_id
//  set) or a plan-only product typed in fresh (product_id null,
//  never written back to the real Products table — Decision, Sept
//  2026). See migration 000240 header.
//
//  @layer    Model
//  @depends  BelongsToCompany, Plan, Product, PlanSalesLine,
//            PlanCollectionTranche, PlanCogsAssumption
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PlanProduct extends Model
{
    use BelongsToCompany;

    public const NATURE_MANUFACTURING = 'manufacturing';
    public const NATURE_TRADING       = 'trading';
    public const NATURE_SERVICE       = 'service';

    protected $fillable = [
        'company_id',
        'plan_id',
        'product_id',
        'name',
        'nature',
        'uom_code',
        'vat_rate',
        'wht_rate',
        'sort_order',
    ];

    protected $casts = [
        'vat_rate' => 'decimal:4',
        'wht_rate' => 'decimal:4',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function salesLines(): HasMany
    {
        return $this->hasMany(PlanSalesLine::class)->orderBy('period_month');
    }

    public function collectionTranches(): HasMany
    {
        return $this->hasMany(PlanCollectionTranche::class);
    }

    public function cogsAssumption(): HasOne
    {
        return $this->hasOne(PlanCogsAssumption::class);
    }

    /** True if this line only exists inside the plan — no real Product record. */
    public function isPlanOnly(): bool
    {
        return $this->product_id === null;
    }
}
