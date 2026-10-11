<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CustomerDebitNoteLine
//
//  See migration 000195. Each line always adds to a specific
//  CustomerInvoiceLine's value — the extra revenue lands on the same
//  account the original line used.
//
//  @layer    Model
//  @depends  BelongsToCompany, CustomerDebitNote, CustomerInvoiceLine
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDebitNoteLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'customer_debit_note_id', 'customer_invoice_line_id',
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

    public function customerDebitNote(): BelongsTo
    {
        return $this->belongsTo(CustomerDebitNote::class);
    }

    public function customerInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoiceLine::class);
    }
}
