<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  MachineOverheadPool
//
//  Machine ↔ Overhead Pool link (VeroCFO_Manufacturing_Costing_
//  Redesign_v1_0.md, Section 4.4 — Option A confirmed with Mahmoud,
//  Sept 2026): a genuine many-to-many, one row per linked pair. See
//  migration 000263's header for why this is modeled as a real
//  Eloquent model with BelongsToCompany rather than a bare pivot
//  table — same precedent as CompanyActiveCurrency (migration
//  000028), this codebase's existing pattern for a link table.
//
//  MachineService owns every write here — see
//  MachineService::syncOverheadPools() (delete-then-create-many on
//  every update, same "wipe and reapply" idempotent pattern already
//  used elsewhere in this app, e.g. Facility Settlement Engine
//  resettlement, Rule 34.4).
//
//  @layer    Model
//  @depends  BelongsToCompany, Machine, OverheadPool
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachineOverheadPool extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'machine_id', 'overhead_pool_id',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function overheadPool(): BelongsTo
    {
        return $this->belongsTo(OverheadPool::class);
    }
}
