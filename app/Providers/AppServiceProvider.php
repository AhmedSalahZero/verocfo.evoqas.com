<?php

namespace App\Providers;

// ══════════════════════════════════════════════════════════════════
//  AppServiceProvider
//
//  Application-wide boot wiring.
//
//  Audit H2 (Sept 2026) — company-scoped "exists" validation:
//    Replaces Laravel's validator and its database lookup helper with
//    TenantValidator + TenantPresenceVerifier, so every
//    'exists:<table>,id' rule anywhere in the app only accepts IDs
//    belonging to the current company (or system rows). One central
//    switch instead of editing 288 rules one by one.
//
//  @layer    Provider
//  @depends  App\Validation\TenantValidator, App\Validation\TenantPresenceVerifier
// ══════════════════════════════════════════════════════════════════

use App\Validation\TenantPresenceVerifier;
use App\Validation\TenantValidator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // ── Audit L1: ONE password rule for the whole application ─
        // Profile change, admin-created users, password reset and the
        // "update password" screen all use Password::defaults(), so the
        // rule can never drift between screens again: at least 8
        // characters with upper and lower case, a number and a symbol.
        Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->symbols());

        // ── Audit H2: company-scoped "exists" rules ───────────────
        Validator::setPresenceVerifier(new TenantPresenceVerifier($this->app['db']));
        Validator::resolver(function ($translator, $data, $rules, $messages, $attributes) {
            return new TenantValidator($translator, $data, $rules, $messages, $attributes);
        });

        // ════════════════════════════════════════════════════════════
        //  OBSERVERS & EVENTS
        //
        //  Registered here as modules are built.
        //  Nothing registered yet — models and events are added
        //  as each module stream is completed.
        // ════════════════════════════════════════════════════════════
    }
}
