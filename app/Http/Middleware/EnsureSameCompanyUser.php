<?php

namespace App\Http\Middleware;

// ══════════════════════════════════════════════════════════════════
//  EnsureSameCompanyUser Middleware
//
//  Audit C2 fix (Sept 2026). Guards every /users/{user}/... route.
//
//  The User model is deliberately NOT company-scoped (the Super Admin
//  has no company_id, and login must find any user), so the {user}
//  loaded from the web address could be anyone on the platform.
//  Before this fix a Company Admin could open, edit, re-password,
//  deactivate or re-permission a user of ANOTHER company — or the
//  Super Admin account itself — and then log in as them.
//
//  Rules (both must pass, otherwise "404 Not Found"):
//    1. The target user belongs to the company this request is
//       working in (app('currentCompanyId') — the admin's own
//       company, or the company the Super Admin has entered).
//    2. The target user is not a Super Admin. A Super Admin account
//       can never be managed from inside a company screen, by anyone.
//
//  404 rather than 403, so the screen never confirms that a user
//  with that number exists somewhere else on the platform.
//
//  Must run AFTER ActiveCompany (needs currentCompanyId) — it is
//  attached to the users route group, which sits inside the
//  auth + active.company group.
//
//  Usage in routes:
//    ->middleware('user.same.company')
//
//  @layer    Middleware
//  @depends  ActiveCompany middleware, App\Models\User
// ══════════════════════════════════════════════════════════════════

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSameCompanyUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $target = $request->route('user');

        // Routes without a {user} (index, create, store) pass through.
        if ($target === null) {
            return $next($request);
        }

        $companyId = app()->bound('currentCompanyId') ? (int) app('currentCompanyId') : 0;

        $allowed = $target instanceof User
            && ! $target->isSuperAdmin()
            && $companyId > 0
            && (int) $target->company_id === $companyId;

        abort_unless($allowed, 404);

        return $next($request);
    }
}
