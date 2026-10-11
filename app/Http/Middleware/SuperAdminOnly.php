<?php

namespace App\Http\Middleware;

// ══════════════════════════════════════════════════════════════════
//  SuperAdminOnly Middleware
//
//  Audit C3 fix (Sept 2026). Platform-level screens — the list of
//  client companies, creating, editing, activating/deactivating and
//  deleting a company — belong to the platform owner only.
//
//  Before this fix those routes used 'admin' (AdminOnly), which ALSO
//  lets a Company Admin through, and some had no guard at all. That
//  let any user see every client, and any Company Admin switch off
//  another client's company.
//
//  Different from AdminOnly on purpose:
//    admin        → Super Admin OR Company Admin (company-level admin)
//    super.admin  → Super Admin only            (platform-level admin)
//
//  Usage in routes:
//    ->middleware('super.admin')
//
//  @layer    Middleware
//  @depends  App\Models\User (isSuperAdmin)
// ══════════════════════════════════════════════════════════════════

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! $user->isSuperAdmin()) {
            abort(403, 'This area is restricted to the platform administrator.');
        }

        return $next($request);
    }
}
