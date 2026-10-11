<?php

namespace App\Exceptions\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  ExpiredCollateralException
//
//  Thrown by the OD Against Contract/SO collateral validation
//  (Building Structure Bible Rule 30.1 / Rule 8.3) when the linked
//  Project or Sales Order's end date is on or before the facility's
//  review date — banks do not finance expired or finishing
//  contracts, so this is a hard block, not a soft warning.
//
//  This class was named in the Bible's directory layout since v1.5
//  but never actually created — both BankService::validateCollateral()
//  and ContractAssignmentOdService::addAssignment() were throwing a
//  generic \DomainException with the right message instead. Created
//  and wired in as part of the July 2026 audit follow-up.
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class ExpiredCollateralException extends \DomainException
{
}
