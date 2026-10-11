<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  SupplierDebitNote
//
//  See migration 000197 for the full design rationale (mandatory
//  supplier_bill_id link, decreases Accounts Payable, optional free-
//  text supplier_credit_note_reference for the supplier's own
//  confirming document).
//
//  @layer    Model
//  @depends  BelongsToCompany, SupplierBill, Partner, Currency,
//            JournalEntry, SupplierDebitNoteLine, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierDebitNote extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'debit_note_code', 'supplier_bill_id', 'partner_id',
        'debit_date', 'debit_reason', 'supplier_credit_note_reference',
        'currency_id', 'exchange_rate',
        'subtotal_amount', 'vat_amount', 'debit_value',
        'subtotal_amount_functional', 'vat_amount_functional', 'debit_value_functional',
        'status', 'journal_entry_id', 'internal_notes',
        'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'debit_date'                    => 'date',
        'exchange_rate'                  => 'decimal:6',
        'subtotal_amount'                 => 'decimal:2',
        'vat_amount'                       => 'decimal:2',
        'debit_value'                      => 'decimal:2',
        'subtotal_amount_functional'       => 'decimal:2',
        'vat_amount_functional'            => 'decimal:2',
        'debit_value_functional'          => 'decimal:2',
        'posted_at'                       => 'datetime',
    ];

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
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
        return $this->hasMany(SupplierDebitNoteLine::class)->orderBy('sort_order');
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
