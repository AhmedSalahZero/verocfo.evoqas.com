<?php

namespace App\Exceptions\Users;

// ══════════════════════════════════════════════════════════════════
//  SeatLimitReachedException
//
//  Audit C5 (Sept 2026). Thrown by UserService when creating or
//  re-activating a user would take the company above the number of
//  active users its subscription pays for (Company::maxUsers() =
//  tier seats + additional_users).
//
//  UserController catches it and shows the message as a red flash —
//  same DomainException pattern used across the app.
//
//  @layer    Exception
//  @depends  App\Services\UserService
// ══════════════════════════════════════════════════════════════════

class SeatLimitReachedException extends \DomainException {}
