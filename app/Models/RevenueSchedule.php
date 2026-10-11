<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  RevenueSchedule
//
//  Audit H18 — one service invoice line whose revenue is spread over
//  its service period (e.g. a one-year maintenance contract invoiced
//  up front). See migration 000304. Written only by
//  RevenueScheduleService.
//
//  @layer    Model
//  @depends  BelongsToCompany, CustomerInvoice, CustomerInvoiceLine,
//            ChartOfAccount, CostCenter, RevenueScheduleLine
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\Traits\SerializesDateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevenueSchedule extends Model
{
    use BelongsToCompany, SerializesDateOnly;

    const STATUS_ACTIVE    = 'active';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id', 'customer_invoice_id', 'customer_invoice_line_id', 'revenue_account_id', 'cost_center_id',
        'period_start', 'period_end', 'total_amount', 'released_amount', 'status',
    ];

    protected $casts = [
        'period_start'    => 'date',
        'period_end'      => 'date',
        'total_amount'    => 'decimal:2',
        'released_amount' => 'decimal:2',
    ];

    public function customerInvoice(): BelongsTo     { return $this->belongsTo(CustomerInvoice::class); }
    public function customerInvoiceLine(): BelongsTo { return $this->belongsTo(CustomerInvoiceLine::class); }
    public function revenueAccount(): BelongsTo      { return $this->belongsTo(ChartOfAccount::class, 'revenue_account_id'); }
    public function lines(): HasMany                  { return $this->hasMany(RevenueScheduleLine::class)->orderBy('period_month'); }
}
