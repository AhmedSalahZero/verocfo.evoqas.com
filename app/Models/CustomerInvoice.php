<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CustomerInvoice
//
//  See migration 000118 for the full design rationale (mandatory
//  Sales Order link, currency/functional dual amounts,
//  amount_paid/amount_outstanding tracking for payment matching).
//  Written exclusively through CustomerInvoiceService.
//
//  Opening balances (Audit C7, Sept 2026) — is_opening_balance = true
//  marks an open item created when opening balances were posted
//  (code OB-…, no lines, no order, born 'posted'). It can be paid and
//  aged like any other but never edited, reopened or cancelled; the
//  service refuses those. ob_partner_invoice_id links it to its row.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, SalesOrder, Currency,
//            PaymentTerm, JournalEntry, CustomerInvoiceLine, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerInvoice extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'invoice_code', 'sales_order_id', 'partner_id',
        'invoice_date', 'currency_id', 'exchange_rate',
        'original_due_date', 'due_date',
        'subtotal_amount', 'vat_amount', 'invoice_value',
        'subtotal_amount_functional', 'vat_amount_functional', 'invoice_value_functional',
        'amount_paid', 'amount_credited', 'amount_debited', 'amount_wht_deducted', 'amount_outstanding',
        'payment_term_id', 'status', 'exceeds_so_value', 'journal_entry_id', 'internal_notes',
        'is_opening_balance', 'ob_partner_invoice_id',
        'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'invoice_date'                    => 'date',
        'original_due_date'               => 'date',
        'due_date'                         => 'date',
        'exchange_rate'                   => 'decimal:6',
        'subtotal_amount'                 => 'decimal:2',
        'vat_amount'                       => 'decimal:2',
        'invoice_value'                    => 'decimal:2',
        'subtotal_amount_functional'       => 'decimal:2',
        'vat_amount_functional'            => 'decimal:2',
        'invoice_value_functional'         => 'decimal:2',
        'amount_paid'                      => 'decimal:2',
        'amount_credited'                  => 'decimal:2',
        'amount_debited'                   => 'decimal:2',
        'amount_wht_deducted'              => 'decimal:2',
        'amount_outstanding'               => 'decimal:2',
        'exceeds_so_value'                 => 'boolean',
        'is_opening_balance'               => 'boolean',
        'posted_at'                        => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    /** Customer — always a customer-type Partner. */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** Every payment allocation currently matched against this invoice. */
    public function allocations(): HasMany
    {
        return $this->hasMany(\App\Models\CustomerPaymentAllocation::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerInvoiceLine::class)->orderBy('sort_order');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(CustomerDebitNote::class);
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(CustomerPaymentAllocation::class);
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

    /** Posted invoices that still have something owed — the payment-matching pool. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED)->where('amount_outstanding', '>', 0);
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

    public function isFullyPaid(): bool
    {
        return $this->isPosted() && bccomp((string) $this->amount_outstanding, '0', 2) <= 0;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->invoice_code;
    }
}
