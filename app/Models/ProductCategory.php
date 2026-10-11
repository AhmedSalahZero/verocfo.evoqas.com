<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ProductCategory
//
//  Two-level hierarchy: Main Category → Sub-Category.
//  company-specific — no system defaults.
//
//  expense_account_id:
//    Required for Consumable categories.
//    Determines posting for consumable items in this category.
//    overhead      → debit Manufacturing Overhead Pool
//    period_expense → debit P&L expense account directly
//
//  @layer    Model
//  @depends  BelongsToCompany, ChartOfAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    use BelongsToCompany;

    const LEVEL_MAIN = 1;
    const LEVEL_SUB  = 2;

    const TREATMENT_OVERHEAD       = 'overhead';
    const TREATMENT_PERIOD_EXPENSE = 'period_expense';

    protected $fillable = [
        'company_id', 'parent_id', 'name_en', 'name_ar',
        'level', 'cost_treatment', 'expense_account_id', 'is_active',
        'raw_material_account_id', 'finished_goods_account_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'level'     => 'integer',
    ];

    // ── Auto-code ─────────────────────────────────────────────────

    public static function nextMainCode(int $companyId): string
    {
        $count = static::where('company_id', $companyId)
            ->where('level', self::LEVEL_MAIN)
            ->count();
        return 'CAT-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
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

    // ── Relationships ─────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ProductCategory::class, 'parent_id')->orderBy('name_en');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    // Auto-created the first time a Raw Material / Consumable product is
    // assigned to this category — see ProductService::resolveCategoryInventoryAccount().
    public function rawMaterialAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'raw_material_account_id');
    }

    // Auto-created the first time a Finished Goods / Tradable product is
    // assigned to this category — see ProductService::resolveCategoryInventoryAccount().
    public function finishedGoodsAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'finished_goods_account_id');
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isConsumable(): bool
    {
        return ! empty($this->cost_treatment);
    }

    public function isMain(): bool { return $this->level === self::LEVEL_MAIN; }
    public function isSub(): bool  { return $this->level === self::LEVEL_SUB; }
}
