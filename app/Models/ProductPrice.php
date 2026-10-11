<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  ProductPrice
//
//  Date-effective selling and purchase prices. Append-only.
//  No updated_at — records are never modified.
//
//  @layer    Model
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class ProductPrice extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'product_id', 'price_type',
        'uom_id', 'currency_id', 'price',
        'effective_date', 'notes', 'created_by', 'created_at',
    ];

    protected $casts = [
        'price'          => 'decimal:4',
        'effective_date' => 'date',
        'created_at'     => 'datetime',
    ];

    const TYPE_SELLING  = 'selling_price';
    const TYPE_PURCHASE = 'purchase_price';

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'uom_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
