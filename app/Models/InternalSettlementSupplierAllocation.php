<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  InternalSettlementSupplierAllocation
//
//  See migration 000215. The AP side of an Internal Settlement —
//  which Supplier Bills it reduces, and by how much.
//
//  @layer    Model
//  @depends  BelongsToCompany, InternalSettlement, SupplierBill, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InternalSettlementSupplierAllocation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'internal_settlement_id', 'supplier_bill_id',
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

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
