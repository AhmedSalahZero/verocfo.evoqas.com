<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FacilityWithdrawal
//
//  Tracks every withdrawal (drawdown) against an OD facility.
//  Opening balance rows: is_opening_balance = true (set at contract creation).
//  Live withdrawals: created automatically when supplier payments
//  are routed through the OD facility (Layer 6).
//
//  Settlement priority (enforced by CleanOverdraftService):
//    1. Bank charges first (FacilityLiabilityCharge)
//    2. Then withdrawals ordered by due_date ASC (nearest first)
//    3. Partial settlement supported: settled_amount < amount
//
//  v1.1 — added bank_account_id (migration 000081). Non-negotiable,
//    permanent rule (Bible Rule 31): every facility transaction must
//    carry its own bank account, not just infer it via the parent
//    credit_facility's current bank_account_id — needed for accurate
//    interest reconciliation against the bank's own statement and for
//    "statement by bank account" treasury reports to run directly off
//    this table.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope, CreditFacility, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\BankAccount;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class FacilityWithdrawal extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'facility_withdrawals';

    // v1.2 (migration 000218) — what created a LIVE withdrawal, so
    // reversing that source knows which withdrawal to unwind.
    // Opening-balance rows (seedOpeningWithdrawals) leave this
    // 'opening_balance' with a null source_id.
    const SOURCE_OPENING_BALANCE       = 'opening_balance';
    const SOURCE_SUPPLIER_PAYMENT      = 'supplier_payment';
    const SOURCE_INTERNAL_MONEY_TRANSFER = 'internal_money_transfer';
    const SOURCE_OTHER_PAYMENT         = 'other_payment';
    const SOURCE_CASH_EXPENSE_PAYMENT  = 'cash_expense_payment';

    protected $fillable = [
        'company_id',
        'credit_facility_id',
        'bank_account_id',
        'withdrawal_date',
        'due_date',
        'amount',
        'settled_amount',
        'remaining_amount',
        'status',
        'is_opening_balance',
        'source_type',
        'source_id',
        'description',
        'journal_entry_id',
    ];

    protected $casts = [
        'withdrawal_date'    => 'date',
        'due_date'           => 'date',
        'amount'             => 'decimal:2',
        'settled_amount'     => 'decimal:2',
        'remaining_amount'   => 'decimal:2',
        'is_opening_balance' => 'boolean',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function creditFacility(): BelongsTo
    {
        return $this->belongsTo(CreditFacility::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    /** Open or partially settled — eligible for settlement */
    public function scopeSettleable(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'partial'])
                     ->orderBy('due_date', 'asc'); // nearest due date first
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'partial'])
                     ->where('due_date', '<', now()->toDateString());
    }

    public function scopeOpeningBalance(Builder $query): Builder
    {
        return $query->where('is_opening_balance', true);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isFullySettled(): bool
    {
        return $this->status === 'settled';
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['open', 'partial'])
            && $this->due_date < now()->toDateString();
    }

    public function daysUntilDue(): int
    {
        return (int) now()->diffInDays($this->due_date, false);
    }

    /**
     * True once any amount has been settled against this withdrawal.
     * Mirrors MtlScheduleLine::isLocked() (Rule 33.2.1) — a source
     * transaction that created this withdrawal (a Supplier Payment or
     * Internal Transfer) cannot simply be un-created once real
     * settlement history exists against it; the settlements must be
     * reversed first.
     */
    public function isLocked(): bool
    {
        return bccomp((string) $this->settled_amount, '0', 2) > 0;
    }
}
