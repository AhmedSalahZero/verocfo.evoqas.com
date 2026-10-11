<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ProductionOrder
//
//  A Mass Production job — produce a quantity of a finished good
//  against a specific BOM version. Material movement lives entirely
//  in StockMovement (via the reference_type/reference_id polymorphic
//  link — stockMovements() below is the inverse side of
//  StockMovement::reference()). Labor/overhead cost lives in
//  ProductionOrderCostEntry. See migration 000090 header for the
//  full reasoning, including why standard cost is snapshotted at
//  creation rather than recalculated live.
//
//  Order code format: PROD/YYYY/MM/00001 — immutable after creation,
//  same pattern as PO/SO codes.
//
//  @layer    Model
//  @depends  BelongsToCompany, Product, BillOfMaterial, InventoryLocation,
//            StockMovement, ProductionOrderCostEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Traits\SerializesDateOnly;

class ProductionOrder extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT       = 'draft';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED   = 'completed';
    const STATUS_CLOSED      = 'closed';
    const STATUS_CANCELLED   = 'cancelled';

    protected $fillable = [
        'company_id', 'order_code', 'product_id', 'bill_of_material_id',
        'quantity_to_produce', 'raw_material_location_id', 'output_location_id',
        'status', 'planned_start_date', 'actual_start_date', 'actual_completion_date',
        'standard_material_cost_per_unit', 'standard_material_cost_total',
        'standard_labor_cost_per_unit', 'standard_labor_cost_total',
        'standard_overhead_cost_per_unit', 'standard_overhead_cost_total',
        'notes', 'created_by',
    ];

    protected $casts = [
        'quantity_to_produce'              => 'decimal:4',
        'standard_material_cost_per_unit'  => 'decimal:4',
        'standard_material_cost_total'     => 'decimal:2',
        'standard_labor_cost_per_unit'     => 'decimal:4',
        'standard_labor_cost_total'        => 'decimal:2',
        'standard_overhead_cost_per_unit'  => 'decimal:4',
        'standard_overhead_cost_total'     => 'decimal:2',
        'planned_start_date'               => 'date',
        'actual_start_date'                => 'date',
        'actual_completion_date'           => 'date',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_IN_PROGRESS]);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class);
    }

    public function rawMaterialLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'raw_material_location_id');
    }

    public function outputLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'output_location_id');
    }

    public function costEntries(): HasMany
    {
        return $this->hasMany(ProductionOrderCostEntry::class);
    }

    // Inverse of StockMovement::reference() — every material
    // consumption/receipt row posted against this order.
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
