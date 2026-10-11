<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  UserService
//
//  Handles all business logic for User management within a company.
//
//  Permission system:
//    savePermissions()  → upserts one row per feature per user
//    loadPermissions()  → returns nested structure for the Vue matrix
//
//  Permission structure returned by loadPermissions():
//    [
//      'accounting' => [
//        'customer_invoices' => ['can_view'=>true, 'can_create'=>false, ...],
//        'supplier_bills'    => ['can_view'=>true, ...],
//        ...
//      ],
//      'banking' => [...],
//    ]
//
//  Permission structure expected by savePermissions():
//    Same nested structure — sent from Users/Permissions.vue
//
//  All writes wrapped in DB::transaction per Bible Rule 14.1.
//
//  @layer    Service
//  Seat limit (Audit C5, Sept 2026): create / re-activate refuse
//  when active users would exceed Company::maxUsers().
//
//  @depends  App\Models\User, App\Models\UserModulePermission, App\Models\Company
//            App\Models\AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Exceptions\Users\SeatLimitReachedException;
use App\Models\Company;
use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserService
{
    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return User::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->when($filters['search'] ?? null, fn ($q, $v) =>
                $q->where(fn ($q) =>
                    $q->where('name', 'like', "%{$v}%")
                      ->orWhere('email', 'like', "%{$v}%")
                )
            )
            ->when($filters['role'] ?? null, fn ($q, $v) =>
                $q->where('role', $v)
            )
            ->when(isset($filters['is_active']), fn ($q) =>
                $q->where('is_active', $filters['is_active'])
            )
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): User
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            if ($data['is_active'] ?? true) {
                $this->assertSeatAvailable($companyId);
            }

            $data['company_id'] = $companyId;
            $data['password']   = Hash::make($data['password']);

            $user = User::create($data);

            AuditLog::create([
                'company_id'     => $companyId,
                'user_id'        => $actor->id,
                'action'         => 'user_created',
                'auditable_type' => User::class,
                'auditable_id'   => $user->id,
                'before'         => null,
                'after'          => $user->makeHidden(['password'])->toArray(),
                'ip_address'     => request()->ip(),
                'user_agent'     => request()->userAgent(),
            ]);

            return $user;
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            // Switching an inactive user back on takes a seat (Audit C5).
            if (! $user->is_active && ! empty($data['is_active'])) {
                $this->assertSeatAvailable((int) $user->company_id);
            }

            $before = $user->makeHidden(['password'])->toArray();

            if (! empty($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password'], $data['password_confirmation']);
            }

            unset($data['password_confirmation']);
            $user->update($data);

            AuditLog::create([
                'company_id'     => $user->company_id,
                'user_id'        => $actor->id,
                'action'         => 'user_updated',
                'auditable_type' => User::class,
                'auditable_id'   => $user->id,
                'before'         => $before,
                'after'          => $user->fresh()->makeHidden(['password'])->toArray(),
                'ip_address'     => request()->ip(),
                'user_agent'     => request()->userAgent(),
            ]);

            return $user->fresh();
        });
    }

    // ── Toggle active ─────────────────────────────────────────────

    public function toggleActive(User $user, User $actor): User
    {
        // Re-activating takes a seat — checked inside update() (Audit C5).
        return $this->update($user, ['is_active' => ! $user->is_active], $actor);
    }

    // ── Seat limit (Audit C5, Sept 2026) ──────────────────────────
    // Active users (Company Admin included) may not exceed
    // Company::maxUsers() — tier seats + additional_users. Inactive
    // users do not use a seat. lockForUpdate() stops two admins
    // creating the last seat at the same moment.

    public function seatUsage(int $companyId): array
    {
        $company = Company::findOrFail($companyId);

        return [
            'used' => User::where('company_id', $companyId)->where('is_active', true)->count(),
            'max'  => $company->maxUsers(),
        ];
    }

    private function assertSeatAvailable(int $companyId): void
    {
        $company = Company::lockForUpdate()->findOrFail($companyId);
        $used    = User::where('company_id', $companyId)->where('is_active', true)->count();
        $max     = $company->maxUsers();

        if ($used >= $max) {
            throw new SeatLimitReachedException(
                "Your {$company->subscription_tier} plan includes {$max} active users and all {$max} are in use. "
                . 'Deactivate a user or contact Vero CFO to add more seats.'
            );
        }
    }

    // ── Save permissions ──────────────────────────────────────────
    // $permissions shape:
    //   ['accounting' => ['customer_invoices' => ['can_view'=>true,...], ...]]

    public function savePermissions(User $user, array $permissions, User $actor): void
    {
        DB::transaction(function () use ($user, $permissions, $actor) {
            foreach ($permissions as $module => $features) {
                foreach ($features as $feature => $flags) {
                    UserModulePermission::updateOrCreate(
                        [
                            'company_id' => $user->company_id,
                            'user_id'    => $user->id,
                            'module'     => $module,
                            'feature'    => $feature,
                        ],
                        [
                            'can_view'    => $flags['can_view']    ?? false,
                            'can_create'  => $flags['can_create']  ?? false,
                            'can_edit'    => $flags['can_edit']    ?? false,
                            'can_reverse' => $flags['can_reverse'] ?? false,
                            'can_post'    => $flags['can_post']    ?? false, // Audit H3
                            'can_approve' => $flags['can_approve'] ?? false, // Audit H3
                            'granted_by'  => $actor->id,
                        ]
                    );
                }
            }

            AuditLog::create([
                'company_id'     => $user->company_id,
                'user_id'        => $actor->id,
                'action'         => 'permissions_updated',
                'auditable_type' => User::class,
                'auditable_id'   => $user->id,
                'before'         => null,
                'after'          => $permissions,
                'ip_address'     => request()->ip(),
                'user_agent'     => request()->userAgent(),
            ]);
        });
    }

    // ── Load permissions ──────────────────────────────────────────
    // Returns nested structure matching FEATURES constant:
    //   ['accounting' => ['customer_invoices' => [...flags], ...], ...]

    public function loadPermissions(User $user): array
    {
        $rows = UserModulePermission::withoutGlobalScopes()
            ->where('company_id', $user->company_id)
            ->where('user_id', $user->id)
            ->get()
            ->groupBy('module')
            ->map(fn ($moduleRows) => $moduleRows->keyBy('feature'));

        $result = [];

        foreach (UserModulePermission::FEATURES as $module => $features) {
            $result[$module] = [];
            foreach ($features as $featureDef) {
                $key = $featureDef['key'];
                $row = $rows[$module][$key] ?? null;

                $result[$module][$key] = [
                    'can_view'    => $row?->can_view    ?? false,
                    'can_create'  => $row?->can_create  ?? false,
                    'can_edit'    => $row?->can_edit    ?? false,
                    'can_reverse' => $row?->can_reverse ?? false,
                    'can_post'    => $row?->can_post    ?? false,
                    'can_approve' => $row?->can_approve ?? false,
                ];
            }
        }

        return $result;
    }
}
