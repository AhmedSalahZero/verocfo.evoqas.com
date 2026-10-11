<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CustomerDebitNote
//
//  See migration 000194 for the full design rationale (mandatory
//  customer_invoice_id link — every debit note increases a specific
//  real invoice's balance, no free-floating debits).
//
//  @layer    Model
//  @depends  BelongsToCompany, CustomerInvoice, Partner, Currency,
//            JournalEntry, CustomerDebitNoteLine, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerDebitNote extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'debit_note_code', 'customer_invoice_id', 'partner_id',
        'debit_date', 'debit_reason', 'currency_id', 'exchange_rate',
        'subtotal_amount', 'vat_amount', 'debit_value',
        'subtotal_amount_functional', 'vat_amount_functional', 'debit_value_functional',
        'status', 'journal_entry_id', 'internal_notes',
        'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'debit_date'                   => 'date',
        'exchange_rate'                 => 'decimal:6',
        'subtotal_amount'                => 'decimal:2',
        'vat_amount'                      => 'decimal:2',
        'debit_value'                     => 'decimal:2',
        'subtotal_amount_functional'      => 'decimal:2',
        'vat_amount_functional'           => 'decimal:2',
        'debit_value_functional'         => 'decimal:2',
        'posted_at'                      => 'datetime',
    ];

    public function customerInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class);
    }

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

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerDebitNoteLine::class)->orderBy('sort_order');
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
        return $this->debit_note_code;
    }
}
