<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  InternalSettlementCustomerAllocation
//
//  See migration 000214. The AR side of an Internal Settlement —
//  which Customer Invoices it reduces, and by how much.
//
//  @layer    Model
//  @depends  BelongsToCompany, InternalSettlement, CustomerInvoice, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalSettlementCustomerAllocation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'internal_settlement_id', 'customer_invoice_id',
        'allocated_amount', 'allocated_amount_functional', 'created_by',
    ];

    protected $casts = [
        'allocated_amount'             => 'decimal:2',
        'allocated_amount_functional'  => 'decimal:2',
    ];

    public function internalSettlement(): BelongsTo
    {
        return $this->belongsTo(InternalSettlement::class);
    }

    public function customerInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
