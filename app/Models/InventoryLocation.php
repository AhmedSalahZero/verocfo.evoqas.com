<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  InventoryLocation
//
//  Two-level hierarchy for inventory location management.
//  Level 1 = Main Location, Level 2 = Sub-Location.
//
//  Types:
//    master       → one per company, auto-created at setup,
//                   cannot be deleted or deactivated
//    warehouse    → user-defined physical locations
//    project_site → auto-created when Project is created,
//                   deactivated when project closes
//
//  Auto-code:
//    Main: LOC-001 / Sub: LOC-001-01
//
//  Posting rule:
//    Sub-locations are always postable.
//    Main locations are postable only when they have no children.
//    Enforced in isPostable() and InventoryLocationService.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLocation extends Model
{
    use BelongsToCompany;

    // ── Type Constants ────────────────────────────────────────────

    const TYPE_MASTER       = 'master';
    const TYPE_WAREHOUSE    = 'warehouse';
    const TYPE_PROJECT_SITE = 'project_site';

    const LEVEL_MAIN = 1;
    const LEVEL_SUB  = 2;

    // ── Fillable ──────────────────────────────────────────────────

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name_en',
        'name_ar',
        'type',
        'level',
        'project_id',
        'is_active',
    ];

    // ── Casts ─────────────────────────────────────────────────────

    protected $casts = [
        'is_active' => 'boolean',
        'level'     => 'integer',
    ];

    // ── Auto-code ─────────────────────────────────────────────────

    /**
     * Next code for a Main Location: LOC-001, LOC-002 …
     */
    public static function nextMainCode(int $companyId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('level', self::LEVEL_MAIN)
            ->where('code', 'like', 'LOC-%')
            ->orderByRaw('CAST(SUBSTRING(code, 5) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->value('code');

        if (! $last) return 'LOC-001';
        $number = (int) substr($last, 4);
        return 'LOC-' . str_pad($number + 1, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Next code for a Sub-Location: LOC-001-01, LOC-001-02 …
     */
    public static function nextSubCode(int $companyId, int $parentId): string
    {
        $parent = static::withoutGlobalScopes()->findOrFail($parentId);

        $last = static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('parent_id', $parentId)
            ->orderByRaw('CAST(SUBSTRING_INDEX(code, \'-\', -1) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->value('code');

        if (! $last) return $parent->code . '-01';
        $suffix = (int) substr($last, strrpos($last, '-') + 1);
        return $parent->code . '-' . str_pad($suffix + 1, 2, '0', STR_PAD_LEFT);
    }

    /**
     * Next code for a Project Site location: PRJ-0001, PRJ-0002 …
     * Project sites are auto-created (one per project) and never
     * manually coded by the user, so this is separate from the
     * Main/Sub warehouse hierarchy codes above.
     */
    public static function nextProjectSiteCode(int $companyId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('type', self::TYPE_PROJECT_SITE)
            ->where('code', 'like', 'PRJ-%')
            ->orderByRaw('CAST(SUBSTRING(code, 5) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->value('code');

        if (! $last) return 'PRJ-0001';
        $number = (int) substr($last, 4);
        return 'PRJ-' . str_pad($number + 1, 4, '0', STR_PAD_LEFT);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeMain(Builder $query): Builder
    {
        return $query->where('level', self::LEVEL_MAIN);
    }

    public function scopePostable(Builder $query): Builder
    {
        // Sub-locations are always postable.
        // Main locations are postable only with no children.
        // This scope returns all sub-locations + childless mains.
        return $query->where(function ($q) {
            $q->where('level', self::LEVEL_SUB)
              ->orWhere(function ($inner) {
                  $inner->where('level', self::LEVEL_MAIN)
                        ->whereNotIn('id', function ($sub) {
                            $sub->select('parent_id')
                                ->from('inventory_locations')
                                ->whereNotNull('parent_id');
                        });
              });
        });
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isMain(): bool { return $this->level === self::LEVEL_MAIN; }
    public function isSub(): bool  { return $this->level === self::LEVEL_SUB; }
    public function isMaster(): bool { return $this->type === self::TYPE_MASTER; }
    public function isProjectSite(): bool { return $this->type === self::TYPE_PROJECT_SITE; }

    /**
     * A location is postable if it is a sub-location OR
     * a main location with no children.
     */
    public function isPostable(): bool
    {
        if ($this->isSub()) return true;
        return $this->children()->count() === 0;
    }

    /**
     * Returns display name with type indicator.
     */
    public function getFullNameAttribute(): string
    {
        $prefix = match($this->type) {
            self::TYPE_MASTER       => '★ ',
            self::TYPE_PROJECT_SITE => '⬡ ',
            default                 => '',
        };
        return $prefix . $this->name_en;
    }

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(InventoryLocation::class, 'parent_id')
                    ->orderBy('code');
    }
}