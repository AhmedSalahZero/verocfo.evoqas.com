<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  MtlMovement
//
//  TRS-002. One accounting event on a Medium-Term Loan: the drawdown,
//  or the principal and interest halves of a settlement. Modelled on
//  LgIssuanceMovement, which the TODO in MtlService named as the
//  pattern to follow rather than inventing a second mechanism.
//
//  Each row carries its own debit and credit leg. A leg is either a
//  Chart of Accounts row directly, or a bank account that resolves to
//  its COA link when the movement is posted — which is why
//  hasPostableLegs() checks for one of each rather than for both COA
//  columns being present.
//
//  @layer    Model
//  @depends  CreditFacility, MtlScheduleLine, MtlSettlement,
//            ChartOfAccount, BankAccount, Currency, JournalEntry
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\Traits\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MtlMovement extends Model
{
    use BelongsToCompany;

    const TYPE_DRAWDOWN            = 'drawdown';
    const TYPE_PRINCIPAL_REPAYMENT = 'principal_repayment';
    const TYPE_INTEREST_EXPENSE    = 'interest_expense';

    const STATUS_PENDING  = 'pending_gl_posting';
    const STATUS_POSTED   = 'posted';
    const STATUS_REVERSED = 'reversed';
    const STATUS_MEMO     = 'memo_only';

    protected $fillable = [
        'company_id', 'credit_facility_id', 'mtl_schedule_line_id', 'mtl_settlement_id',
        'movement_type', 'movement_date',
        'debit_coa_account_id', 'credit_coa_account_id',
        'debit_bank_account_id', 'credit_bank_account_id',
        'amount', 'currency_id', 'description',
        'status', 'posted_at', 'posted_journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'posted_at'     => 'datetime',
        'amount'        => 'decimal:2',
    ];

    /**
     * Whether this movement has both legs and can become a journal
     * entry. A leg counts if it names either a COA account or a bank
     * account — the bank account is resolved to its COA link at
     * posting time.
     */
    public function hasPostableLegs(): bool
    {
        return ($this->debit_coa_account_id  || $this->debit_bank_account_id)
            && ($this->credit_coa_account_id || $this->credit_bank_account_id);
    }

    public function facility(): BelongsTo     { return $this->belongsTo(CreditFacility::class, 'credit_facility_id'); }
    public function scheduleLine(): BelongsTo { return $this->belongsTo(MtlScheduleLine::class, 'mtl_schedule_line_id'); }
    public function settlement(): BelongsTo   { return $this->belongsTo(MtlSettlement::class, 'mtl_settlement_id'); }
    public function currency(): BelongsTo     { return $this->belongsTo(Currency::class); }
    public function debitCoaAccount(): BelongsTo  { return $this->belongsTo(ChartOfAccount::class, 'debit_coa_account_id'); }
    public function creditCoaAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'credit_coa_account_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'posted_journal_entry_id'); }
    public function createdBy(): BelongsTo    { return $this->belongsTo(User::class, 'created_by'); }
}
