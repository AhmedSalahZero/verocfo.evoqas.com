<?php

namespace App\Exceptions\Accounting;

// ══════════════════════════════════════════════════════════════════
//  DuplicateSupplierInvoiceException
//
//  Thrown by SupplierBillService when a non-cancelled Supplier Bill
//  already exists for the same partner_id + supplier_invoice_number
//  (migration 000106 header — a soft check, not a hard DB unique,
//  since supplier invoice numbering is outside this system's
//  control and occasionally repeats across periods).
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class DuplicateSupplierInvoiceException extends \DomainException {}
