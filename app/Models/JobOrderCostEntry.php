<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  JobOrderCostEntry
//
//  A progressive labor or overhead cost posting against an open
//  JobOrder — same reasoning as ProductionOrderCostEntry for why
//  this is separate from StockMovement. A DELIBERATE, SEPARATE
//  table from ProductionOrderCostEntry (not shared/polymorphic) —
//  per Mahmoud's instruction to keep Job Order structurally apart
//  from Mass Production.
//
//  overhead_pool_id points at the SAME OverheadPool table Mass
//  Production uses, and rate_applied resolves through the SAME
//  StandardRate mechanism — the one deliberate point of reuse.
//
//  No standard_amount column (unlike ProductionOrderCostEntry) —
//  Job Order has no per-entry standard to compute against. The
//  single comparison point is JobOrder's own estimated_labor_cost_
//  per_unit / estimated_overhead_cost_per_unit, captured once at
//  job opening.
//
//  job_order_operation_id (Section 6, Sept 2026) is OPTIONAL —
//  NULL means the original flat path (a manually-typed entry against
//  the whole job, unchanged); SET means this entry was generated
//  automatically by logPieceOperationCost() against a specific
//  Piece's Operation — see that method and migration 000268's header
//  for the full reasoning.
//
//  @layer    Model
//  @depends  BelongsToCompany, JobOrder, JobOrderOperation, OverheadPool, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class JobOrderCostEntry extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const TYPE_LABOR    = 'labor';
    const TYPE_OVERHEAD = 'overhead';

    protected $fillable = [
        'company_id', 'job_order_id', 'job_order_operation_id', 'entry_type', 'overhead_pool_id',
        'base_quantity', 'rate_applied', 'actual_amount', 'journal_entry_id', 'cost_center_id',
        'entry_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'base_quantity' => 'decimal:4',
        'rate_applied'  => 'decimal:4',
        'actual_amount' => 'decimal:2',
        'entry_date'    => 'date',
    ];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    /** Section 6 — the Piece Operation this entry was logged against, if any (null = the original flat path). */
    public function jobOrderOperation(): BelongsTo
    {
        return $this->belongsTo(JobOrderOperation::class);
    }

    public function overheadPool(): BelongsTo
    {
        return $this->belongsTo(OverheadPool::class);
    }

    // Bug fix (found while building Section 6): both were already
    // in the migration (000224) and already written to by
    // recordLaborCost()/recordOverheadCost(), but neither was in
    // $fillable (so every create()/update() call was silently
    // dropping them) nor had a relation method — JobOrderController::
    // show() already eager-loads 'costEntries.costCenter', which was
    // throwing on every single Job Order detail page view before
    // this fix.
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
