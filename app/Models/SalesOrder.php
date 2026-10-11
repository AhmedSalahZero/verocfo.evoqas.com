<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  SalesOrder
//
//  Mandatory gateway to all customer invoicing in Vero CFO.
//  Every invoice must link to a Sales Order (Rule 28.1).
//  SO can be standalone (project_id = null) or under a Project.
//
//  SO code format: SO/YYYY/MM/00001. Its YYYY/MM comes from
//  issuance_date, NOT the row's created_at — see
//  SalesOrderService::generateSoCode(). The number itself renumbers
//  automatically if issuance_date is edited while still unlocked (see
//  SalesOrderService::isIssuanceDateLocked() for when it locks).
//  invoiced_amount is auto-updated by the accounting engine when
//  invoices are posted or reversed.
//
//  @layer    Model
//  @depends  BelongsToCompany, CompanyScope, Project, Partner, Currency
// ══════════════════════════════════════════════════════════════════

use App\Models\Scopes\CompanyScope;
use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\SerializesDateOnly;

class SalesOrder extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    protected $fillable = [
        'company_id', 'so_code', 'issuance_date', 'project_id', 'partner_id', 'subject',
        'description', 'so_value', 'invoiced_amount', 'currency_id',
        'exchange_rate', 'start_date', 'end_date', 'payment_term_id',
        'status', 'internal_notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'so_value'        => 'decimal:2',
        'invoiced_amount' => 'decimal:2',
        'exchange_rate'   => 'decimal:6',
        'issuance_date'   => 'date',
        'start_date'      => 'date',
        'end_date'        => 'date',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

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
        return $this->hasMany(SalesOrderLine::class)->orderBy('sort_order');
    }

    /** Added alongside Customer Invoice (Sales Cycle, July 2026) — every invoice against this SO. */
    public function customerInvoices(): HasMany
    {
        return $this->hasMany(CustomerInvoice::class);
    }

    /** Added alongside Delivery & Revenue Recognition (July 2026) — every delivery against this SO. */
    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class);
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

    /** Eligible as collateral for OD Against Contract/SO */
    public function scopeEligibleCollateral(Builder $query, string $afterDate): Builder
    {
        return $query->where('status', 'active')
                     ->where('end_date', '>', $afterDate);
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

    /** Remaining invoiceable value — used by Rule 28.2 */
    public function remainingValue(): string
    {
        return bcsub((string) $this->so_value, (string) $this->invoiced_amount, 2);
    }

    /**
     * Audit M-11 — an invoice (or credit note) amount expressed in THIS
     * Sales Order's own currency, the unit so_value and invoiced_amount
     * are both kept in.
     *
     * Same currency → the document's own amount, no rate involved at all
     * (so a USD order invoiced in USD is never disturbed by the rate
     * moving between the order date and the invoice date). Different
     * currency → the functional figure converted at the order's agreed
     * rate, rounded half-up.
     */
    public function inOrderCurrency(string $documentAmount, string $functionalAmount, ?int $documentCurrencyId): string
    {
        if ((int) $documentCurrencyId === (int) $this->currency_id) {
            return bcadd($documentAmount, '0', 2);
        }

        $rate = (string) $this->exchange_rate;
        if (bccomp($rate, '0', 8) <= 0) {
            $rate = '1';
        }

        return bcadd(bcdiv($functionalAmount, $rate, 6), '0.005', 2);
    }

    public function isFullyInvoiced(): bool
    {
        return bccomp((string) $this->invoiced_amount, (string) $this->so_value, 2) >= 0;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->so_code . ($this->subject ? ' — ' . $this->subject : '');
    }
}
