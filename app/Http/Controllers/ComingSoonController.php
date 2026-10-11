<?php

namespace App\Http\Controllers;

// ══════════════════════════════════════════════════════════════════
//  ComingSoonController
//
//  One honest page for every menu item whose screen is not built yet
//  (Audit M43). Before this, those menu items quietly opened the
//  company Dashboard, so a user clicking "Profitability" or "DCF"
//  believed the system had ignored them or was broken.
//
//  It replaces the `function () { return inertia('Dashboard'); }`
//  closures in routes/web.php and the old TreasuryStubController.
//  Closures also cannot be cached by `php artisan route:cache`, so
//  this removes that deploy risk at the same time.
//
//  The page is told which menu item was clicked through the route
//  name (e.g. "analytics.ratios.index" → "analytics_ratios_index"),
//  which the Vue page turns into a translated title. Permissions are
//  still enforced by each route's own `module:` middleware, exactly
//  as before — a user who may not see Valuation still cannot open it.
//
//  When a real screen is built for one of these routes, point that
//  route at the real controller and nothing else needs to change.
//
//  @layer    Controller
//  @depends  —
// ══════════════════════════════════════════════════════════════════

use Illuminate\Http\Request;
use Inertia\Inertia;

class ComingSoonController extends Controller
{
    public function __invoke(Request $request)
    {
        $name = (string) $request->route()?->getName();

        return Inertia::render('ComingSoon', [
            'feature' => str_replace(['.', '-'], '_', $name),
        ]);
    }
}
