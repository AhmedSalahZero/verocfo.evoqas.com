<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CustomerRefund
//
//  Audit M-9 — cash paid back to a customer out of a Customer Credit
//  Balance: Dr 235 (the amount) / Cr cash safe or bank (cash_amount)
//  / Cr 142 Debit WHT Receivable (wht_adjustment — the withholding the
//  customer already took on the paid invoice, given up in proportion).
//  A reversed refund keeps its row, marked 'reversed'.
//
//  @layer    Model
//  @depends  CustomerCreditBalance, Partner, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerRefund extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_POSTED   = 'posted';
    const STATUS_REVERSED = 'reversed';

    const SOURCE_CASH_SAFE    = 'cash_safe';
    const SOURCE_BANK_ACCOUNT = 'bank_account';

    protected $fillable = [
        'company_id', 'refund_code', 'customer_credit_balance_id', 'partner_id',
        'refund_date', 'amount', 'wht_adjustment', 'cash_amount',
        'source_type', 'bank_account_id', 'safe_account_id', 'reference', 'notes',
        'status', 'journal_entry_id', 'created_by',
        'reversed_by', 'reversed_at', 'reversal_reason',
    ];

    protected $casts = [
        'refund_date'    => 'date',
        'amount'         => 'decimal:2',
        'wht_adjustment' => 'decimal:2',
        'cash_amount'    => 'decimal:2',
        'reversed_at'    => 'datetime',
    ];

    public function balance(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditBalance::class, 'customer_credit_balance_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
