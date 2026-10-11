<?php

namespace App\Models\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  MtlScheduleLine
//
//  One row of a Medium Term Loan's manually-entered/uploaded Loan
//  Schedule Table. See migration 000078 header — no auto-calculation
//  lives here, same principle as Leasing's lease_cheques.
//
//  Status is computed, never stored, because it depends on the
//  running sum of this line's mtl_settlements — a value that can
//  change independently of anything on this row itself.
//
//  @layer    Model
//  @depends  BelongsToCompany, CreditFacility, MtlSettlement
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\SerializesDateOnly;

class MtlScheduleLine extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $table = 'mtl_schedule_lines';

    protected $fillable = [
        'company_id', 'credit_facility_id', 'due_date', 'beginning_balance',
        'schedule_payment', 'interest_amount', 'principal_amount', 'end_balance',
        'sort_order', 'created_by',
    ];

    protected $casts = [
        'due_date'          => 'date',
        'beginning_balance' => 'decimal:2',
        'schedule_payment'  => 'decimal:2',
        'interest_amount'   => 'decimal:2',
        'principal_amount'  => 'decimal:2',
        'end_balance'       => 'decimal:2',
    ];

    public function creditFacility(): BelongsTo { return $this->belongsTo(CreditFacility::class); }

    public function settlements(): HasMany
    {
        return $this->hasMany(MtlSettlement::class)->orderBy('settlement_date');
    }

    /** SUM of every settlement recorded against this line so far. */
    public function settledAmount(): string
    {
        return (string) $this->settlements()->sum('settlement_amount');
    }

    /**
     * True the moment ANY settlement — even one partial payment —
     * has been recorded against this line. A locked line's schedule
     * fields (due date, amounts) can never be edited or removed;
     * the only way back to editable is reversing every settlement
     * against it. Confirmed with Mahmoud: partial settlement locks
     * exactly the same as a full one — this is not "locked once
     * fully paid," it's "locked the moment anything real is
     * recorded," since a partially-settled row's numbers are no
     * longer just a plan.
     */
    public function isLocked(): bool
    {
        return bccomp($this->settledAmount(), '0', 2) > 0;
    }

    /** schedule_payment − settledAmount(), floored at 0. */
    public function remainingAmount(): string
    {
        $remaining = bcsub((string) $this->schedule_payment, $this->settledAmount(), 2);
        return bccomp($remaining, '0', 2) < 0 ? '0.00' : $remaining;
    }

    /**
     * Past Due / Partially Paid / Paid / Upcoming — matches the
     * reference image's status badge. Fully derived, never stored:
     * a line paid in 3 partial settlements has no single "paid at"
     * moment on the row itself, only on its settlements.
     */
    public function computedStatus(): string
    {
        $remaining = $this->remainingAmount();

        if (bccomp($remaining, '0', 2) <= 0) {
            return 'paid';
        }

        if (bccomp($this->settledAmount(), '0', 2) > 0) {
            return 'partially_paid';
        }

        return $this->due_date->isPast() ? 'past_due' : 'upcoming';
    }
}
