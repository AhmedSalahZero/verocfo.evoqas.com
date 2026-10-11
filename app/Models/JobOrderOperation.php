<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  JobOrderOperation
//
//  One step in a Job Order Piece's own routing (Section 6.2) — Process
//  + the Machine actually used + Labor Category. Same building blocks
//  as BomOperation, just assembled fresh per job/piece instead of
//  reused per product. No overhead_pool_id — overhead is derived at
//  logging time from this operation's Machine's own linked pools
//  (Machine::machineOverheadPools), same reasoning as BomOperation.
//
//  @layer    Model
//  @depends  BelongsToCompany, JobOrderPiece, Process, Machine,
//            LaborCategory, JobOrderCostEntry
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobOrderOperation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'job_order_piece_id', 'sequence',
        'process_id', 'machine_id', 'labor_category_id',
    ];

    protected $casts = [
        'sequence' => 'integer',
    ];

    public function piece(): BelongsTo
    {
        return $this->belongsTo(JobOrderPiece::class, 'job_order_piece_id');
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function laborCategory(): BelongsTo
    {
        return $this->belongsTo(LaborCategory::class);
    }

    public function costEntries(): HasMany
    {
        return $this->hasMany(JobOrderCostEntry::class);
    }
}
