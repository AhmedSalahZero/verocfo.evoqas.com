<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  SupplierBill
//
//  See migration 000106 for the full design rationale (bill_type,
//  currency/functional dual amounts, duplicate-invoice check).
//  Written exclusively through SupplierBillService.
//
//  Supplier Payment (August 2026) — amount_paid/amount_outstanding
//  added (migration 000136), mirroring customer_invoices exactly.
//  amount_outstanding tracks the NET AP amount (already post-WHT —
//  see SupplierBillService::post()), never the gross bill_value —
//  the withheld portion was never owed to the supplier in the first
//  place, so Supplier Payment settling against amount_outstanding
//  automatically pays out the correct net figure with zero WHT logic
//  of its own needed.
//
//  Opening balances (Audit C7, Sept 2026) — is_opening_balance = true
//  marks an open item created when opening balances were posted
//  (code OB-…, no lines, no order, born 'posted'). It can be paid and
//  aged like any other but never edited, reopened or cancelled; the
//  service refuses those. ob_partner_invoice_id links it to its row.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, PurchaseOrder, Currency,
//            PaymentTerm, JournalEntry, SupplierBillLine, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\SerializesDateOnly;

class SupplierBill extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const TYPE_GOODS   = 'goods';
    const TYPE_EXPENSE = 'expense';
    const TYPE_PREPAID = 'prepaid'; // see migration 000192 — Expense Bill in every respect except amortization

    const STATUS_DRAFT     = 'draft';
    const STATUS_POSTED    = 'posted';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'bill_code', 'bill_type', 'partner_id', 'purchase_order_id',
        'supplier_invoice_number', 'supplier_invoice_date', 'bill_date',
        'original_due_date', 'due_date',
        'currency_id', 'exchange_rate',
        'subtotal_amount', 'vat_amount', 'bill_value',
        'subtotal_amount_functional', 'vat_amount_functional', 'bill_value_functional',
        'withholding_tax_amount', 'withholding_tax_amount_functional',
        'amount_paid', 'amount_credited', 'amount_debited', 'amount_outstanding',
        'payment_term_id', 'status', 'journal_entry_id', 'internal_notes',
        'is_opening_balance', 'ob_partner_invoice_id',
        'created_by', 'updated_by', 'posted_by', 'posted_at',
    ];

    protected $casts = [
        'supplier_invoice_date'        => 'date',
        'bill_date'                    => 'date',
        'original_due_date'            => 'date',
        'due_date'                      => 'date',
        'exchange_rate'                => 'decimal:6',
        'subtotal_amount'              => 'decimal:2',
        'vat_amount'                   => 'decimal:2',
        'bill_value'                   => 'decimal:2',
        'subtotal_amount_functional'   => 'decimal:2',
        'vat_amount_functional'        => 'decimal:2',
        'bill_value_functional'        => 'decimal:2',
        'withholding_tax_amount'              => 'decimal:2',
        'withholding_tax_amount_functional'    => 'decimal:2',
        'amount_paid'                   => 'decimal:2',
        'amount_credited'               => 'decimal:2',
        'amount_debited'                => 'decimal:2',
        'amount_outstanding'            => 'decimal:2',
        'posted_at'                    => 'datetime',
        'is_opening_balance'           => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────

    /** Supplier — always a supplier-type Partner. */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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

    /** Every payment allocation currently matched against this bill. */
    public function allocations(): HasMany
    {
        return $this->hasMany(\App\Models\SupplierPaymentAllocation::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class)->orderBy('sort_order');
    }

    public function debitNotes(): HasMany
    {
        return $this->hasMany(SupplierDebitNote::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(SupplierCreditNote::class);
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

    public function scopeGoods(Builder $query): Builder
    {
        return $query->where('bill_type', self::TYPE_GOODS);
    }

    public function scopeExpense(Builder $query): Builder
    {
        return $query->where('bill_type', self::TYPE_EXPENSE);
    }

    public function scopePrepaid(Builder $query): Builder
    {
        return $query->where('bill_type', self::TYPE_PREPAID);
    }

    /** Posted bills with something still owed — Supplier Payment's Settlement Information table. */
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

    public function isGoodsBill(): bool
    {
        return $this->bill_type === self::TYPE_GOODS;
    }

    public function isPrepaidBill(): bool
    {
        return $this->bill_type === self::TYPE_PREPAID;
    }

    public function isFullySettled(): bool
    {
        return $this->isPosted() && bccomp((string) $this->amount_outstanding, '0', 2) <= 0;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->bill_code . ($this->supplier_invoice_number ? " (Inv# {$this->supplier_invoice_number})" : '');
    }
}
