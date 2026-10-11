<?php

namespace App\Exceptions\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  TimedepositNotMaturedYetException
//
//  Reserved for the documented Time Deposit rule (Application Scope
//  §6.2 / COA doc "Time Deposit rule"): on maturity, the user gets an
//  alert to either renew the TD or transfer it back to the current
//  account. This exception is the guard on the OTHER end of that —
//  it's what should stop someone from renewing or transferring a TD
//  early, before td_maturity_date has actually arrived.
//
//  NOT WIRED — flagged here rather than silently assumed complete.
//  As of this engagement, there is no BankAccountController and no
//  BankAccountService transaction layer at all — nothing anywhere
//  lets a user renew or transfer a Time Deposit yet, so there is no
//  real place to throw this from today. Created now so the class
//  exists and is ready the moment that feature is built — at which
//  point it should be thrown exactly where a renew/transfer action
//  is attempted against an account whose td_maturity_date is still
//  in the future.
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class TimedepositNotMaturedYetException extends \DomainException
{
}
