<?php

namespace App\Exceptions\Accounting;

// ══════════════════════════════════════════════════════════════════
//  BillExceedsReceivedQuantityException
//
//  Thrown by SupplierBillService when a Goods Bill line's quantity
//  would exceed its matched Goods Receipt Note line's
//  remainingBillableQuantity() (migration 000104). Unlike GRN-vs-PO
//  receiving, there is no tolerance band here — see migration 000104
//  header for why.
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class BillExceedsReceivedQuantityException extends \DomainException {}
