<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  SupplierPaymentAllocation
//
//  See migration 000138. The only place a SupplierPayment and a
//  SupplierBill are actually linked — genuinely many-to-many.
//  Mirrors CustomerPaymentAllocation exactly, including carrying its
//  own journal_entry_id from the start (a later "Apply" allocation is
//  a real Dr AP / Cr 144 Advances to Suppliers movement, not a
//  re-labeling — same corrected understanding already proven on the
//  customer side, built in here rather than needing a follow-up fix).
//
//  @layer    Model
//  @depends  BelongsToCompany, SupplierPayment, SupplierBill, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPaymentAllocation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'supplier_payment_id', 'supplier_bill_id',
        'allocated_amount', 'fx_gain_loss_functional', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'allocated_amount'         => 'decimal:2',
        'fx_gain_loss_functional'  => 'decimal:2',
    ];

    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
