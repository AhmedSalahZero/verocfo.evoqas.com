<?php

namespace App\Models;

// ══════════════════════════════════════════════════════════════════
//  DocumentReopenRequest
//
//  A request to "Set to Draft" a POSTED tax document — customer
//  invoice or supplier bill (Audit H5, Sept 2026 — confirmed with
//  Mahmoud: allowed only with a written reason, approval by a
//  DIFFERENT user with the Approve right, and only while the month is
//  open). DocumentReopenService creates it; approving it performs the
//  reopen, rejecting it leaves the document untouched.
//
//  document_type: customer_invoice | supplier_bill
//  status:        pending → approved | rejected
//
//  @layer    Model
//  @depends  BelongsToCompany, User, CustomerInvoice, SupplierBill
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentReopenRequest extends Model
{
    use BelongsToCompany;

    const TYPE_CUSTOMER_INVOICE = 'customer_invoice';
    const TYPE_SUPPLIER_BILL    = 'supplier_bill';

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'company_id', 'document_type', 'document_id', 'document_code', 'reason',
        'status', 'requested_by', 'decided_by', 'decided_at', 'rejection_reason',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function requestedBy(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function decidedBy(): BelongsTo   { return $this->belongsTo(User::class, 'decided_by'); }

    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
}
