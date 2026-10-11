<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  KitRecipe
//
//  The header for an Assembly/Kit recipe — which product is a kit,
//  and (via lines()) what components make it up. See migration
//  000251 header for why this is deliberately unversioned, unlike
//  BillOfMaterial.
//
//  @layer    Model
//  @depends  BelongsToCompany, Product, KitRecipeLine, KitAssembly
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KitRecipe extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'product_id', 'is_active', 'notes', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(KitRecipeLine::class)->orderBy('sort_order');
    }

    public function assemblies(): HasMany
    {
        return $this->hasMany(KitAssembly::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
