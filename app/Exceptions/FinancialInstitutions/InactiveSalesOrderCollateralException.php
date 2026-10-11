<?php

namespace App\Exceptions\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  InactiveSalesOrderCollateralException
//
//  Thrown when a Project or Sales Order selected as OD Against
//  Contract/SO collateral cannot be found for this company, or is
//  not in an 'active' status — distinct from ExpiredCollateralException,
//  which covers a collateral that IS active but whose end date has
//  passed. Rule 30.1 requires the collateral to be an active Project
//  or Sales Order before any expiry check even runs.
//
//  This class was named in the Bible's directory layout since v1.5
//  but never actually created. Two call sites needed it:
//    - BankService::validateCollateral() was throwing a generic
//      \DomainException with the right message instead.
//    - ContractAssignmentOdService::addAssignment() was relying on
//      Eloquent's firstOrFail(), which throws
//      ModelNotFoundException — a generic 404-style error with no
//      Vero-specific message — instead of a proper domain exception.
//      Both are now fixed to throw this class with a clear message.
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class InactiveSalesOrderCollateralException extends \DomainException
{
}
