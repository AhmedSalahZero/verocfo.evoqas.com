<?php

namespace App\Http\Controllers\Auth;

// ══════════════════════════════════════════════════════════════════
//  AuthenticatedSessionController
//
//  Handles login and logout for all Vero CFO user types.
//
//  Login redirect logic (store method):
//    Super Admin  → companies.index (platform view, no company context)
//    Company Admin / User → dashboard (requires active.company middleware)
//
//  Uses $user->isSuperAdmin() — the role-based method on User model.
//  Never uses $user->is_super_admin (not a column — role is the column).
//
//  The login page is rendered via GuestLayout automatically because
//  Login.vue defines its own layout via defineOptions.
//
//  @layer    Controller (Auth)
//  @depends  App\Http\Requests\Auth\LoginRequest
//            App\Models\User (isSuperAdmin method)
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class AuthenticatedSessionController extends Controller
{
    // ── Show login page ───────────────────────────────────────────

    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status'           => session('status'),
        ]);
    }

    // ── Handle login ──────────────────────────────────────────────

    public function store(LoginRequest $request): SymfonyResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $user = Auth::user();

        // Update last login timestamp
        $user->update(['last_login_at' => now()]);

        if ($user->isSuperAdmin()) {
            return Inertia::location(route('companies.index'));
        }

        // Audit L3: a full page load (not an in-page visit) so the browser
        // receives the signed-in route list instead of the minimal login one.
        return Inertia::location(route('dashboard'));
    }

    // ── Handle logout ─────────────────────────────────────────────

    public function destroy(Request $request): SymfonyResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Audit L3: full reload so the signed-in route list is dropped.
        return Inertia::location('/');
    }
}