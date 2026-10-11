<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  Product
//
//  Main product / service item master.
//
//  Account assignment is automatic — derived from usage_type
//  and nature. Never manually assigned per SKU.
//
//  Auto-code: P-00001 sequential per company.
//
//  has_stock: derived from usage_type + nature:
//    saleable + tradable/manufactured → true
//    saleable + service               → false
//    raw_material / consumable        → true
//
//  @layer    Model
//  @depends  BelongsToCompany, ProductCategory, UnitOfMeasure
//            ChartOfAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use BelongsToCompany;

    // ── Usage Type Constants ──────────────────────────────────────
    const TYPE_SALEABLE     = 'saleable';
    const TYPE_RAW_MATERIAL = 'raw_material';
    const TYPE_CONSUMABLE   = 'consumable';
    const TYPE_FIXED_ASSET  = 'fixed_asset';

    // ── Nature Constants ──────────────────────────────────────────
    const NATURE_TRADABLE      = 'tradable';
    const NATURE_MANUFACTURED  = 'manufactured';
    const NATURE_SERVICE       = 'service';
    const NATURE_FIXED_ASSET   = 'fixed_asset';

    protected $fillable = [
        'company_id', 'code', 'name_en', 'name_ar',
        'barcode', 'description', 'category_id',
        'usage_types', 'nature', 'has_stock',
        'primary_uom_id',
        'revenue_account_id', 'cogs_account_id', 'inventory_account_id',
        'fixed_asset_account_id', 'accumulated_depreciation_account_id',
        'default_useful_life_months', 'default_residual_value',
        'weight', 'weight_uom_id',
        'reorder_level', 'reorder_qty', 'is_active',
    ];

    protected $casts = [
        'usage_types' => 'array',
        'has_stock'   => 'boolean',
        'is_active'   => 'boolean',
        'default_useful_life_months' => 'integer',
        'default_residual_value'     => 'decimal:2',
    ];

    // ── Auto-code ─────────────────────────────────────────────────

    public static function nextCode(int $companyId): string
    {
        $last = static::where('company_id', $companyId)
            ->where('code', 'like', 'P-%')
            ->orderByRaw('CAST(SUBSTRING(code, 3) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->value('code');

        if (! $last) return 'P-00001';
        $number = (int) substr($last, 2);
        return 'P-' . str_pad($number + 1, 5, '0', STR_PAD_LEFT);
    }

    // ── has_stock derivation ──────────────────────────────────────

    public static function deriveHasStock(array $usageTypes, ?string $nature): bool
    {
        // Service items never have stock
        if ($nature === self::NATURE_SERVICE) return false;
        // Fixed Assets are tracked individually in the Asset Register,
        // never in the WAC-pooled stock ledger — see FixedAssetService.
        if ($nature === self::NATURE_FIXED_ASSET) return false;
        // Raw materials and consumables always have stock
        if (in_array(self::TYPE_RAW_MATERIAL, $usageTypes)) return true;
        if (in_array(self::TYPE_CONSUMABLE, $usageTypes)) return true;
        // Tradable and manufactured saleables have stock
        if (in_array($nature, [self::NATURE_TRADABLE, self::NATURE_MANUFACTURED])) return true;
        return false;
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeWithStock(Builder $query): Builder
    {
        return $query->where('has_stock', true);
    }

    public function scopeForDropdown(Builder $query): Builder
    {
        return $query->active()
            ->orderBy('name_en')
            ->select(['id', 'code', 'name_en', 'name_ar',
                      'usage_types', 'nature', 'has_stock', 'primary_uom_id']);
    }

    // BOM fix (reported bug) — the "Product" picker on a Bill of
    // Materials must only offer finished goods that are actually
    // manufactured, not every product in the company.
    public function scopeManufactured(Builder $query): Builder
    {
        return $query->where('nature', self::NATURE_MANUFACTURED);
    }

    // BOM fix (reported bug) — the "Component" picker on a Bill of
    // Materials must only offer Raw Materials, not every product.
    // usage_types is a JSON array column (migration 000033), so this
    // needs whereJsonContains rather than a plain where().
    public function scopeRawMaterial(Builder $query): Builder
    {
        return $query->whereJsonContains('usage_types', self::TYPE_RAW_MATERIAL);
    }

    // ── Relationships ─────────────────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function primaryUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'primary_uom_id');
    }

    public function uomLines(): HasMany
    {
        return $this->hasMany(ProductUomLine::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class)->orderByDesc('effective_date');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'revenue_account_id');
    }

    public function cogsAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'cogs_account_id');
    }

    public function inventoryAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'inventory_account_id');
    }

    public function fixedAssetAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'fixed_asset_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'accumulated_depreciation_account_id');
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isSaleable(): bool
    {
        return in_array(self::TYPE_SALEABLE, $this->usage_types ?? []);
    }

    public function isRawMaterial(): bool
    {
        return in_array(self::TYPE_RAW_MATERIAL, $this->usage_types ?? []);
    }

    public function isConsumable(): bool
    {
        return in_array(self::TYPE_CONSUMABLE, $this->usage_types ?? []);
    }

    public function isService(): bool
    {
        return $this->nature === self::NATURE_SERVICE;
    }

    public function isFixedAsset(): bool
    {
        return $this->nature === self::NATURE_FIXED_ASSET;
    }

    public function isManufactured(): bool
    {
        return $this->nature === self::NATURE_MANUFACTURED;
    }

    /**
     * Returns the effective selling price for a given date, UOM, and currency.
     * Used by the transaction engine — null if no price found.
     */
    public function currentSellingPrice(
        int    $uomId,
        int    $currencyId,
        string $date
    ): ?string {
        return $this->prices()
            ->where('price_type', 'selling_price')
            ->where('uom_id', $uomId)
            ->where('currency_id', $currencyId)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->value('price');
    }

    /**
     * Returns the effective purchase price for a given date, UOM, and currency.
     */
    public function currentPurchasePrice(
        int    $uomId,
        int    $currencyId,
        string $date
    ): ?string {
        return $this->prices()
            ->where('price_type', 'purchase_price')
            ->where('uom_id', $uomId)
            ->where('currency_id', $currencyId)
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->value('price');
    }
}
