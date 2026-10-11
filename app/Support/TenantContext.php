<?php

namespace App\Support;

// ══════════════════════════════════════════════════════════════════
//  TenantContext
//
//  Answers one question: "which company is this request working in?"
//  — and can answer it EARLY, before the ActiveCompany middleware
//  has run.
//
//  Why it exists (Audit C1, Sept 2026):
//  Laravel loads the record named in the web address (route model
//  binding, e.g. /customer-invoices/1234/edit) BEFORE ActiveCompany
//  binds app('currentCompanyId'). At that moment CompanyScope has no
//  company to filter by and is skipped, so invoice 1234 was loaded
//  whichever company owned it. BelongsToCompany now asks this class
//  for the company at binding time and filters the lookup itself.
//
//  Resolution order — mirrors ActiveCompany exactly:
//    1. app('currentCompanyId') if the middleware has already bound it
//    2. Super Admin → the impersonated company in the session
//       (so the Super Admin keeps full access to whichever company
//       they have entered)
//    3. Everyone else → the company on their own user record
//  Returns null when no company can be resolved (guest, or a Super
//  Admin who has not entered any company). Callers treat null as
//  "no access" — fail closed, never "no filter".
//
//  Note: this does NOT replace Rule 6. Controllers still read the
//  company only via $this->companyId($request). This class is used
//  by the model layer during route binding only.
//
//  runAs() (Audit H9, Sept 2026): background jobs and console commands
//  have no logged-in user, so nothing bound a company and CompanyScope
//  filtered nothing — any service relying on it mixed companies. A job
//  now wraps each company's work in TenantContext::runAs($companyId,
//  fn () => ...), which binds that company for exactly that call and
//  restores whatever was bound before (even if the call throws).
//
//  @layer    Support
//  @depends  App\Models\User (isSuperAdmin), ActiveCompany middleware
// ══════════════════════════════════════════════════════════════════

class TenantContext
{
    /**
     * Runs $callback with $companyId bound as the current company, then
     * restores the previous binding. Used by jobs and commands.
     */
    public static function runAs(int $companyId, callable $callback): mixed
    {
        $hadPrevious = app()->bound('currentCompanyId');
        $previous    = $hadPrevious ? app('currentCompanyId') : null;

        app()->instance('currentCompanyId', $companyId);

        try {
            return $callback();
        } finally {
            if ($hadPrevious) {
                app()->instance('currentCompanyId', $previous);
            } else {
                app()->forgetInstance('currentCompanyId');
                unset(app()['currentCompanyId']);
            }
        }
    }

    public static function companyId(): ?int
    {
        if (app()->bound('currentCompanyId') && app('currentCompanyId')) {
            return (int) app('currentCompanyId');
        }

        $user = auth()->user();

        if (! $user) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            $id = session('impersonate_company_id');

            return $id ? (int) $id : null;
        }

        return $user->company_id ? (int) $user->company_id : null;
    }
}
