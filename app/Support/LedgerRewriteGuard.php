<?php

namespace App\Support;

// ══════════════════════════════════════════════════════════════════
//  LedgerRewriteGuard   (audit M-39)
//
//  A data-fix migration that rewrites or back-fills POSTED ledger rows
//  (journal entries / lines) is acceptable only before the system goes
//  live. After go-live, history must be corrected with new journal
//  entries, never by editing old ones.
//
//  Set LEDGER_GO_LIVE=true in the production .env on go-live day. From
//  then on any migration that calls assertAllowed() refuses to run and
//  says why, instead of silently changing the books.
//
//  @layer    Support
//  @depends  config('app.ledger_go_live')
// ══════════════════════════════════════════════════════════════════

class LedgerRewriteGuard
{
    public static function assertAllowed(string $what): void
    {
        if (config('app.ledger_go_live', false)) {
            throw new \RuntimeException(
                "Refused: {$what} would rewrite posted ledger history, and this system is marked LIVE (LEDGER_GO_LIVE=true). "
                .'Correct the books with new journal entries instead. If this is a test copy, set LEDGER_GO_LIVE=false.'
            );
        }
    }
}
