<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  KitRecipeLine
//
//  A component of a KitRecipe. quantity_per_kit is scaled by
//  KitAssemblyService against however many kit units are being
//  assembled. See migration 000252 header for why there's no cost
//  column here — cost is always resolved at assembly time from the
//  component's current WAC.
//
//  @layer    Model
//  @depends  BelongsToCompany, KitRecipe, Product
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KitRecipeLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'kit_recipe_id', 'component_product_id',
        'quantity_per_kit', 'sort_order',
    ];

    protected $casts = [
        'quantity_per_kit' => 'decimal:4',
    ];

    public function kitRecipe(): BelongsTo
    {
        return $this->belongsTo(KitRecipe::class);
    }

    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }
}
