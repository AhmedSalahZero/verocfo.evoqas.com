<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LeaseCheque
//
//  Manually-entered real cheque schedule the company handed to the
//  leasing company. Applies to every contract type — Treatment A,
//  Treatment B, and Capital alike.
//
//  Separate from LeaseAmortizationLine, which is auto-calculated by
//  the system (Rule 29.2, Treatment B / Capital only) and drives the
//  ROU / lease-liability journal entries. This table does not feed
//  into or replace that calculation — it is purely a record of
//  physical reality (real cheques, real principal/interest split as
//  documented by the leasing company), entered or imported by the user.
//
//  status / withdrawn_date are reserved for a future Accounting Engine
//  feature (marking a cheque withdrawn from the bank on a chosen date,
//  for bank statement reconciliation). No logic uses them yet.
//
//  v1.1 — added bank_account_id (migration 000081). Non-negotiable,
//    permanent rule (Bible Rule 31): every facility transaction must
//    carry its own bank account, not just infer it via the parent
//    leasing_contract's current bank_account_id — a lease can run for
//    years and the servicing bank account can genuinely change
//    partway through, so this must be captured per cheque, not only
//    once on the contract.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope, LeasingContract, BankAccount
// ══════════════════════════════════════════════════════════════════

use App\Models\FinancialInstitutions\BankAccount;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\SerializesDateOnly;

class LeaseCheque extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'lease_cheques';

    const STATUS_PENDING   = 'pending';
    const STATUS_WITHDRAWN = 'withdrawn';
    const STATUS_BOUNCED   = 'bounced';

    protected $fillable = [
        'company_id',
        'leasing_contract_id',
        'lease_amortization_line_id',
        'bank_account_id',
        'payment_date',
        'beginning_balance',
        'cheque_number',
        'cheque_amount',
        'principal_amount',
        'interest_amount',
        'end_balance',
        'status',
        'withdrawn_date',
        'created_by',
    ];

    protected $casts = [
        'payment_date'      => 'date',
        'withdrawn_date'    => 'date',
        'beginning_balance' => 'decimal:2',
        'cheque_amount'     => 'decimal:2',
        'principal_amount'  => 'decimal:2',
        'interest_amount'   => 'decimal:2',
        'end_balance'       => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function leasingContract(): BelongsTo
    {
        return $this->belongsTo(LeasingContract::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeOrderedByDate(Builder $query): Builder
    {
        return $query->orderBy('payment_date');
    }
}
