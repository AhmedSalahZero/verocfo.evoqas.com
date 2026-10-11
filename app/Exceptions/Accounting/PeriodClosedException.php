<?php

namespace App\Exceptions\Accounting;

// ══════════════════════════════════════════════════════════════════
//  PeriodClosedException
//
//  Thrown by JournalEntryService::post() when the entry date falls
//  inside a closed AccountingPeriod, or inside no period at all for
//  this company (fails safe — an unrecognized date is never treated
//  as "obviously fine to post").
//
//  Closes the gap flagged in the August 2026 audit: AccountingPeriod
//  has always documented "closed → no new postings, no edits, no
//  reversals," but the single gatekeeper for all GL writes never
//  actually enforced it. Every calling service (Customer Invoice,
//  Supplier Bill, GRN, Payments, etc.) gets this protection for free
//  the moment it calls post() — nothing else needed to change.
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class PeriodClosedException extends \DomainException {}
