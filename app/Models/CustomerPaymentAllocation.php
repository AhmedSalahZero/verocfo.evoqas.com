<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  CustomerPaymentAllocation
//
//  See migration 000123. The only place a CustomerPayment and a
//  CustomerInvoice are actually linked — genuinely many-to-many.
//
//  Corrected in Customer Collection v1.1 (see
//  VeroCFO_Customer_Collection_Design_v1_1.md Section 1): DOES now
//  carry its own journal_entry_id (migration 000127). An unapplied
//  receipt sits in 235 Advances from Customers, not AR — so
//  allocating it to a real invoice is a genuine account-to-account
//  movement (Dr 235 / Cr AR), not a re-labeling of cash that was
//  already sitting in AR. unallocate() reverses this same entry.
//
//  @layer    Model
//  @depends  BelongsToCompany, CustomerPayment, CustomerInvoice, JournalEntry, User
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPaymentAllocation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'customer_payment_id', 'customer_invoice_id',
        'allocated_amount', 'wht_amount', 'wht_amount_functional', 'fx_gain_loss_functional',
        'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'allocated_amount'         => 'decimal:2',
        'wht_amount'                => 'decimal:2',
        'wht_amount_functional'     => 'decimal:2',
        'fx_gain_loss_functional'   => 'decimal:2',
    ];

    public function customerPayment(): BelongsTo
    {
        return $this->belongsTo(CustomerPayment::class);
    }

    public function customerInvoice(): BelongsTo
    {
        return $this->belongsTo(CustomerInvoice::class);
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
