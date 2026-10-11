<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  LandingCostAllocation
//
//  See migration 000204. One row per (Landing Cost, Goods Receipt
//  Note Line) pair — written once, at attach time, alongside the
//  GRN's own posting. Never edited afterward — a posted GRN is
//  immutable, same principle as everywhere else in this project.
//
//  @layer    Model
//  @depends  BelongsToCompany, LandingCost, GoodsReceiptNoteLine
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingCostAllocation extends Model
{
    use BelongsToCompany;

    const BASE_VALUE    = 'value';
    const BASE_QUANTITY = 'quantity';
    const BASE_WEIGHT   = 'weight';
    const BASE_MANUAL   = 'manual';

    protected $fillable = [
        'company_id', 'landing_cost_id', 'goods_receipt_note_line_id',
        'allocation_base', 'allocated_amount',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
    ];

    public function landingCost(): BelongsTo
    {
        return $this->belongsTo(LandingCost::class);
    }

    public function goodsReceiptNoteLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNoteLine::class);
    }
}
