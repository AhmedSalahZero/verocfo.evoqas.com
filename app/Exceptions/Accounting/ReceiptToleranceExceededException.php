<?php

namespace App\Exceptions\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ReceiptToleranceExceededException
//
//  Thrown by GoodsReceiptNoteService when posting a GRN line would
//  push a Purchase Order line's cumulative received_quantity beyond
//  its ordered quantity + tolerance_pct (COA doc / migration 000074
//  header — tolerance is entered per-line at PO creation time).
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class ReceiptToleranceExceededException extends \DomainException {}
