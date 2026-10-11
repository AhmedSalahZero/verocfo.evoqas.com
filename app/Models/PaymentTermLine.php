<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PaymentTermLine
//
//  One installment line within a PaymentTerm.
//  Each line defines: what percentage is due, how many days
//  after which reference point, and an optional label.
//
//  reference_point valid values:
//    invoice_date   → most common — N days after invoice is issued
//    so_date        → N days after Sales Order date (down payments)
//    po_date        → N days after Purchase Order date
//    contract_date  → N days after contract signing
//
//  percentage is stored as decimal(5,2) — 50.00 means 50%.
//  All lines on one PaymentTerm must sum to 100.00.
//  This is validated in PaymentTermService, not at DB level.
//
//  AUDIT M1 FIX (Sept 2026): this table has no company_id of its
//  own — a line belongs to whichever company owns its parent term.
//  A global scope now only returns lines whose parent PaymentTerm is
//  visible to the current company (the parent carries CompanyScope),
//  so a direct PaymentTermLine query can never read another
//  company's installment schedule.
//
//  @layer    Model
//  @depends  PaymentTerm
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentTermLine extends Model
{
    protected $fillable = [
        'payment_term_id',
        'percentage',
        'due_days',
        'reference_point',
        'label',
        'sort_order',
    ];

    protected $casts = [
        'percentage' => 'decimal:2',
        'due_days'   => 'integer',
        'sort_order' => 'integer',
    ];

    // ── Tenant filter (via parent term) ───────────────────────────

    protected static function booted(): void
    {
        static::addGlobalScope('company_via_term', function (Builder $query) {
            // whereHas applies PaymentTerm's CompanyScope automatically.
            $query->whereHas('paymentTerm');
        });
    }

    // ── Reference point options ───────────────────────────────────

    public const REFERENCE_POINTS = [
        'invoice_date'  => 'Invoice Date',
        'so_date'       => 'Sales Order Date',
        'po_date'       => 'Purchase Order Date',
        'contract_date' => 'Contract Date',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Human-readable description of this line.
     * e.g. "50% — 30 days after Invoice Date"
     */
    public function getDescriptionAttribute(): string
    {
        $refLabel = self::REFERENCE_POINTS[$this->reference_point] ?? $this->reference_point;
        $days     = $this->due_days === 0 ? 'immediately' : "{$this->due_days} days after {$refLabel}";

        return "{$this->percentage}% — {$days}";
    }
}
