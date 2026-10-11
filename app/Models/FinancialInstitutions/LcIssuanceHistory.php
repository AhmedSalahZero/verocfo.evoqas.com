<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LcIssuanceHistory
//
//  Append-only lifecycle log for one LetterOfCreditIssuance —
//  mark_paid and settlement events, each independently undoable, most
//  recent first. See migration 000147 header for the full rationale.
//
//  @layer    Model
//  @depends  BelongsToCompany, LetterOfCreditIssuance, SupplierBill,
//            BankAccount, ChartOfAccount, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\SupplierBill;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LcIssuanceHistory extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'lc_issuance_history';

    protected $fillable = [
        'company_id',
        'lc_issuance_id',
        'event_type',
        'event_date',
        'supplier_bill_id',
        'amount_paid_to_supplier',
        'bank_debt_opened',
        'principal_amount',
        'interest_amount',
        'interest_expense_account_id',
        'settlement_from_account_id',
        'bank_debt_after_event',
        'old_bank_debt_outstanding',
        'journal_entry_id',
        'cash_cover_released_amount',
        'notes',
        'created_by',
        'undone_at',
        'undone_by',
    ];

    protected $casts = [
        'event_date'               => 'date',
        'amount_paid_to_supplier'  => 'decimal:2',
        'bank_debt_opened'         => 'decimal:2',
        'cash_cover_released_amount' => 'decimal:2',
        'principal_amount'         => 'decimal:2',
        'interest_amount'          => 'decimal:2',
        'bank_debt_after_event'    => 'decimal:2',
        'old_bank_debt_outstanding' => 'decimal:2',
        'undone_at'                => 'datetime',
    ];

    public function lcIssuance(): BelongsTo   { return $this->belongsTo(LetterOfCreditIssuance::class, 'lc_issuance_id'); }
    public function supplierBill(): BelongsTo { return $this->belongsTo(SupplierBill::class); }
    public function interestExpenseAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'interest_expense_account_id'); }
    public function settlementFromAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'settlement_from_account_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function createdBy(): BelongsTo    { return $this->belongsTo(User::class, 'created_by'); }
    public function undoneBy(): BelongsTo     { return $this->belongsTo(User::class, 'undone_by'); }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }
}
