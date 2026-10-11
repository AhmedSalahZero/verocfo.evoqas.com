<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LcIssuanceExpense
//
//  Miscellaneous expense against a live LC — see migration 000148
//  header. Ported from CashVero's LcIssuanceExpense; each row here
//  posts (and, on edit/delete, reverses) a real journal entry.
//
//  @layer    Model
//  @depends  BelongsToCompany, LetterOfCreditIssuance, ChartOfAccount,
//            BankAccount, Currency, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LcIssuanceExpense extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'lc_issuance_expenses';

    public const STATUS_POSTED   = 'posted';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'company_id',
        'lc_issuance_id',
        'expense_name',
        'expense_date',
        'amount',
        'currency_id',
        'exchange_rate',
        'expense_account_id',
        'paid_from_account_id',
        'status',
        'journal_entry_id',
        'created_by',
    ];

    protected $casts = [
        'expense_date'  => 'date',
        'amount'        => 'decimal:2',
        'exchange_rate' => 'decimal:6',
    ];

    public function lcIssuance(): BelongsTo    { return $this->belongsTo(LetterOfCreditIssuance::class, 'lc_issuance_id'); }
    public function currency(): BelongsTo      { return $this->belongsTo(Currency::class); }
    public function expenseAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'expense_account_id'); }
    public function paidFromAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'paid_from_account_id'); }
    public function journalEntry(): BelongsTo  { return $this->belongsTo(JournalEntry::class); }
    public function createdBy(): BelongsTo     { return $this->belongsTo(User::class, 'created_by'); }
}
