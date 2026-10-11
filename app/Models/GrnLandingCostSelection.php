<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  GrnLandingCostSelection
//
//  See migration 000205. Records a draft GRN's intent to attach a
//  Landing Cost, and with which allocation base — written/synced
//  while the GRN is still draft, read (never written) by
//  GoodsReceiptNoteService::post() to drive the real attachment.
//
//  @layer    Model
//  @depends  BelongsToCompany, GoodsReceiptNote, LandingCost
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrnLandingCostSelection extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'goods_receipt_note_id', 'landing_cost_id',
        'allocation_base', 'manual_amounts',
    ];

    protected $casts = [
        'manual_amounts' => 'array',
    ];

    public function goodsReceiptNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class);
    }

    public function landingCost(): BelongsTo
    {
        return $this->belongsTo(LandingCost::class);
    }
}
