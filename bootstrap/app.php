<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // تفويض قنوات البث — شوف routes/channels.php
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // ── Global web middleware ─────────────────────────────────
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // ── Route middleware aliases ──────────────────────────────
        $middleware->alias([

            // ZAVERO — company & subscription guard
            // Usage: ->middleware('active.company')
            'active.company' => \App\Http\Middleware\ActiveCompany::class,

            // ZAVERO — module + permission guard
            // Usage: ->middleware('module:crm,view')
            //        ->middleware('module:billing,validate')
            'module'         => \App\Http\Middleware\ModulePermission::class,

            // ZAVERO — administration guard (AUTH-001 companion)
            // Usage: ->middleware('admin')
            // Was written and wired onto 10 routes (impersonate,
            // companies, user permissions) but never registered here —
            // Laravel had nothing to resolve 'admin' to, hence
            // "Target class [admin] does not exist." on every one of
            // those routes. Fixed 8 Sep 2026, reported by Mahmoud.
            'admin'          => \App\Http\Middleware\AdminOnly::class,

            // ZAVERO — platform-owner guard (Audit C3, Sept 2026)
            // Usage: ->middleware('super.admin')
            // Super Admin ONLY. Company Admins are refused. Used on
            // every Companies screen (list, create, edit, toggle, delete).
            'super.admin'    => \App\Http\Middleware\SuperAdminOnly::class,

            // ZAVERO — same-company user guard (Audit C2, Sept 2026)
            // Usage: ->middleware('user.same.company')
            // Any /users/{user}/... route: the target user must belong
            // to the current company and must not be a Super Admin.
            'user.same.company' => \App\Http\Middleware\EnsureSameCompanyUser::class,

        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Audit L-15: a business-rule refusal (the services throw
        // \DomainException for every "you cannot do that" message) must
        // reach the user as a normal red message, never as a raw
        // "500 Server Error" page. Controllers that already catch it keep
        // doing so; this is the safety net for every action that does not.
        // Only for actions that change something (POST/PUT/PATCH/DELETE):
        // sending a failed page-load "back" could loop.
        $exceptions->dontReport(\DomainException::class);
        $exceptions->render(function (\DomainException $e, \Illuminate\Http\Request $request) {
            if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
                return null; // default handling
            }

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        });
    })->create();