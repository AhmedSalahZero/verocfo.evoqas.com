<?php

namespace App\Exceptions\Accounting;

// ══════════════════════════════════════════════════════════════════
//  UnbalancedJournalEntryException
//
//  Thrown by JournalEntryService::post() when sum(debit) !=
//  sum(credit) on the lines supplied — reserved for exactly this
//  purpose since Building Structure Bible v1.5 §1.1. Never caught
//  and "fixed up" automatically — an unbalanced entry means a bug
//  in the calling service, not something to auto-correct.
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class UnbalancedJournalEntryException extends \DomainException {}
