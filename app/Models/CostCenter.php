<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CostCenter
//
//  Two-level cost center hierarchy:
//    Level 1 — Main Cost Center  (مركز التكلفة الرئيسي)
//    Level 2 — Sub Cost Center   (مركز التكلفة الفرعي)
//
//  Posting rule:
//    - Sub Cost Centers are always postable.
//    - Main Cost Centers are postable ONLY when they have no
//      children. Once a sub cost center is created under a main,
//      posting moves to the sub level.
//    - This rule is enforced in CostCenterService and the
//      journal entry posting engine.
//
//  Balance rollup:
//    - Main Cost Center balance = sum of all its sub cost centers.
//    - Reporting always shows both levels.
//
//  Auto-code: generated per company as CC-001, CC-002 …
//  for Main Cost Centers. Sub cost centers use the parent code
//  as prefix: CC-001-01, CC-001-02 …
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostCenter extends Model
{
    use BelongsToCompany;

    // ── Level Constants ───────────────────────────────────────────

    const LEVEL_MAIN = 1;
    const LEVEL_SUB  = 2;

    // ── Fillable ──────────────────────────────────────────────────

    protected $fillable = [
        'company_id',
        'code',
        'name_en',
        'name_ar',
        'level',
        'parent_id',
        'is_active',
    ];

    // ── Casts ─────────────────────────────────────────────────────

    protected $casts = [
        'is_active' => 'boolean',
        'level'     => 'integer',
    ];

    // ── Auto-code generation ──────────────────────────────────────

    /**
     * Next code for a Main Cost Center.
     * Format: CC-001, CC-002 …
     */
    public static function nextMainCode(int $companyId): string
    {
        $last = static::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('level', self::LEVEL_MAIN)
            ->where('code', 'like', 'CC-%')
            ->orderByRaw('CAST(SUBSTRING(code, 4) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->value('code');

        if (! $last) return 'CC-001';

        $number = (int) substr($last, 3);
        return 'CC-' . str_pad($number + 1, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Next code for a Sub Cost Center under a given parent.
     * Format: CC-001-01, CC-001-02 …
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

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeMain(Builder $query): Builder
    {
        return $query->where('level', self::LEVEL_MAIN);
    }

    public function scopeSub(Builder $query): Builder
    {
        return $query->where('level', self::LEVEL_SUB);
    }

    // ── Relationships ─────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(CostCenter::class, 'parent_id')
                    ->orderBy('code');
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isMain(): bool
    {
        return $this->level === self::LEVEL_MAIN;
    }

    public function isSub(): bool
    {
        return $this->level === self::LEVEL_SUB;
    }

    /**
     * A Main Cost Center is postable only when it has no children.
     * Sub Cost Centers are always postable.
     * Evaluated in real-time to reflect current state.
     */
    public function isPostable(): bool
    {
        if ($this->isSub()) return true;
        return $this->children()->count() === 0;
    }

    public function getLevelLabelAttribute(): string
    {
        return $this->level === self::LEVEL_MAIN
            ? 'Main Cost Center'
            : 'Sub Cost Center';
    }
}
