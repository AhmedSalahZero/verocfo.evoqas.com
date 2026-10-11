<?php

namespace App\Exceptions\Accounting;

// ══════════════════════════════════════════════════════════════════
//  NonPostableAccountException
//
//  Thrown by JournalEntryService::post() when a line references a
//  chart_of_accounts row that is not Level 4/5 / is_postable = false
//  (a summary account), or belongs to a different company than the
//  one posting the entry.
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class NonPostableAccountException extends \DomainException {}
