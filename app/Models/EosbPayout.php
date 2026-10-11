<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  EosbPayout   (audit M-36)
//
//  One end-of-service payout to an employee. Releases the provision
//  built up by the monthly EOSB runs; if what is owed is more than the
//  provision, the shortfall is expensed as a true-up. Reversed rows
//  keep reversed_at (never deleted).
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, JournalEntry
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;

class EosbPayout extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $fillable = [
        'company_id', 'partner_id', 'employee_payroll_profile_id', 'payout_date',
        'payable_amount', 'provision_released', 'true_up_expense',
        'journal_entry_id', 'notes', 'reversed_at', 'created_by',
    ];

    protected $casts = [
        'payout_date' => 'date', 'payable_amount' => 'decimal:2',
        'provision_released' => 'decimal:2', 'true_up_expense' => 'decimal:2', 'reversed_at' => 'datetime',
    ];

    public function scopeLive($q) { return $q->whereNull('reversed_at'); }
}
