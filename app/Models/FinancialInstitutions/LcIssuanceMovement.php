<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LcIssuanceMovement
//
//  One real journal LINE for an LC issuance-time or mark-paid event.
//  See migration 000146 header. Unlike LgIssuanceMovement (which
//  started staged-only and was migrated to real posting later), this
//  posts for real from day one — JournalEntryService already exists.
//
//  purchase_order_id (added here, not on a separate table like LG's
//  project_id/sales_order_id split): cost-allocation tag ONLY — never
//  an accounting posting. Copied from the parent issuance's own
//  purchase_order_id at creation time, for a future Project P&L /
//  Purchase Analytics report to sum LC costs per PO without joining
//  back through letter_of_credit_issuances.
//
//  @layer    Model
//  @depends  BelongsToCompany, LetterOfCreditIssuance, ChartOfAccount,
//            BankAccount, Currency, User, JournalEntry, PurchaseOrder
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LcIssuanceMovement extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'lc_issuance_movements';

    public const STATUS_POSTED   = 'posted';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_MEMO     = 'memo_only';

    protected $fillable = [
        'company_id',
        'lc_issuance_id',
        'purchase_order_id',
        'movement_type',
        'movement_date',
        'debit_coa_account_id',
        'credit_coa_account_id',
        'debit_bank_account_id',
        'credit_bank_account_id',
        'amount',
        'currency_id',
        'description',
        'status',
        'posted_at',
        'posted_journal_entry_id',
        'created_by',
    ];

    protected $casts = [
        'movement_date' => 'date',
        'amount'        => 'decimal:2',
        'posted_at'     => 'datetime',
    ];

    public function lcIssuance(): BelongsTo     { return $this->belongsTo(LetterOfCreditIssuance::class, 'lc_issuance_id'); }
    public function purchaseOrder(): BelongsTo  { return $this->belongsTo(PurchaseOrder::class); }
    public function debitCoaAccount(): BelongsTo   { return $this->belongsTo(ChartOfAccount::class, 'debit_coa_account_id'); }
    public function creditCoaAccount(): BelongsTo  { return $this->belongsTo(ChartOfAccount::class, 'credit_coa_account_id'); }
    public function debitBankAccount(): BelongsTo  { return $this->belongsTo(BankAccount::class, 'debit_bank_account_id'); }
    public function creditBankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'credit_bank_account_id'); }
    public function currency(): BelongsTo       { return $this->belongsTo(Currency::class); }
    public function createdBy(): BelongsTo      { return $this->belongsTo(User::class, 'created_by'); }
    public function postedJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'posted_journal_entry_id'); }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function hasPostableLegs(): bool
    {
        $hasDebit  = $this->debit_coa_account_id  || $this->debit_bank_account_id;
        $hasCredit = $this->credit_coa_account_id || $this->credit_bank_account_id;

        return (bool) ($hasDebit && $hasCredit);
    }
}
