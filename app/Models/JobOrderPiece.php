<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  JobOrderPiece
//
//  A named component/sub-assembly within one Job Order (Section 6.2)
//  — e.g. "Uprights," "Base Plates," "Cross Braces" for a racking
//  system. Entirely optional per Job Order (confirmed with Mahmoud) —
//  a simple job never creates one and keeps working exactly as it
//  always has (JobOrderCostEntry with no job_order_operation_id).
//
//  Pieces are NOT sequential — two can genuinely be in_progress at
//  once, no dependency between them (confirmed with Mahmoud). status
//  moves not_started → in_progress automatically the first time cost
//  is logged against one of this piece's operations (see
//  JobOrderService::logPieceOperationCost()), and → completed only
//  via an explicit user action (completePiece()) — never inferred.
//
//  @layer    Model
//  @depends  BelongsToCompany, JobOrder, JobOrderOperation
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobOrderPiece extends Model
{
    use BelongsToCompany;

    const STATUS_NOT_STARTED = 'not_started';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED   = 'completed';

    protected $fillable = [
        'company_id', 'job_order_id', 'name', 'quantity', 'sort_order', 'status',
    ];

    protected $casts = [
        'quantity'   => 'decimal:4',
        'sort_order' => 'integer',
    ];

    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(JobOrderOperation::class)->orderBy('sequence');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
