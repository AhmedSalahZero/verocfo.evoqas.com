<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  GoodsReceiptNote
//
//  Accounting Engine Phase 2. See migration 000100 for the full
//  design rationale (grn_policy, currency simplification, status
//  lifecycle). Written exclusively through GoodsReceiptNoteService.
//
//  @layer    Model
//  @depends  BelongsToCompany, PurchaseOrder, Partner, InventoryLocation,
//            JournalEntry, GoodsReceiptNoteLine, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceiptNote extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'grn_code', 'purchase_order_id', 'partner_id',
        'inventory_location_id', 'received_date', 'exchange_rate',
        'grn_value', 'status', 'journal_entry_id', 'supplier_reference',
        'internal_notes', 'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'received_date' => 'date',
        'exchange_rate'  => 'decimal:6',
        'grn_value'      => 'decimal:2',
        'posted_at'      => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** Supplier — always a supplier-type Partner. */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function inventoryLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptNoteLine::class)->orderBy('sort_order');
    }

    /** Every Landing Cost attached to this receipt — see LandingCost::isAttached(). */
    public function landingCosts(): HasMany
    {
        return $this->hasMany(LandingCost::class);
    }

    /** This draft's picks — not yet attached, see GrnLandingCostSelection header. */
    public function landingCostSelections(): HasMany
    {
        return $this->hasMany(GrnLandingCostSelection::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
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

    public function scopeAdHoc(Builder $query): Builder
    {
        return $query->whereNull('purchase_order_id');
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

    public function isAdHoc(): bool
    {
        return is_null($this->purchase_order_id);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->grn_code . ($this->purchaseOrder ? ' — ' . $this->purchaseOrder->po_code : ' — ad-hoc');
    }
}
