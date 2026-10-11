<?php

namespace App\Exceptions\Accounting;

// ══════════════════════════════════════════════════════════════════
//  SOValueExceededException
//
//  Thrown by CustomerInvoiceService::post() when a Customer Invoice's
//  value, added to every other posted invoice already against the
//  same Sales Order, would exceed the SO's own value — Building
//  Structure Bible Rule 28.2: "The sum of all posted invoices against
//  an SO cannot exceed the SO value." Hard block, not a warning —
//  partial/milestone invoicing is fine, invoicing past 100% of the
//  SO is not.
//
//  This class was named in the Bible's directory layout (and Rule
//  8.3's own worked example) since v1.5 but never actually created —
//  same pattern found and fixed for the FinancialInstitutions/
//  Projects exceptions earlier in this engagement. Created now as
//  part of building Customer Invoice, the first real caller.
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class SOValueExceededException extends \DomainException
{
}
