<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  LandingCost
//
//  See migration 000203 for the full design rationale and
//  VeroCFO_Landing_Cost_Design_v1_0.md for the complete feature
//  design. A standalone document (never linked to a Purchase Order)
//  representing one logistics cost — customs dues, transportation,
//  etc. — tied to a shipment.
//
//  Two independent things happen to one record over its life:
//    - the LIABILITY (create → optionally pay) — tracked by `status`
//    - the ATTACHMENT to a Goods Receipt Note — tracked by
//      `goods_receipt_note_id` / `attached_at`, completely separate
//      from status (a Landing Cost can be attached whether or not
//      it's been paid yet)
//
//  Once `goods_receipt_note_id` is set, this record is permanently
//  used — see isEligibleForAttachment() below, the single source of
//  truth for "can this still be picked" everywhere in the app.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, ChartOfAccount, GoodsReceiptNote,
//            BankAccount, JournalEntry, LandingCostAllocation, User
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\BankAccount;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandingCost extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_PAID      = 'paid';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'landing_cost_code', 'partner_id', 'chart_of_account_id',
        'cost_date', 'amount', 'currency_id', 'exchange_rate', 'amount_functional', 'vat_amount', 'vat_amount_functional', 'reference', 'status',
        'journal_entry_id',
        'payment_journal_entry_id', 'paid_bank_account_id', 'paid_date',
        'goods_receipt_note_id', 'attached_at',
        'internal_notes', 'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'cost_date'   => 'date',
        'amount'      => 'decimal:2',
        'amount_functional' => 'decimal:2',
        'vat_amount'  => 'decimal:2',
        'vat_amount_functional' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'paid_date'   => 'date',
        'attached_at' => 'datetime',
        'posted_at'   => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    /** The Landing Cost Supplier (partners.is_landing_cost_supplier = true). */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** The 146xxx Landing Cost Clearing sub-account this cost sits in until attached. */
    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function paymentJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'payment_journal_entry_id');
    }

    public function paidBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'paid_bank_account_id');
    }

    public function goodsReceiptNote(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptNote::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LandingCostAllocation::class);
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

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID);
    }

    /**
     * Posted (or paid — either is fine), not cancelled, and not yet
     * attached to any GRN — the single query every "eligible landing
     * costs" picker in the app uses. Deliberately does NOT filter on
     * paid/unpaid, since attachment and payment are independent (see
     * class header).
     */
    public function scopeEligibleForAttachment(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_POSTED, self::STATUS_PAID])
            ->whereNull('goods_receipt_note_id');
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

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isAttached(): bool
    {
        return ! is_null($this->goods_receipt_note_id);
    }

    /** Single source of truth for "can this still be picked at GRN time" — see class header. */
    public function isEligibleForAttachment(): bool
    {
        return in_array($this->status, [self::STATUS_POSTED, self::STATUS_PAID], true)
            && ! $this->isAttached();
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->landing_cost_code;
    }
}
