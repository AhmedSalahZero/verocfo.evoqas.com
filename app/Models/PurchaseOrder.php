<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  PurchaseOrder
//
//  Mirrors SalesOrder's shape — see that model for the pattern this
//  was built from. Key differences: partner_id is a SUPPLIER;
//  project_id is optional and only offered to "Service with
//  Inventory" companies (Company::hasMixedInvoices() — reused
//  deliberately, see migration 000073 header); not linked to COGS,
//  purely inventory-bound.
//
//  PO code format: PO/YYYY/MM/00001. Its YYYY/MM comes from
//  issuance_date, NOT the row's created_at — see
//  PurchaseOrderService::generatePoCode(). The number itself renumbers
//  automatically if issuance_date is edited while still unlocked (see
//  PurchaseOrderService::isIssuanceDateLocked() for when it locks).
//  billed_amount is auto-updated by the future Supplier Bill module
//  (Accounting Engine Phase 2) — untouched here.
//
//  Carries TWO independent status tracks beyond its own `status`
//  lifecycle — see receipt_status / billing_status accessors below
//  and the migration 000073 header for the full reasoning on why
//  these must stay separate.
//
//  @layer    Model
//  @depends  BelongsToCompany, Partner, Project, Currency, PaymentTerm
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\SerializesDateOnly;

class PurchaseOrder extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $fillable = [
        'company_id', 'po_code', 'issuance_date', 'project_id', 'partner_id', 'subject',
        'description', 'po_value', 'billed_amount', 'currency_id',
        'exchange_rate', 'start_date', 'end_date', 'payment_term_id',
        'status', 'receipt_status', 'billing_status', 'closure_reason',
        'has_price_variance', 'price_variance_amount',
        'internal_notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'po_value'               => 'decimal:2',
        'billed_amount'          => 'decimal:2',
        'exchange_rate'          => 'decimal:6',
        'issuance_date'          => 'date',
        'start_date'             => 'date',
        'end_date'               => 'date',
        'has_price_variance'     => 'boolean',
        'price_variance_amount'  => 'decimal:2',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Supplier — despite the generic relationship name, always a supplier-type Partner for a PO. */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeStandalone(Builder $query): Builder
    {
        return $query->whereNull('project_id');
    }

    // ── Helpers ───────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isStandalone(): bool
    {
        return is_null($this->project_id);
    }

    /** Remaining billable value — future Supplier Bill module reads this the same way Rule 28.2 reads SO's remainingValue(). */
    public function remainingValue(): string
    {
        return bcsub((string) $this->po_value, (string) $this->billed_amount, 2);
    }

    public function isFullyBilled(): bool
    {
        return $this->billing_status === 'fully_billed';
    }

    public function isFullyReceived(): bool
    {
        return $this->receipt_status === 'fully_received';
    }

    /** Rolled up from every line's own has_price_variance by SupplierBillService — see migration 000102/000103. */
    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->po_code . ($this->subject ? ' — ' . $this->subject : '');
    }
}
