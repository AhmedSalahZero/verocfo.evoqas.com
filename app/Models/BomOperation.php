<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  BomOperation
//
//  One step in a BOM's Routing (VeroCFO_Manufacturing_Costing_
//  Redesign_v1_0.md, Section 5) — Process + a default Machine (for
//  standard costing) + Labor Category + how many labor/machine hours
//  that step needs per batch. Ordered within its BOM by `sequence`.
//
//  No overhead_pool_id here by design — an operation's overhead cost
//  is derived at calculation time from its Machine's own linked pools
//  (Machine::machineOverheadPools, Section 4.4, Option A — a machine
//  can feed several pools at once). See
//  ProductionOrderService::standardOverheadCostPerUnit(). Storing a
//  separate pool link here would let it silently drift out of sync
//  with the machine's actual pools.
//
//  @layer    Model
//  @depends  BelongsToCompany, BillOfMaterial, Process, Machine, LaborCategory
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomOperation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'bill_of_material_id', 'sequence',
        'process_id', 'default_machine_id', 'labor_category_id',
        'standard_labor_hours', 'standard_machine_hours',
    ];

    protected $casts = [
        'sequence'                => 'integer',
        'standard_labor_hours'    => 'decimal:4',
        'standard_machine_hours'  => 'decimal:4',
    ];

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function defaultMachine(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'default_machine_id');
    }

    public function laborCategory(): BelongsTo
    {
        return $this->belongsTo(LaborCategory::class);
    }
}
