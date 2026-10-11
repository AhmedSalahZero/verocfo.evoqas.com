<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FacilityLiabilityCharge
//
//  Non-cash charges that increase the OD liability without cash movement:
//    - interest:             Dr 555 OD Interest / Cr 223001 OD Liability
//    - highest_debit_balance Dr 552 Bank Charges  / Cr 223001 OD Liability
//    - admin_fee:            Dr 552 Bank Charges  / Cr 223001 OD Liability
//
//  System calculates theoretical_amount using 360-day convention.
//  User verifies against bank statement and may override actual_amount.
//  Difference flagged when abs(actual - theoretical) > 1% of theoretical.
//
//  Settlement priority: charges are settled BEFORE withdrawals
//  when any inflow hits the OD account (bank settles their fees first).
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

class FacilityLiabilityCharge extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'facility_liability_charges';

    protected $fillable = [
        'company_id',
        'credit_facility_id',
        'bank_account_id',
        'charge_type',
        'period_label',
        'charge_date',
        'theoretical_amount',
        'actual_amount',
        'settled_amount',
        'remaining_amount',
        'difference_amount',
        'user_overridden',
        'override_reason',
        'status',
        'journal_entry_id',
        'posted_by',
        'posted_at',
    ];

    protected $casts = [
        'charge_date'        => 'date',
        'theoretical_amount' => 'decimal:2',
        'actual_amount'      => 'decimal:2',
        'settled_amount'     => 'decimal:2',
        'remaining_amount'   => 'decimal:2',
        'difference_amount'  => 'decimal:2',
        'user_overridden'    => 'boolean',
        'posted_at'          => 'datetime',
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

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', 'posted');
    }

    public function scopeInterest(Builder $query): Builder
    {
        return $query->where('charge_type', 'interest');
    }

    public function scopeHdb(Builder $query): Builder
    {
        return $query->where('charge_type', 'highest_debit_balance');
    }

    /**
     * Posted, but not yet fully settled — eligible for the Facility
     * Settlement Engine to apply an incoming credit against, oldest
     * charge_date first. Mirrors FacilityWithdrawal::scopeSettleable().
     */
    public function scopeSettleable(Builder $query): Builder
    {
        return $query->where('status', 'posted')
                     ->where('remaining_amount', '>', 0)
                     ->orderBy('charge_date', 'asc');
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Audit M23/M24 — interest the user has ALREADY posted from the
     * bank statement for one calendar month ('YYYY-MM'), in the
     * facility's own currency.
     *
     * Which month a charge belongs to: the month typed in its period
     * label ("2026-03") when it looks like one, otherwise the month of
     * its charge date. Reads across the company filter on purpose
     * (jobs have no logged-in user) — the facility id is the filter.
     */
    public static function postedInterestForMonth(int $facilityId, string $yearMonth): string
    {
        $total = '0.00';

        $charges = static::withoutGlobalScopes()
            ->where('credit_facility_id', $facilityId)
            ->where('charge_type', 'interest')
            ->where('status', 'posted')
            ->get(['period_label', 'charge_date', 'actual_amount']);

        foreach ($charges as $charge) {
            $label = trim((string) $charge->period_label);
            $month = preg_match('/^\d{4}-\d{2}/', $label)
                ? substr($label, 0, 7)
                : substr($charge->charge_date->format('Y-m-d'), 0, 7);

            if ($month === $yearMonth) {
                $total = bcadd($total, (string) $charge->actual_amount, 2);
            }
        }

        return $total;
    }

    public function isFullySettled(): bool
    {
        return bccomp((string) $this->remaining_amount, '0', 2) <= 0;
    }

    public function hasSignificantDifference(): bool
    {
        if (bccomp((string) $this->theoretical_amount, '0', 2) === 0) {
            return false;
        }
        $threshold = bcmul((string) $this->theoretical_amount, '0.01', 6); // 1%
        return bccomp(
            (string) abs($this->actual_amount - $this->theoretical_amount),
            $threshold,
            2
        ) > 0;
    }

    public function chargeTypeLabel(): string
    {
        return match($this->charge_type) {
            'interest'              => 'Monthly Interest',
            'highest_debit_balance' => 'Highest Debit Balance Fee',
            'admin_fee'             => 'Admin Fee',
            default                 => $this->charge_type,
        };
    }
}
