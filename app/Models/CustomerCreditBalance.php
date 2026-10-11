<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CustomerCreditBalance
//
//  Audit M-9 — money the company owes a customer after a Credit Note
//  worth more than the invoice still owed (normally because the
//  invoice was already paid). Held in 235 Advances from Customers, in
//  the functional currency. Created by CreditNoteService::post(); used
//  up by CustomerCreditBalanceService (apply to an invoice, or refund
//  in cash).
//
//  remaining_amount = original − applied − refunded, always.
//
//  @layer    Model
//  @depends  CreditNote, CustomerInvoice, Partner,
//            CustomerCreditApplication, CustomerRefund
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerCreditBalance extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_OPEN     = 'open';
    const STATUS_CLOSED   = 'closed';
    const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'company_id', 'balance_code', 'partner_id', 'credit_note_id', 'customer_invoice_id',
        'credit_date', 'currency_id', 'exchange_rate',
        'original_amount', 'applied_amount', 'refunded_amount', 'remaining_amount',
        'wht_portion', 'wht_remaining',
        'status', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'credit_date'      => 'date',
        'exchange_rate'    => 'decimal:6',
        'original_amount'  => 'decimal:2',
        'applied_amount'   => 'decimal:2',
        'refunded_amount'  => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'wht_portion'      => 'decimal:2',
        'wht_remaining'    => 'decimal:2',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function customerInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(CustomerCreditApplication::class)->orderBy('application_date')->orderBy('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(CustomerRefund::class)->orderBy('refund_date')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
