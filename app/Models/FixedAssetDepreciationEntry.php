<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetDepreciationEntry
//
//  One row per posted monthly depreciation period per asset — see
//  migration 000170 header. Reversible (never edited in place),
//  same shape as Leasing's lease_wear_down_entries (Building Bible
//  Rule 29.7).
//
//  @layer    Model
//  @depends  BelongsToCompany, FixedAsset, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetDepreciationEntry extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    public const STATUS_POSTED   = 'posted';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'company_id',
        'fixed_asset_id',
        'period_date',
        'amount',
        'journal_entry_id',
        'status',
        'reversed_by',
        'reversed_at',
        'created_by',
    ];

    protected $casts = [
        'period_date' => 'date',
        'amount'      => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }
}
