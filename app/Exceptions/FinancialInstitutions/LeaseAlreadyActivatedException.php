<?php

namespace App\Exceptions\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LeaseAlreadyActivatedException
//
//  Thrown when trying to re-activate, or change the terms of, a
//  leasing contract that's already active. Once a lease is live, its
//  numbers are locked — the calculation already ran and posted, and
//  changing the terms afterward would leave the books out of sync
//  with the schedule.
//
//  This class was already named in the Building Structure Bible's
//  directory layout since v1.5 but never actually created — see the
//  July 2026 audit notes. Created now as part of the Lease
//  Amortization Engine build.
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class LeaseAlreadyActivatedException extends \DomainException
{
}
