<?php

namespace App\Console\Concerns;

// ══════════════════════════════════════════════════════════════════
//  RefusesInProduction (Trait)
//
//  AUDIT H8 FIX (Sept 2026). Demo seeders, the testing cycle, the
//  use-case registry builder and the transaction reset tool delete
//  or overwrite data. They are development tools and must never run
//  on the live server where real clients' books live.
//
//  Usage — first line of handle():
//      if ($this->refuseInProduction()) {
//          return self::FAILURE;
//      }
//
//  "Production" = APP_ENV=production in the server's .env file.
//  There is deliberately NO override flag: on the live server these
//  tools simply cannot run.
//
//  @layer    Console (Trait)
//  @depends  —
// ══════════════════════════════════════════════════════════════════

trait RefusesInProduction
{
    protected function refuseInProduction(): bool
    {
        if (! app()->isProduction()) {
            return false;
        }

        $this->error('BLOCKED: this command deletes or overwrites data and cannot run on the production server (APP_ENV=production).');
        $this->line('Run it on a local or staging copy instead.');

        return true;
    }
}
