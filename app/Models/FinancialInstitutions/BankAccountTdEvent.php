<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankAccountTdEvent
//
//  One row per Time Deposit lifecycle event — 'interest', 'break',
//  'maturity', or 'renewal'. See migration
//  2026_01_01_000117_create_bank_account_td_events_table for the
//  full field-by-event-type breakdown. Append-only, same pattern as
//  BankAccountTermsHistory and LeaseWearDownEntry — never updated or
//  deleted once posted (a mistake gets reversed via the journal
//  entry's own reversal, not by editing this row).
//
//  @layer    Model
//  @depends  BankAccount, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\JournalEntry;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccountTdEvent extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'bank_account_td_events';

    protected $fillable = [
        'company_id', 'bank_account_id', 'event_type', 'event_date',
        'interest_amount', 'wht_rate', 'wht_amount', 'principal_amount',
        'old_maturity_date', 'new_maturity_date',
        'old_interest_rate', 'new_interest_rate',
        'credit_bank_account_id', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'event_date'         => 'date',
        'interest_amount'    => 'decimal:2',
        'principal_amount'   => 'decimal:2',
        'old_maturity_date'  => 'date',
        'new_maturity_date'  => 'date',
        'old_interest_rate'  => 'decimal:4',
        'new_interest_rate'  => 'decimal:4',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** The account the cash actually landed in — Rule 31, carried directly on this row. */
    public function creditBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'credit_bank_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isInterest(): bool { return $this->event_type === 'interest'; }
    public function isBreak(): bool    { return $this->event_type === 'break'; }
    public function isMaturity(): bool { return $this->event_type === 'maturity'; }
    public function isRenewal(): bool  { return $this->event_type === 'renewal'; }
}
