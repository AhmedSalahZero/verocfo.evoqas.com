<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  TaxOverride
//
//  Specific VAT / Debit WHT rates for a category or product.
//  Either category_id OR product_id must be set — not both.
//
//  Resolution priority (enforced in TaxService):
//    1. Product-level override  (most specific)
//    2. Category-level override
//    3. Company default rate    (fallback)
//
//  null rate = "do not override this tax for this level"
//  e.g. vat_rate = null + debit_wht_rate = 20.00 means:
//    override WHT to 20% but inherit VAT from company default
//
//  @layer    Model
//  @depends  BelongsToCompany, ProductCategory, Product
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxOverride extends Model
{
    use BelongsToCompany;

    protected $table = 'tax_overrides';

    protected $fillable = [
        'company_id',
        'category_id',
        'product_id',
        'vat_rate',
        'debit_wht_rate',
        'notes',
    ];

    protected $casts = [
        'vat_rate'       => 'decimal:2',
        'debit_wht_rate' => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function isProductLevel(): bool   { return ! is_null($this->product_id); }
    public function isCategoryLevel(): bool  { return ! is_null($this->category_id) && is_null($this->product_id); }
}
