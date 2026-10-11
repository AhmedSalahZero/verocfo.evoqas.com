<?php

namespace App\Exceptions\Accounting;

// ══════════════════════════════════════════════════════════════════
//  AccrualExceededException
//
//  Thrown by SupplierBillService when a Bill line's accrual-
//  settlement amount would exceed the Accrual's remainingAmount()
//  (migration 000105 / Accrual::remainingAmount()).
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class AccrualExceededException extends \DomainException {}
