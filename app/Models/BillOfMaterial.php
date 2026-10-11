<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  BillOfMaterial
//
//  Versioned BOM header for a finished-good product. Draft/Active/
//  Superseded lifecycle (VeroCFO_Manufacturing_Flexibility_Design_
//  v1_0.md, Section 1 — replaces the old plain is_active boolean,
//  see migration 000254). Only one version per product may be
//  status = 'active' at a time — enforced in BillOfMaterialService,
//  never at the DB level, same pattern as every other "one active X"
//  rule in this app.
//
//  standard_labor_hours_per_batch / standard_machine_hours_per_batch
//  are GONE (dropped in migration 000264, Sept 2026 — Manufacturing
//  Costing Redesign, Section 5). Standard labor/overhead cost per
//  batch is now computed entirely from this BOM's Operations
//  (operations() below, BomOperation / migration 000265) — see
//  ProductionOrderService::standardLaborCostPerUnit()/
//  standardOverheadCostPerUnit(). No flat-field fallback was kept:
//  confirmed with Mahmoud that VeroCFO is still being built, not live
//  in production, so this is a clean one-way replacement.
//
//  @layer    Model
//  @depends  BelongsToCompany, Product, BomLine, BomOperation, ProductionOrder
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\SerializesDateOnly;

class BillOfMaterial extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT      = 'draft';
    const STATUS_ACTIVE     = 'active';
    const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'company_id', 'product_id', 'version', 'status',
        'output_quantity',
        'effective_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'output_quantity'                  => 'decimal:4',
        'effective_date'                   => 'date',
    ];

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    // Active or Superseded — the two statuses that ever actually
    // governed real production, and are therefore eligible for
    // BillOfMaterialService::resolveEffective()'s historical lookup.
    // Draft is deliberately excluded — it has never taken effect.
    public function scopeEverEffective(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_SUPERSEDED]);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BomLine::class)->orderBy('sort_order');
    }

    /** The Routing — ordered Operations (Section 5), see BomOperation. */
    public function operations(): HasMany
    {
        return $this->hasMany(BomOperation::class)->orderBy('sequence');
    }

    public function productionOrders(): HasMany
    {
        return $this->hasMany(ProductionOrder::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
