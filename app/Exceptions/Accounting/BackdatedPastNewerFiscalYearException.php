<?php

namespace App\Exceptions\Accounting;

// ══════════════════════════════════════════════════════════════════
//  BackdatedPastNewerFiscalYearException
//
//  Thrown when a Customer Invoice or Goods Receipt Note is posted
//  with a document date that falls inside a fiscal year OLDER than
//  one that already exists for the company — Building Structure
//  Bible Rules 26.4 (revenue invoices) and 26.5 (inventory receipts).
//
//  Deliberately narrow: supplier bills, payments, and manual journal
//  entries are explicitly ALLOWED to back-date into open prior
//  periods (Rule 26 summary) — only these two document types are
//  hard-blocked once a newer fiscal year exists. Backed by
//  FiscalYearService::newerFiscalYearExists().
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class BackdatedPastNewerFiscalYearException extends \DomainException {}
