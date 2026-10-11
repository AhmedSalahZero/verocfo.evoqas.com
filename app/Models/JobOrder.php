<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  JobOrder
//
//  A Job Order Production job — kept structurally SEPARATE from
//  ProductionOrder (Mass Production) and from Project, per Mahmoud's
//  explicit instruction, for clarity in fixing and debugging. The
//  one deliberate point of reuse with Mass Production is overhead:
//  JobOrderCostEntry.overhead_pool_id points at the same
//  OverheadPool table, and its rate resolves through the same
//  StandardRate mechanism.
//
//  Material movement lives entirely in StockMovement (job_
//  consumption/job_receipt, via the reference_type/reference_id
//  polymorphic link — stockMovements() below is the inverse side of
//  StockMovement::reference(), same pattern as ProductionOrder).
//
//  Estimated cost (per unit, captured at creation) is compared
//  against actual cost at completion — see migration 000094 header
//  for why material is auto-calculated but labor/overhead are
//  user-quoted, unlike ProductionOrder's fully system-derived
//  standard cost.
//
//  Job Order Routing (Section 6, Sept 2026): a job can OPTIONALLY be
//  broken into Pieces (pieces() below) — see JobOrderPiece's own
//  header. When it has any, complete() requires every piece be
//  Completed first, but still receives Finished Goods as ONE product
//  (this job's own product_id) — pieces never become separate
//  stockable items.
//
//  @layer    Model
//  @depends  BelongsToCompany, Product, JobOrderTemplate, SalesOrder,
//            InventoryLocation, JobOrderLine, JobOrderCostEntry,
//            JobOrderPiece, StockMovement, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Traits\SerializesDateOnly;

class JobOrder extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT       = 'draft';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED   = 'completed';
    const STATUS_CLOSED      = 'closed';
    const STATUS_CANCELLED   = 'cancelled';

    protected $fillable = [
        'company_id', 'job_code', 'job_order_template_id', 'sales_order_id',
        'product_id', 'quantity_to_produce', 'raw_material_location_id', 'output_location_id',
        'status', 'planned_start_date', 'actual_start_date', 'actual_completion_date',
        'estimated_material_cost_per_unit', 'estimated_material_cost_total', 'estimated_labor_cost_per_unit', 'estimated_overhead_cost_per_unit',
        'notes', 'created_by',
    ];

    protected $casts = [
        'quantity_to_produce'                => 'decimal:4',
        'estimated_material_cost_per_unit'   => 'decimal:4',
        'estimated_material_cost_total'      => 'decimal:2',
        'estimated_labor_cost_per_unit'      => 'decimal:4',
        'estimated_overhead_cost_per_unit'   => 'decimal:4',
        'planned_start_date'                 => 'date',
        'actual_start_date'                  => 'date',
        'actual_completion_date'             => 'date',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_IN_PROGRESS]);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(JobOrderTemplate::class, 'job_order_template_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function rawMaterialLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'raw_material_location_id');
    }

    public function outputLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'output_location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JobOrderLine::class)->orderBy('sort_order');
    }

    public function costEntries(): HasMany
    {
        return $this->hasMany(JobOrderCostEntry::class);
    }

    /** Optional Piece breakdown (Section 6) — empty for a simple, flat job. */
    public function pieces(): HasMany
    {
        return $this->hasMany(JobOrderPiece::class)->orderBy('sort_order');
    }

    // This job's own valuation segment (Manufacturing Variance Closing
    // Design v1.0, Section 6) — never blended into ProductStockBalance's
    // company-wide pooled WAC. One row, since one Job Order produces
    // one Product.
    public function stockBalance(): HasOne
    {
        return $this->hasOne(JobOrderStockBalance::class);
    }

    // Inverse of StockMovement::reference() — every material
    // consumption/receipt row posted against this job.
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
