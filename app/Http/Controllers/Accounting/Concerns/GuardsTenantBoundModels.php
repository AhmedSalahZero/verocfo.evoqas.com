<?php

namespace App\Http\Controllers\Accounting\Concerns;

// ══════════════════════════════════════════════════════════════════
//  GuardsTenantBoundModels
//
//  Route model binding resolves a model from a URL id BEFORE the
//  active-company context is guaranteed to be in place, and
//  CompanyScope deliberately steps aside when 'currentCompanyId' is
//  not yet bound (see that class's own header — it has to, or
//  implicit binding would throw during resolution). ActiveCompany
//  middleware then validates the USER's company, but nothing
//  re-checks that the model which was already resolved belongs to it.
//
//  So a URL like /hr/expense-claims/9999 resolves claim 9999
//  whatever company owns it. Every action that receives a bound model
//  therefore has to say so explicitly, which is what this does.
//
//  This is the same defence-in-depth reasoning behind
//  StockLedgerService's assertLocationBelongsToCompany() family
//  (Building Bible Rule 2.5): the Form Request scopes ids it
//  validates, the service re-checks the ids it is handed, and this
//  covers the third route in — an id that arrived through the URL and
//  was turned into a model before either of them could look at it.
//
//  Kept as a concern rather than a base-class method so it is opt-in
//  and visible at the top of the controllers that use it, rather than
//  silently available everywhere and therefore easy to forget.
//
//  @layer    Controller Concern
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

trait GuardsTenantBoundModels
{
    /**
     * 404 — not 403 — when a model belongs to another company. A
     * company should not be able to discover that a record exists
     * simply by being told it may not see it.
     */
    protected function guardTenant(Request $request, Model ...$models): void
    {
        $companyId = $this->companyId($request);

        foreach ($models as $model) {
            abort_if((int) $model->getAttribute('company_id') !== $companyId, 404);
        }
    }
}
