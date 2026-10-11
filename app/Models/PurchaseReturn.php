<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PurchaseReturn
//
//  Goods sent back to a supplier against a posted Goods Receipt Note
//  (Audit H16). See migration 000305 and PurchaseReturnService. Posted
//  on creation; cancelled (everything reversed), never deleted.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, GoodsReceiptNote,
//            PurchaseReturnLine, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReturn extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'return_code', 'partner_id', 'goods_receipt_note_id', 'return_date', 'status',
        'total_cost', 'journal_entry_id', 'debit_note_ids', 'reason',
        'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $casts = [
        'return_date'    => 'date',
        'total_cost'     => 'decimal:2',
        'debit_note_ids' => 'array',
        'cancelled_at'   => 'datetime',
    ];

    public function lines(): HasMany              { return $this->hasMany(PurchaseReturnLine::class); }
    public function partner(): BelongsTo          { return $this->belongsTo(Partner::class); }
    public function goodsReceiptNote(): BelongsTo { return $this->belongsTo(GoodsReceiptNote::class); }
    public function journalEntry(): BelongsTo     { return $this->belongsTo(JournalEntry::class); }
    public function creator(): BelongsTo          { return $this->belongsTo(User::class, 'created_by'); }
}
