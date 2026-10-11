<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  Machine
//
//  The real "Work Center" (VeroCFO_Manufacturing_Costing_Redesign_v1_
//  0.md, Section 4.1/4.2) — a specific, individually identified
//  physical machine the company owns. Auto-coded on registration
//  (M-00001, same pattern as Product's P-00001 — see nextCode()),
//  tagged to exactly one Process, linked to one or more Overhead
//  Pools (Section 4.4, Option A — many-to-many, see
//  MachineOverheadPool / migration 000263), and carries a status
//  (Active / Under Maintenance / Retired — Section 4.3).
//
//  fixed_asset_id is OPTIONAL — see migration 000262's header for the
//  full reasoning on why a Machine is not simply a Fixed Asset. When
//  set, it links this operational/costing identity to the real
//  accounting book record for the same physical equipment.
//
//  A Retired or Under-Maintenance machine's past production history
//  is never hidden (Section 4.3) — status changes never delete or
//  hide anything; they only affect whether the machine is offered as
//  the DEFAULT for a NEW Routing, once Routing (Section 5) exists.
//
//  @layer    Model
//  @depends  BelongsToCompany, Process, FixedAsset, MachineOverheadPool, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Machine extends Model
{
    use BelongsToCompany;

    const STATUS_ACTIVE            = 'active';
    const STATUS_UNDER_MAINTENANCE = 'under_maintenance';
    const STATUS_RETIRED           = 'retired';

    const ALL_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_UNDER_MAINTENANCE,
        self::STATUS_RETIRED,
    ];

    protected $fillable = [
        'company_id', 'code', 'name', 'process_id', 'fixed_asset_id',
        'status', 'notes', 'created_by', 'updated_by',
    ];

    // ── Auto-code ─────────────────────────────────────────────────
    // Mirrors Product::nextCode() exactly — same prefix-strip-and-
    // increment pattern, just a different prefix.

    public static function nextCode(int $companyId): string
    {
        $last = static::where('company_id', $companyId)
            ->where('code', 'like', 'M-%')
            ->orderByRaw('CAST(SUBSTRING(code, 3) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->value('code');

        if (! $last) return 'M-00001';
        $number = (int) substr($last, 2);
        return 'M-' . str_pad($number + 1, 5, '0', STR_PAD_LEFT);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Machines it's currently sensible to offer as the DEFAULT for a
     * NEW Routing operation (Section 5, not yet built) — Active only.
     * Under Maintenance / Retired machines are simply not offered as
     * a *new* default; they are never hidden from anywhere else (this
     * index, reporting, or an existing Routing that already points at
     * one — Section 4.3's "never hide history" rule).
     */
    public function scopeEligibleForNewRoutingDefault(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(Process::class);
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The link rows to this machine's Overhead Pool(s) — see
     * MachineOverheadPool / migration 000263. MachineService is the
     * only place that writes here (createMany on
     * create()/replaces-on-update via sync-style delete+create — see
     * MachineService::syncOverheadPools()). Controllers eager-load
     * 'machineOverheadPools.overheadPool' so a loaded Machine can
     * display its pools directly without a second query per row.
     */
    public function machineOverheadPools(): HasMany
    {
        return $this->hasMany(MachineOverheadPool::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
