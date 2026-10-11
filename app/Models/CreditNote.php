<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CreditNote
//
//  See migration 000120 for the full design rationale (mandatory
//  customer_invoice_id link — every credit note reverses a specific
//  real invoice, no free-floating credits).
//
//  @layer    Model
//  @depends  BelongsToCompany, CustomerInvoice, Partner, Currency,
//            JournalEntry, CreditNoteLine, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditNote extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_REVERSED  = 'reversed'; // Audit M9

    protected $fillable = [
        'company_id', 'credit_note_code', 'customer_invoice_id', 'partner_id',
        'credit_date', 'credit_reason', 'currency_id', 'exchange_rate',
        'subtotal_amount', 'vat_amount', 'credit_value',
        'subtotal_amount_functional', 'vat_amount_functional', 'credit_value_functional',
        'credit_balance_functional', // Audit M9 — the part that became a Customer Credit Balance
        'status', 'journal_entry_id', 'internal_notes',
        'created_by', 'updated_by', 'posted_by', 'posted_at',
        'reversed_by', 'reversed_at', 'reversal_reason', // Audit M9
    ];

    protected $casts = [
        'credit_date'                   => 'date',
        'exchange_rate'                 => 'decimal:6',
        'subtotal_amount'               => 'decimal:2',
        'vat_amount'                     => 'decimal:2',
        'credit_value'                   => 'decimal:2',
        'subtotal_amount_functional'     => 'decimal:2',
        'vat_amount_functional'          => 'decimal:2',
        'credit_value_functional'        => 'decimal:2',
        'credit_balance_functional'      => 'decimal:2',
        'posted_at'                      => 'datetime',
        'reversed_at'                    => 'datetime',
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
        return $this->hasMany(CreditNoteLine::class)->orderBy('sort_order');
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

    public function creditBalance(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CustomerCreditBalance::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->credit_note_code;
    }
}
