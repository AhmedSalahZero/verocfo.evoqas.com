<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CreditNoteLine
//
//  See migration 000121. Each line always credits back a specific
//  CustomerInvoiceLine — revenue reversal lands on the same account
//  it was originally recognized on.
//
//  @layer    Model
//  @depends  BelongsToCompany, CreditNote, CustomerInvoiceLine
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditNoteLine extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'credit_note_id', 'customer_invoice_line_id',
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

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function customerInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoiceLine::class);
    }
}
