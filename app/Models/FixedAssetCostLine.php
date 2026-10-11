<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetCostLine
//
//  One row per line contributing to a FixedAsset's cost_basis — see
//  migration 000169 header for the full design rationale (any
//  supplier/PO/Bill line can add to an existing asset's cost, not
//  only the line that originally created it).
//
//  @layer    Model
//  @depends  BelongsToCompany, FixedAsset, GoodsReceiptNoteLine,
//            SupplierBillLine, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetCostLine extends Model
{
    use BelongsToCompany;

    public const TYPE_ASSET_COST   = 'asset_cost';
    public const TYPE_FREIGHT      = 'freight';
    public const TYPE_INSTALLATION = 'installation';
    public const TYPE_CUSTOMS      = 'customs';
    public const TYPE_OTHER        = 'other';

    protected $fillable = [
        'company_id',
        'fixed_asset_id',
        'goods_receipt_note_line_id',
        'supplier_bill_line_id',
        'cost_type',
        'amount',
        'is_provisional',
        'description',
        'created_by',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'is_provisional' => 'boolean',
    ];

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function goodsReceiptNoteLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNoteLine::class);
    }

    public function supplierBillLine(): BelongsTo
    {
        return $this->belongsTo(SupplierBillLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
