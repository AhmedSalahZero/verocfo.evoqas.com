<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetImpairment
//
//  One IAS 36 write-down of a fixed asset's carrying amount (Audit
//  H17). Dr 546 Impairment Loss / Cr the asset's accumulated
//  depreciation account. See migration 000303. Cancelled, never
//  deleted.
//
//  @layer    Model
//  @depends  BelongsToCompany, FixedAsset, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetImpairment extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'fixed_asset_id', 'impairment_date', 'amount', 'carrying_amount_before', 'reason',
        'status', 'journal_entry_id', 'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'impairment_date'        => 'date',
        'amount'                 => 'decimal:2',
        'carrying_amount_before' => 'decimal:2',
        'cancelled_at'           => 'datetime',
    ];

    public function fixedAsset(): BelongsTo   { return $this->belongsTo(FixedAsset::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function creator(): BelongsTo      { return $this->belongsTo(User::class, 'created_by'); }
}
