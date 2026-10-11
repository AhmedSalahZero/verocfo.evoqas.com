<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  DeliveryNote
//
//  See migration 000129 for the full design rationale. The outbound
//  mirror of GoodsReceiptNote — written exclusively through
//  DeliveryNoteService.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, SalesOrder, JournalEntry,
//            DeliveryNoteLine, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryNote extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'delivery_code', 'sales_order_id', 'partner_id',
        'delivery_date', 'status',
        'inventory_journal_entry_id', 'revenue_journal_entry_id',
        'internal_notes', 'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'posted_at'     => 'datetime',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function inventoryJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'inventory_journal_entry_id');
    }

    public function revenueJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'revenue_journal_entry_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class)->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->delivery_code;
    }
}
