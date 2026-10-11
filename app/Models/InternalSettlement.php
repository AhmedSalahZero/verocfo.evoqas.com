<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  InternalSettlement
//
//  See migration 000213. Nets a partner's Accounts Receivable
//  balance against their own Accounts Payable balance — same
//  partner, both a customer and a supplier at once. Written
//  exclusively through InternalSettlementService.
//
//  @layer    Model
//  @depends  BelongsToCompany, SerializesDateOnly, Partner, Currency,
//            JournalEntry, InternalSettlementCustomerAllocation,
//            InternalSettlementSupplierAllocation, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InternalSettlement extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'settlement_code', 'partner_id',
        'settlement_date', 'currency_id', 'amount',
        'status', 'journal_entry_id', 'internal_notes',
        'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'settlement_date' => 'date',
        'amount'          => 'decimal:2',
        'posted_at'       => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function customerAllocations(): HasMany
    {
        return $this->hasMany(InternalSettlementCustomerAllocation::class);
    }

    public function supplierAllocations(): HasMany
    {
        return $this->hasMany(InternalSettlementSupplierAllocation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    // ── Helpers ───────────────────────────────────────────────────

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
        return $this->settlement_code;
    }
}
