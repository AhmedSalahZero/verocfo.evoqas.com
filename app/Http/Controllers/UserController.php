<?php

namespace App\Http\Controllers;

// ══════════════════════════════════════════════════════════════════
//  UserController
//
//  Handles HTTP layer for User management within a company.
//  Accessible by Super Admin (via impersonation) and Company Admin.
//  Zero business logic — all delegated to UserService.
//
//  Routes (under auth + active.company middleware):
//    GET    /users                     → index()
//    GET    /users/create              → create()
//    POST   /users                     → store()
//    GET    /users/{user}/edit         → edit()
//    PUT    /users/{user}              → update()
//    PATCH  /users/{user}/toggle       → toggleActive()
//    GET    /users/{user}/permissions  → permissions()
//    PUT    /users/{user}/permissions  → savePermissions()
//
//  Company ID is always resolved via $this->companyId($request)
//  which reads app('currentCompanyId') — set by ActiveCompany
//  middleware. Never reads from $user->company_id directly.
//
//  Seat limit (Audit C5): store / update / toggle catch
//  SeatLimitReachedException and show it as a red message.
//  index() passes 'seats' (used / max) to the Users list.
//
//  All methods under 15 lines per Building Structure Bible Rule 8.
//
//  @layer    Controller
//  @depends  App\Services\UserService
//            App\Http\Requests\UserRequest
//            App\Models\User, App\Models\UserModulePermission
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\Users\SeatLimitReachedException;
use App\Http\Requests\UserRequest;
use App\Models\User;
use App\Models\UserModulePermission;
use App\Services\UserService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function __construct(private UserService $service) {}

    // ── Index ─────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $users     = $this->service->paginate($companyId, $request->only('search', 'role', 'is_active'));

        return Inertia::render('Users/Index', [
            'users'   => $users,
            'seats'   => $this->service->seatUsage($companyId),
            'filters' => $request->only('search', 'role', 'is_active'),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(): Response
    {
        return Inertia::render('Users/Form', [
            'user' => null,
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────

    public function store(UserRequest $request)
    {
        try {
            $user = $this->service->create($this->companyId($request), $request->validated(), $request->user());
        } catch (SeatLimitReachedException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('users.index')
            ->with('success', "User [{$user->name}] created successfully.");
    }

    // ── Edit ──────────────────────────────────────────────────────

    public function edit(User $user): Response
    {
        return Inertia::render('Users/Form', [
            'user' => $user,
        ]);
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(UserRequest $request, User $user)
    {
        try {
            $this->service->update($user, $request->validated(), $request->user());
        } catch (SeatLimitReachedException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('users.index')
            ->with('success', "User [{$user->name}] updated successfully.");
    }

    // ── Toggle active ─────────────────────────────────────────────

    public function toggleActive(Request $request, User $user)
    {
        try {
            $this->service->toggleActive($user, $request->user());
        } catch (SeatLimitReachedException $e) {
            return back()->with('error', $e->getMessage());
        }
        $status = $user->fresh()->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "User [{$user->name}] {$status}.");
    }

    // ── Permissions page ──────────────────────────────────────────

    public function permissions(User $user): Response
    {
        return Inertia::render('Users/Permissions', [
            'user'            => $user,
            'permissions'     => $this->service->loadPermissions($user),
            'tier'            => $user->company?->subscription_tier ?? 'Starter',
            'isManufacturing' => $user->company?->isManufacturing() ?? false,
        ]);
    }

    // ── Save permissions ──────────────────────────────────────────

    public function savePermissions(Request $request, User $user)
    {
        $this->service->savePermissions(
            $user,
            $request->input('permissions', []),
            $request->user()
        );

        return back()->with('success', "Permissions for [{$user->name}] updated successfully.");
    }
}
