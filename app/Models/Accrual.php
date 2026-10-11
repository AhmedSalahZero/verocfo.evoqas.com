<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  Accrual
//
//  See migration 000105 for the full design rationale. Written
//  exclusively through AccrualService (create() posts the initial
//  GL entry; settled_amount is advanced only by SupplierBillService
//  when a Bill line settles against it via applySettle()).
//
//  overhead_pool_id (Section 7, Sept 2026 — Applied vs Actual
//  variance closing): OPTIONAL tag marking this as a real
//  manufacturing overhead cost (rent, electricity, maintenance)
//  belonging to a specific Overhead Pool. Purely a read-only input to
//  PayrollRunService's monthly overhead reconciliation query — never
//  changes this accrual's own posting mechanics (still Dr Expense /
//  Cr Accrued Expenses, untouched). Null for every non-manufacturing
//  accrual, which is most of them.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, ChartOfAccount, CostCenter,
//            Project, SalesOrder, JournalEntry, OverheadPool, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\SerializesDateOnly;

class Accrual extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_OPEN               = 'open';
    const STATUS_PARTIALLY_SETTLED  = 'partially_settled';
    const STATUS_FULLY_SETTLED      = 'fully_settled';
    const STATUS_CANCELLED          = 'cancelled';

    protected $fillable = [
        'company_id', 'accrual_code', 'partner_id', 'chart_of_account_id',
        'cost_center_id', 'project_id', 'sales_order_id', 'overhead_pool_id', 'description',
        'accrual_date', 'amount', 'settled_amount', 'status',
        'journal_entry_id', 'internal_notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'accrual_date'   => 'date',
        'amount'         => 'decimal:2',
        'settled_amount' => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    /** Supplier this is owed to. */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function overheadPool(): BelongsTo
    {
        return $this->belongsTo(OverheadPool::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function billLines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_PARTIALLY_SETTLED]);
    }

    public function scopeForPartner(Builder $query, int $partnerId): Builder
    {
        return $query->where('partner_id', $partnerId);
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function remainingAmount(): string
    {
        $remaining = bcsub((string) $this->amount, (string) $this->settled_amount, 2);
        return bccomp($remaining, '0', 2) > 0 ? $remaining : '0';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_PARTIALLY_SETTLED]);
    }

    public function isFullySettled(): bool
    {
        return $this->status === self::STATUS_FULLY_SETTLED;
    }

    public function hasAnySettlement(): bool
    {
        return bccomp((string) $this->settled_amount, '0', 2) > 0;
    }
}
