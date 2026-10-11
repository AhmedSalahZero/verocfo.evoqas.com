<?php

namespace App\Http\Middleware;

// ══════════════════════════════════════════════════════════════════
//  ModulePermission Middleware
//
//  Guards routes at module level and optionally feature level.
//
//  Usage in routes:
//    ->middleware('module:accounting,view')
//    ->middleware('module:accounting,create,customer_invoices')
//    ->middleware('module:hr,view,payroll')
//
//  Parameters:
//    $module     → accounting | banking | hr | analytics | planning | valuation
//    $action     → view | create | edit | post | reverse | approve  (post/approve: Audit H3)
//    $feature    → optional specific feature key (see UserModulePermission::FEATURES)
//
//  Gate order (Audit C5, Sept 2026):
//    1. Super admin → always passes (platform owner, may inspect any
//       company whatever its plan)
//    2. Company must exist and be active
//    3. Subscription tier must include this module — now applies to
//       the Company Admin TOO. Before the fix the Company Admin
//       skipped this check, so a Starter client's admin could open
//       Analytics, Planning and Valuation by typing the address.
//    4. Company admin → passes the feature flags (full access inside
//       what the plan includes)
//    5. If feature provided → check canOnFeature()
//       else → check canOnModule() (any feature in module)
//       "view" is implied by create / edit / reverse (User model).
//
//  Every screen-opening (GET) route and every export now carries
//  'module:<module>,view[,<feature>]' — previously only the save /
//  post / delete buttons were checked, so a user with no rights
//  could open and export the ledger, statements and payroll.
//
//  Tier module map includes HR on all tiers.
//
//  @layer    Middleware
//  @depends  App\Models\User (canOnModule, canOnFeature)
// ══════════════════════════════════════════════════════════════════

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ModulePermission
{
    private const TIER_MODULES = [
        'Starter'    => ['accounting', 'banking', 'hr'],
        'Growth'     => ['accounting', 'banking', 'hr', 'analytics'],
        'Pro'        => ['accounting', 'banking', 'hr', 'analytics', 'planning'],
        'Enterprise' => ['accounting', 'banking', 'hr', 'analytics', 'planning', 'valuation'],
    ];

    public function handle(
        Request $request,
        Closure $next,
        string $module,
        string $action = 'view',
        string $feature = ''
    ): Response {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        // ── 1. Super admin bypass ─────────────────────────────────
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // ── 2. Company must be active ─────────────────────────────
        $company = $user->company;

        if (! $company || ! $company->is_active) {
            abort(403, 'Company account is inactive.');
        }

        // ── 3. Subscription tier must include this module ─────────
        // Applies to the Company Admin too (Audit C5).
        $allowed = self::TIER_MODULES[$company->subscription_tier] ?? [];

        if (! in_array($module, $allowed, true)) {
            abort(403, "Your subscription plan does not include the [{$module}] module.");
        }

        // ── 4. Company admin — full access within the plan ────────
        if ($user->isCompanyAdmin()) {
            return $next($request);
        }

        // ── 5. Check permission (regular users) ──────────────────
        if ($feature !== '') {
            // Feature-level check
            if (! $user->canOnFeature($action, $module, $feature)) {
                abort(403, "You do not have [{$action}] permission on [{$module}.{$feature}].");
            }
        } else {
            // Module-level check — any feature with this action
            if (! $user->canOnModule($action, $module)) {
                abort(403, "You do not have [{$action}] access to the [{$module}] module.");
            }
        }

        return $next($request);
    }
}
