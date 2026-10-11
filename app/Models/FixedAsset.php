<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  FixedAsset
//
//  One row per INDIVIDUAL physical asset — see migration 000168
//  header for the full lifecycle rationale (capitalizing → in_service
//  → disposed).
//
//  machine() (added Sept 2026 — Manufacturing Costing Redesign,
//  Section 4.2): the OPTIONAL reverse side of Machine::fixed_asset_id
//  — set only when this specific physical asset has also been
//  registered as a manufacturing Work Center (Process + Overhead
//  Pool link + routing status). Most Fixed Assets (laptops, office
//  furniture, vehicles) will never have one; that's expected, not a
//  gap.
//
//  Audit H17 (Sept 2026): accumulated_impairment, disposals(),
//  impairments(), transfers(); netBookValue() and depreciation now
//  take impairment into account.
//
//  @layer    Model
//  @depends  BelongsToCompany, Product, ChartOfAccount, Currency,
//            InventoryLocation, FixedAssetCostLine,
//            FixedAssetDepreciationEntry, User, Machine
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FixedAsset extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    public const STATUS_CAPITALIZING = 'capitalizing';
    public const STATUS_IN_SERVICE   = 'in_service';
    public const STATUS_DISPOSED     = 'disposed';

    public const METHOD_STRAIGHT_LINE = 'straight_line'; // only method Phase 1 supports

    protected $fillable = [
        'company_id',
        'asset_code',
        'product_id',
        'name',
        'serial_number',
        'fixed_asset_account_id',
        'accumulated_depreciation_account_id',
        'currency_id',
        'inventory_location_id',
        'cost_basis',
        'residual_value',
        'useful_life_months',
        'depreciation_method',
        'received_date',
        'depreciation_start_date',
        'accumulated_depreciation',
        'accumulated_impairment',
        'cost_revised_at',
        'status',
        'in_service_at',
        'source_type',
        'source_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'cost_basis'                => 'decimal:2',
        'residual_value'            => 'decimal:2',
        'useful_life_months'        => 'integer',
        'accumulated_depreciation'  => 'decimal:2',
        'accumulated_impairment'    => 'decimal:2',
        'received_date'             => 'date',
        'depreciation_start_date'   => 'date',
        'in_service_at'             => 'date',
        'cost_revised_at'           => 'datetime',
    ];

    // ── Auto-code ─────────────────────────────────────────────────

    public static function nextCode(int $companyId): string
    {
        $last = static::where('company_id', $companyId)
            ->where('asset_code', 'like', 'FA-%')
            ->orderByRaw('CAST(SUBSTRING(asset_code, 4) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->value('asset_code');

        if (! $last) return 'FA-00001';
        $number = (int) substr($last, 3);
        return 'FA-' . str_pad($number + 1, 5, '0', STR_PAD_LEFT);
    }

    // ── Relationships ─────────────────────────────────────────────

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fixedAssetAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'fixed_asset_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'accumulated_depreciation_account_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function costLines(): HasMany
    {
        return $this->hasMany(FixedAssetCostLine::class)->orderBy('id');
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(FixedAssetDepreciationEntry::class)->orderBy('period_date');
    }

    /** Audit H17 — sale / scrapping, impairment write-downs and location moves. */
    public function disposals(): HasMany
    {
        return $this->hasMany(FixedAssetDisposal::class)->orderByDesc('id');
    }

    public function impairments(): HasMany
    {
        return $this->hasMany(FixedAssetImpairment::class)->orderByDesc('id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(FixedAssetTransfer::class)->orderByDesc('id');
    }

    /** The Machine (Work Center) this asset is also registered as, if any — see class header. */
    public function machine(): HasOne
    {
        return $this->hasOne(Machine::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeCapitalizing(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CAPITALIZING);
    }

    public function scopeInService(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_SERVICE);
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Whether more cost can still be capitalized onto this asset —
     * true only while 'capitalizing'. Once 'in_service' (or
     * 'disposed'), any further cost must be expensed instead
     * (enforced in FixedAssetService::addCostLine(), not here).
     */
    public function canStillCapitalize(): bool
    {
        return $this->status === self::STATUS_CAPITALIZING;
    }

    /** Cost − accumulated depreciation − accumulated impairment (Audit H17). */
    public function netBookValue(): string
    {
        return bcsub(bcsub((string) $this->cost_basis, (string) $this->accumulated_depreciation, 2), (string) ($this->accumulated_impairment ?? '0'), 2);
    }

    /**
     * What depreciation must still spread over the asset's whole life:
     * (Cost − Residual) − accumulated impairment (Audit H17). With no
     * impairment this is the familiar (Cost − Residual).
     */
    public function depreciableBase(): string
    {
        return bcsub(bcsub((string) $this->cost_basis, (string) $this->residual_value, 2), (string) ($this->accumulated_impairment ?? '0'), 2);
    }

    /**
     * Straight-line monthly depreciation: (Cost − Residual) ÷ Useful
     * Life (months). Recomputed fresh every time it's needed (not
     * stored) so a cost-basis correction automatically changes the
     * figure the next period posts at — no separate "recalculate"
     * step required.
     */
    public function monthlyDepreciationAmount(): string
    {
        // Audit H17 (IAS 36.63): after an impairment, what is left of the
        // (reduced) depreciable amount is spread over the months left.
        // Audit M-20 (IAS 8.36): a cost confirmed AFTER depreciation was
        // posted is a change in estimate — the remaining depreciable
        // amount is spread over the remaining months, never booked back
        // into periods already posted (and possibly closed).
        if (bccomp((string) ($this->accumulated_impairment ?? '0'), '0', 2) > 0 || $this->cost_revised_at !== null) {
            $posted = $this->depreciationEntries()->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)->count();
            $remainingPeriods = (int) $this->useful_life_months - $posted;
            $remainingBase = bcsub($this->depreciableBase(), (string) $this->accumulated_depreciation, 2);

            if ($remainingPeriods <= 0 || bccomp($remainingBase, '0', 2) <= 0) {
                return '0.00';
            }

            return bcdiv($remainingBase, (string) $remainingPeriods, 2);
        }

        $depreciableBase = bcsub((string) $this->cost_basis, (string) $this->residual_value, 2);

        if ($this->useful_life_months <= 0 || bccomp($depreciableBase, '0', 2) <= 0) {
            return '0.00';
        }

        return bcdiv($depreciableBase, (string) $this->useful_life_months, 2);
    }

    /**
     * Total periods this asset should ever depreciate for — used to
     * cap posting once fully depreciated and to let the final period
     * absorb any rounding residue (same principle as Leasing's own
     * wear-down schedule).
     */
    public function totalDepreciationPeriods(): int
    {
        return (int) $this->useful_life_months;
    }
}
