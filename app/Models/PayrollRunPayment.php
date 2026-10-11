<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PayrollRunPayment   (audit M-36)
//
//  The net-pay payment of a posted payroll run: Dr 239 Salaries
//  Payable / Cr the bank account's ledger account. One live payment
//  per run; reversing it sets reversed_at (never deleted).
//
//  @layer    Model
//  @depends  BelongsToCompany, PayrollRun, JournalEntry
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;

class PayrollRunPayment extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $fillable = [
        'company_id', 'payroll_run_id', 'bank_account_id', 'payment_date',
        'amount', 'journal_entry_id', 'reversed_at', 'created_by',
    ];

    protected $casts = ['payment_date' => 'date', 'amount' => 'decimal:2', 'reversed_at' => 'datetime'];

    public function scopeLive($q) { return $q->whereNull('reversed_at'); }
}
