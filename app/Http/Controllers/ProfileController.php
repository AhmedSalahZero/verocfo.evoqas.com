<?php

namespace App\Http\Controllers;

// ══════════════════════════════════════════════════════════════════
//  ProfileController
//
//  Handles the authenticated user's own profile page.
//  Every user (all roles) can access and update their own profile.
//
//  Routes:
//    GET   /profile          → edit()    — render profile page
//    PATCH /profile          → update()  — update name, titles, prefs
//    PATCH /profile/password → password() — change password
//
//  The profile update passes the authenticated user directly —
//  no company scoping needed since users update only their own data.
//
//  Password change enforces the same strong password policy as
//  UserRequest — min 8 chars, mixed case, numbers, symbols.
//
//  @layer    Controller
//  @depends  App\Models\User
// ══════════════════════════════════════════════════════════════════

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    // ── Show profile page ─────────────────────────────────────────

    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'user' => $request->user(),
        ]);
    }

    // ── Update profile ────────────────────────────────────────────

    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'         => ['required', 'string', 'max:255'],
            'name_ar'      => ['nullable', 'string', 'max:255'],
            'job_title_en' => ['nullable', 'string', 'max:255'],
            'job_title_ar' => ['nullable', 'string', 'max:255'],
            'lang_pref'    => ['required', 'string', 'in:en,ar'],
            'timezone'     => ['nullable', 'string', 'max:50'],
        ]);

        $user->update($validated);

        return back()->with('success', 'Profile updated successfully.');
    }

    // ── Change password ───────────────────────────────────────────

    public function password(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => [
                'required',
                'confirmed',
                Password::defaults(), // Audit L1: the one shared rule, set in AppServiceProvider
            ],
        ]);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('success', 'Password updated successfully.');
    }
}
