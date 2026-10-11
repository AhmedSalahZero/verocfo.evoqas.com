<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CustomerCreditApplication
//
//  Audit M-9 — one use of a Customer Credit Balance against an open
//  invoice (Dr 235 / Cr Accounts Receivable). Undoing it reverses its
//  entry and marks the row 'reversed' — rows are never deleted.
//
//  @layer    Model
//  @depends  CustomerCreditBalance, CustomerInvoice
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCreditApplication extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_APPLIED  = 'applied';
    const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'company_id', 'customer_credit_balance_id', 'customer_invoice_id',
        'application_date', 'amount', 'wht_released', 'status',
        'journal_entry_id', 'created_by', 'reversed_by', 'reversed_at',
    ];

    protected $casts = [
        'application_date' => 'date',
        'amount'           => 'decimal:2',
        'wht_released'     => 'decimal:2',
        'reversed_at'      => 'datetime',
    ];

    public function balance(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditBalance::class, 'customer_credit_balance_id');
    }

    public function customerInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class);
    }
}
