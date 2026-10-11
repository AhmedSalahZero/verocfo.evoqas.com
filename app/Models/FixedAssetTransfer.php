<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetTransfer
//
//  One move of a fixed asset between locations (Audit H17). No ledger
//  effect — an audit trail of where the asset has been. See migration
//  000303.
//
//  @layer    Model
//  @depends  BelongsToCompany, FixedAsset, InventoryLocation, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetTransfer extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $fillable = [
        'company_id', 'fixed_asset_id', 'transfer_date', 'from_location_id', 'to_location_id', 'notes', 'created_by',
    ];

    protected $casts = ['transfer_date' => 'date'];

    public function fixedAsset(): BelongsTo   { return $this->belongsTo(FixedAsset::class); }
    public function fromLocation(): BelongsTo { return $this->belongsTo(InventoryLocation::class, 'from_location_id'); }
    public function toLocation(): BelongsTo   { return $this->belongsTo(InventoryLocation::class, 'to_location_id'); }
    public function creator(): BelongsTo      { return $this->belongsTo(User::class, 'created_by'); }
}
