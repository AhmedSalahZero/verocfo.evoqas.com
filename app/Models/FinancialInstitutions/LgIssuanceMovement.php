<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LgIssuanceMovement
//
//  One journal LINE for an LG issuance event — cash cover placement,
//  issuance fee, commission accrual (first + recurring), renewal fee,
//  cancel/liquidate cash cover release, decrease memo. See migration
//  000068 header for the original pending-GL-posting design.
//
//  UPDATE (August 2026): these now post for real. status walks
//  through pending_gl_posting → posted (posted_journal_entry_id set,
//  via LgIssuanceService + JournalEntryService::post()) → reversed
//  (Set to Draft, or an Undo action, via JournalEntryService::
//  reverse()). memo_only is for rows that never had a real postable
//  leg on both sides (e.g. Time-Deposit-collateral cash cover, or a
//  decrease_adjustment) — the posting sweep skips these entirely.
//
//  project_id / sales_order_id (added 000144): cost-allocation
//  tagging ONLY — never an accounting posting. Copied from the
//  parent LgIssuance's contract_reference_project_id /
//  purchase_order_so_id at creation time so a future Project P&L /
//  Project Cash Flow report can sum LG costs (Cash Cover, Fees,
//  Commission) per contract without joining back through
//  lg_issuances. Always NULL for Bid Bond (no Project/SO to tag).
//
//  @layer    Model
//  @depends  BelongsToCompany, LgIssuance, ChartOfAccount, BankAccount,
//            Currency, User, JournalEntry, Project, SalesOrder
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\Project;
use App\Models\SalesOrder;
use App\Models\Traits\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class LgIssuanceMovement extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'lg_issuance_movements';

    public const STATUS_PENDING  = 'pending_gl_posting';
    public const STATUS_POSTED   = 'posted';
    public const STATUS_REVERSED = 'reversed';
    public const STATUS_MEMO     = 'memo_only';

    protected $fillable = [
        'company_id',
        'lg_issuance_id',
        'project_id',
        'sales_order_id',
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

    public function lgIssuance(): BelongsTo   { return $this->belongsTo(LgIssuance::class); }
    public function project(): BelongsTo      { return $this->belongsTo(Project::class); }
    public function salesOrder(): BelongsTo   { return $this->belongsTo(SalesOrder::class); }
    public function debitCoaAccount(): BelongsTo  { return $this->belongsTo(ChartOfAccount::class, 'debit_coa_account_id'); }
    public function creditCoaAccount(): BelongsTo { return $this->belongsTo(ChartOfAccount::class, 'credit_coa_account_id'); }
    public function debitBankAccount(): BelongsTo  { return $this->belongsTo(BankAccount::class, 'debit_bank_account_id'); }
    public function creditBankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'credit_bank_account_id'); }
    public function currency(): BelongsTo     { return $this->belongsTo(Currency::class); }
    public function createdBy(): BelongsTo    { return $this->belongsTo(User::class, 'created_by'); }
    public function postedJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'posted_journal_entry_id'); }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    /**
     * A movement is a real GL line only if BOTH legs resolve to an
     * actual account (one debit, one credit) — a row missing either
     * side (a memo, e.g. TD-blocked cash cover or a decrease note)
     * has nothing to post and is skipped by the sweep.
     */
    public function hasPostableLegs(): bool
    {
        $hasDebit  = $this->debit_coa_account_id  || $this->debit_bank_account_id;
        $hasCredit = $this->credit_coa_account_id || $this->credit_bank_account_id;

        return (bool) ($hasDebit && $hasCredit);
    }
}
