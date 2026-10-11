<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  SupplierDebitNoteLine
//
//  See migration 000198. Each line always reverses a specific
//  SupplierBillLine — the service resolves the correct contra account
//  (inventory / expense-asset / 228 Accrued Expenses) from that exact
//  line's own shape (SupplierBillLine::isGoodsLine() /
//  isAccrualSettlement() / isFreshExpense()).
//
//  @layer    Model
//  @depends  BelongsToCompany, SupplierDebitNote, SupplierBillLine
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierDebitNoteLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'supplier_debit_note_id', 'supplier_bill_line_id',
        'description', 'quantity', 'unit_price', 'line_subtotal',
        'vat_rate', 'vat_amount', 'line_total', 'sort_order',
    ];

    protected $casts = [
        'quantity'      => 'decimal:4',
        'unit_price'    => 'decimal:2',
        'line_subtotal' => 'decimal:2',
        'vat_rate'      => 'decimal:2',
        'vat_amount'    => 'decimal:2',
        'line_total'    => 'decimal:2',
    ];

    public function supplierDebitNote(): BelongsTo
    {
        return $this->belongsTo(SupplierDebitNote::class);
    }

    public function supplierBillLine(): BelongsTo
    {
        return $this->belongsTo(SupplierBillLine::class);
    }
}
