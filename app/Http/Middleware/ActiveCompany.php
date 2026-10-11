<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// ══════════════════════════════════════════════════════════════════
//  ActiveCompany Middleware
//
//  Resolves which company is active for this request.
//  Handles both regular users (company_id on user record)
//  and super admin impersonation (company_id in session).
//
//  Checks (in order):
//    1. Resolve the active company (user's company or impersonated)
//    2. Company must exist
//    3. Company must be is_active = true
//    4. User must be is_active = true
//    5. Subscription expiry → READ-ONLY mode (Audit C5, Sept 2026)
//       Once subscription_expires_at has passed, the company can
//       still log in, open and export everything (their data is
//       theirs), but nothing can be saved, posted or deleted.
//       Exceptions: the user's own profile / password (so nobody is
//       locked out of their account) and the Super Admin (so you can
//       always fix or renew a client). The expiry DATE ITSELF is still
//       a working day — read-only starts the day after.
//
//  Subscription and module access are checked per-module by the
//  ModulePermission middleware applied to each module route group.
//  A company with some restricted modules can still log in and
//  use their permitted modules — locking them out entirely is wrong.
// ══════════════════════════════════════════════════════════════════

class ActiveCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // ── Resolve the active company ────────────────────────────
        // Super admin uses impersonation session; regular users use
        // the company_id on their user record.
        if ($user->isSuperAdmin()) {
            $companyId = session('impersonate_company_id');

            if (! $companyId) {
                // Super admin without an active impersonation session
                // has no company context — redirect to companies index.
                return redirect()->route('companies.index');
            }

            $company = Company::find($companyId);

            // The impersonated company is gone, or has been switched
            // off, since the session key was set. Deleting a company
            // does not reach into anyone's session, so a super admin
            // who was working inside it is left holding a dead id —
            // and used to get a bare 403 with no navigation on it, in
            // the middle of whatever they were doing, with no clue
            // what had happened or how to get out. (The demo seeders
            // make this routine: demo:seed-testing-cycle deletes and
            // rebuilds its three companies on every run, so their ids
            // change each time.)
            //
            // Recovered the same way the no-session case above is:
            // clear the stale key and send them back to pick a
            // company. A dead end is never the right answer to a
            // condition the user can fix in one click.
            if (! $company || ! $company->is_active) {
                $reason = $company
                    ? 'That company has been deactivated. Choose another to continue.'
                    : 'The company you were working in no longer exists. Choose another to continue.';

                session()->forget('impersonate_company_id');

                return redirect()->route('companies.index')->with('warning', $reason);
            }
        } else {
            $company = $user->company;

            // ── Company must exist ────────────────────────────────
            // A regular user genuinely has nowhere to be redirected
            // to: their account is misconfigured, or their company
            // was removed out from under them. This one IS a dead
            // end, and correctly so.
            if (! $company) {
                abort(403, 'No company associated with this account.');
            }

            // ── Company must be active ────────────────────────────
            if (! $company->is_active) {
                abort(403, 'This company account has been deactivated. Please contact support.');
            }
        }

        // ── User must be active ───────────────────────────────────
        if (! $user->is_active) {
            abort(403, 'Your user account has been deactivated. Please contact your administrator.');
        }

        // ── Bind the resolved company ID to the container ─────────
        // This is the ONLY authorised source of company ID in Vero CFO.
        // Controllers use $this->companyId($request) which reads this binding.
        // Services accept explicit int $companyId — never resolve auth() internally.
        app()->instance('currentCompanyId', $company->id);

        // ── Expired subscription → read-only ──────────────────────
        if (! $user->isSuperAdmin()
            && $company->isSubscriptionExpired()
            && ! $request->isMethodSafe()
            && ! $request->routeIs('profile.*')) {

            $message = 'Your subscription expired on '
                . $company->subscription_expires_at->format('d M Y')
                . '. The system is in read-only mode — please contact Vero CFO to renew.';

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $message], 403);
            }

            return back()->with('error', $message);
        }

        return $next($request);
    }
}
