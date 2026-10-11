<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetDisposal
//
//  One sale or scrapping of a fixed asset (Audit H17 — IAS 16). Holds
//  the snapshot of what left the books and the gain / loss posted.
//  See migration 000303. Written only by FixedAssetDisposalService;
//  cancelled (journal entry reversed), never deleted.
//
//  @layer    Model
//  @depends  BelongsToCompany, FixedAsset, Partner, ChartOfAccount,
//            JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FixedAssetDisposal extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const TYPE_SALE  = 'sale';
    const TYPE_SCRAP = 'scrap';

    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'fixed_asset_id', 'disposal_date', 'disposal_type', 'partner_id',
        'proceeds', 'vat_amount', 'proceeds_account_id', 'cost_basis', 'accumulated_depreciation',
        'net_book_value', 'gain_loss', 'previous_status', 'depreciation_entry_id', 'status',
        'journal_entry_id', 'notes', 'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'disposal_date'            => 'date',
        'proceeds'                 => 'decimal:2',
        'vat_amount'               => 'decimal:2',
        'cost_basis'               => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'net_book_value'           => 'decimal:2',
        'gain_loss'                => 'decimal:2',
        'cancelled_at'             => 'datetime',
    ];

    public function fixedAsset(): BelongsTo     { return $this->belongsTo(FixedAsset::class); }
    public function partner(): BelongsTo        { return $this->belongsTo(Partner::class); }
    public function proceedsAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'proceeds_account_id'); }
    public function journalEntry(): BelongsTo   { return $this->belongsTo(JournalEntry::class); }
    public function creator(): BelongsTo        { return $this->belongsTo(User::class, 'created_by'); }
}
