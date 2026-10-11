<?php

namespace App\Http\Middleware;

// ══════════════════════════════════════════════════════════════════
//  AdminOnly Middleware
//
//  AUTH-001 companion. A handful of state-changing routes are not
//  module routes at all and cannot be expressed as one:
//
//    users/*        granting and revoking other people's permissions
//    companies/*    creating, editing and deactivating the company
//    impersonate/*  entering another company as a super admin
//
//  Guarding those with 'module:accounting,edit' would be nonsense —
//  they belong to no module, and a user with accounting rights must
//  not inherit the ability to grant themselves more. They are
//  administration, so they are guarded as administration.
//
//  Deliberately NOT a permission check: there is no "manage users"
//  feature key, and inventing one would create a permission the
//  permissions screen cannot grant, which locks everyone out rather
//  than protecting anything. Admin status is the check.
//
//  Usage in routes:
//    ->middleware('admin')
//
//  @layer    Middleware
//  @depends  App\Models\User (isSuperAdmin, isCompanyAdmin)
// ══════════════════════════════════════════════════════════════════

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if ($user->isSuperAdmin() || $user->isCompanyAdmin()) {
            return $next($request);
        }

        abort(403, 'This action is restricted to company administrators.');
    }
}
