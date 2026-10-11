<?php

namespace App\Http\Controllers;

// ══════════════════════════════════════════════════════════════════
//  Controller (Base)
//
//  Base controller for all Vero CFO HTTP controllers.
//
//  Provides companyId() — the ONLY authorised way to resolve the
//  active company ID in any controller in the application.
//
//  companyId() reads app('currentCompanyId') which is bound by
//  ActiveCompany middleware on every authenticated request.
//  This works identically for:
//    - Regular users (company_id on their user record)
//    - Super Admin impersonating a company (session key)
//
//  Never read $request->user()->company_id directly in any
//  controller — always use $this->companyId($request).
//
//  @layer    Controller (Base)
//  @depends  ActiveCompany middleware (binds currentCompanyId)
// ══════════════════════════════════════════════════════════════════

use Illuminate\Http\Request;

abstract class Controller
{
    // ── Resolve active company ID ─────────────────────────────────
    // Single authorized source of company ID for all controllers.
    // Throws if called outside of active.company middleware context.

    protected function companyId(Request $request): int
    {
        $id = app('currentCompanyId');

        abort_if(! $id, 403, 'No active company context.');

        return (int) $id;
    }
}
