<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ProductionOrderCostEntry
//
//  A progressive labor or overhead cost posting against an open
//  ProductionOrder — the piece of WIP that isn't a physical stock
//  movement (no product/quantity changing hands, just cost accruing
//  against the job). See migration 000091 header for the full
//  reasoning on why this is separate from StockMovement.
//
//  standard_amount / actual_amount both live on every entry so
//  variance can be computed per entry, not just at the order level.
//
//  @layer    Model
//  @depends  BelongsToCompany, ProductionOrder, OverheadPool, CostCenter, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class ProductionOrderCostEntry extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const TYPE_LABOR    = 'labor';
    const TYPE_OVERHEAD = 'overhead';

    protected $fillable = [
        'company_id', 'production_order_id', 'entry_type', 'overhead_pool_id',
        'base_quantity', 'rate_applied', 'standard_amount', 'actual_amount',
        'journal_entry_id', 'cost_center_id', 'entry_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'base_quantity'   => 'decimal:4',
        'rate_applied'    => 'decimal:4',
        'standard_amount' => 'decimal:2',
        'actual_amount'   => 'decimal:2',
        'entry_date'      => 'date',
    ];

    public function productionOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function overheadPool(): BelongsTo
    {
        return $this->belongsTo(OverheadPool::class);
    }

    // Bug fix (found while building Job Order Routing, Section 6 —
    // the same gap existed on this sibling table): both columns were
    // already in migration 000224 and already written to by
    // ProductionOrderService::recordLaborCost()/recordOverheadCost(),
    // but neither was in $fillable (silently dropped on every
    // create()/update()) nor had a relation method —
    // ProductionOrderController::show() already eager-loads
    // 'costEntries.costCenter', which was throwing on every single
    // Production Order detail page view before this fix.
    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
