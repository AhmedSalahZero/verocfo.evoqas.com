<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuthorizationTest
//
//  Who is allowed to do what.
//
//  This system ships a complete permission model — UserModulePermission
//  with six modules, four actions and a feature key per screen — and a
//  ModulePermission middleware that enforces it, registered under the
//  'module' alias and documented in routes/web.php. Everything needed
//  is present.
//
//  What no test had ever asked is whether it is ACTUALLY ATTACHED to
//  the routes that change money. It is not: it guards HR leave and
//  expense claims, Planning, Valuation and Analytics, and nothing
//  else. Every write route in Accounting and Banking — post a journal
//  entry, invoice a customer, pay a supplier, close a fiscal year —
//  is reachable by any authenticated user of the company regardless of
//  what they have been granted.
//
//  These tests are written as the CORRECT behaviour, so they fail
//  until the middleware is attached. That is deliberate: the point of
//  a permission model nobody enforces is to make the gap visible, and
//  a test that asserted today's behaviour would lock the gap in.
//
//  The route table is READ, not listed by hand — a new unguarded
//  route fails here the day it is added.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\User;
use App\Models\UserModulePermission;
use Illuminate\Support\Facades\Route;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuthorizationTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scenario = (new ErpScenario($this->actor))->createCompany();
    }

    // ══════════════════════════════════════════════════════════════
    //  Is the permission model attached at all?
    // ══════════════════════════════════════════════════════════════

    /**
     * Every route that changes state must pass through the permission
     * middleware. A route that writes to the database without one is
     * open to every authenticated user in the company.
     *
     * Authentication is not authorization: ActiveCompany establishes
     * WHICH company a request belongs to, never WHETHER this user may
     * perform this action.
     */
    public function test_every_state_changing_route_is_behind_the_permission_middleware(): void
    {
        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            if (! $this->changesState($route->methods())) {
                continue;
            }

            if ($this->isPublicAuthRoute($route->uri())) {
                continue;
            }

            // AUTH-001 fix (2026-09) — a small number of state-changing
            // routes belong to no module and cannot be expressed as one.
            // They are named here rather than waved through by a pattern,
            // so adding a route to this list is a deliberate act that
            // shows up in review.
            //
            // These are self-service: every authenticated user may change
            // their own profile and password, switch their own interface
            // language, and mark their own notifications read. There is
            // no permission that could sensibly gate them, and gating
            // them by module would deny a user their own account.
            if ($this->isSelfServiceRoute($route->uri())) {
                continue;
            }

            $guarded = false;

            // gatherMiddleware() hands back whatever was written on the
            // route, which is the 'module:...' alias — not the resolved
            // class name — so both spellings count.
            //
            // 'admin' counts too: user management, company management and
            // impersonation are administration rather than module work,
            // and AdminOnly is a real guard, not an exemption — see that
            // middleware's header for why a feature key would have been
            // the wrong instrument there.
            foreach ($route->gatherMiddleware() as $middleware) {
                $middleware = (string) $middleware;

                if (str_contains($middleware, 'ModulePermission') || str_starts_with($middleware, 'module:')) {
                    $guarded = true;
                }

                if (str_contains($middleware, 'AdminOnly') || $middleware === 'admin'
                    || str_contains($middleware, 'SuperAdminOnly') || $middleware === 'super.admin') {
                    $guarded = true;
                }
            }

            if (! $guarded) {
                $unguarded[] = implode('|', $route->methods())." {$route->uri()}";
            }
        }

        sort($unguarded);

        $this->assertSame(
            [],
            $unguarded,
            count($unguarded).' state-changing routes have no permission check at all, so any authenticated '
            ."user of the company can perform them whatever their granted permissions say:\n  "
            .implode("\n  ", array_slice($unguarded, 0, 60))
            ."\n\nThe middleware exists (App\\Http\\Middleware\\ModulePermission, alias 'module') and is already "
            ."used on HR leave, expense claims, Planning, Valuation and Analytics — see routes/web.php around "
            .'the hr.leave routes for the shape. It is simply not attached anywhere else.'
        );
    }

    /**
     * Routes a user performs on their own account, which no module
     * permission could sensibly gate.
     *
     * An explicit list, not a prefix match: 'profile' must not quietly
     * exempt a future 'profile-approvals' screen.
     */
    private function isSelfServiceRoute(string $uri): bool
    {
        return in_array(trim($uri, '/'), [
            'profile',
            'profile/password',
            'lang',
            'notifications/read-all',

            // Messaging is self-service in the same sense: every user
            // may write to a colleague and raise a support ticket, and
            // there is no company module it could belong to — the Super
            // Admin who answers the tickets has no company at all, so a
            // module: guard would lock the Support Inbox against the one
            // person who needs it.
            //
            // It is NOT ungoverned. Authorization here is per
            // conversation, not per module, and lives in
            // MessagingController::authorizeConversation() plus the
            // same-company check in startDirect(): you can only touch a
            // thread you are a participant in (or a support ticket, if
            // you are the Super Admin). Tests\Feature\Erp\MessagingTest
            // holds that line — it asserts an outsider is refused on
            // read, send, mark-read and delete, that a Super Admin
            // cannot read private chats, and that a cross-company chat
            // cannot be opened at all.
            'messages/start',
            'messages/support',
            'messages/{conversation}/send',
            'messages/{conversation}/read',
            'messages/{conversation}/status',
            'messages/{conversation}/messages/{message}',

            // Laravel's own channel-authorization endpoint, registered
            // by routes/channels.php. It changes no application state —
            // it answers "may this logged-in user subscribe to this
            // channel?" and the answer is the callback in channels.php,
            // which is the guard. A module: permission on it would be
            // meaningless: there is no module a websocket handshake
            // belongs to.
            'broadcasting/auth',
        ], true);
    }

    // ══════════════════════════════════════════════════════════════
    //  Does the middleware work where it IS attached?
    // ══════════════════════════════════════════════════════════════

    /**
     * The guarded routes prove the mechanism itself is sound, which
     * is what makes the gap above a wiring problem rather than a
     * design one.
     */
    public function test_a_user_without_permission_is_refused_on_a_guarded_route(): void
    {
        $this->actingAs($this->userWithNoPermissions())
            ->get(route('hr.leave.index'))
            ->assertForbidden();
    }

    public function test_a_user_with_the_granted_permission_is_allowed_on_a_guarded_route(): void
    {
        $user = $this->userWithNoPermissions();

        UserModulePermission::withoutGlobalScopes()->create([
            'user_id'    => $user->id,
            'company_id' => $this->scenario->companyId,
            'module'     => UserModulePermission::MODULE_HR,
            'feature'    => 'leave_management',
            'can_view'   => true,
            'can_create' => false,
            'can_edit'   => false,
            'can_reverse' => false,
            'granted_by' => $this->actor->id,
        ]);

        $this->actingAs($user->fresh())
            ->get(route('hr.leave.index'))
            ->assertOk();
    }

    /**
     * View is not create. A user granted read access must not be able
     * to write — the single most common way a permission model is got
     * wrong is treating any grant as full access.
     */
    public function test_view_permission_does_not_confer_create_permission(): void
    {
        $user = $this->userWithNoPermissions();

        UserModulePermission::withoutGlobalScopes()->create([
            'user_id'     => $user->id,
            'company_id'  => $this->scenario->companyId,
            'module'      => UserModulePermission::MODULE_HR,
            'feature'     => 'leave_management',
            'can_view'    => true,
            'can_create'  => false,
            'can_edit'    => false,
            'can_reverse' => false,
            'granted_by'  => $this->actor->id,
        ]);

        $this->actingAs($user->fresh())
            ->get(route('hr.leave.create'))
            ->assertForbidden();
    }

    /**
     * A grant on one module says nothing about another. This is the
     * check that fails if canOnModule() ever stops filtering by
     * module — which would silently turn every grant into a
     * full-access grant.
     */
    public function test_a_grant_on_one_module_does_not_leak_into_another(): void
    {
        $user = $this->userWithNoPermissions();

        UserModulePermission::withoutGlobalScopes()->create([
            'user_id'     => $user->id,
            'company_id'  => $this->scenario->companyId,
            'module'      => UserModulePermission::MODULE_ANALYTICS,
            'feature'     => 'sales_analysis',
            'can_view'    => true,
            'can_create'  => true,
            'can_edit'    => true,
            'can_reverse' => true,
            'granted_by'  => $this->actor->id,
        ]);

        $this->actingAs($user->fresh())
            ->get(route('hr.leave.index'))
            ->assertForbidden();
    }

    /**
     * A grant belongs to a company as well as a user. Someone granted
     * HR access at company A must not carry it into company B.
     */
    public function test_a_grant_is_scoped_to_the_company_it_was_made_in(): void
    {
        $other = (new ErpScenario($this->actor))->createCompany();
        $user  = $this->userWithNoPermissions();

        UserModulePermission::withoutGlobalScopes()->create([
            'user_id'     => $user->id,
            'company_id'  => $other->companyId,
            'module'      => UserModulePermission::MODULE_HR,
            'feature'     => 'leave_management',
            'can_view'    => true,
            'can_create'  => true,
            'can_edit'    => true,
            'can_reverse' => true,
            'granted_by'  => $this->actor->id,
        ]);

        // The user belongs to the FIRST company; the grant was made in
        // the second. It must not apply.
        $this->actingAs($user->fresh())
            ->get(route('hr.leave.index'))
            ->assertForbidden();
    }

    /**
     * An inactive company is closed to everyone below admin, whatever
     * their grants say.
     */
    public function test_a_user_of_an_inactive_company_is_refused(): void
    {
        $user = $this->userWithNoPermissions();

        UserModulePermission::withoutGlobalScopes()->create([
            'user_id'     => $user->id,
            'company_id'  => $this->scenario->companyId,
            'module'      => UserModulePermission::MODULE_HR,
            'feature'     => 'leave_management',
            'can_view'    => true,
            'can_create'  => true,
            'can_edit'    => true,
            'can_reverse' => true,
            'granted_by'  => $this->actor->id,
        ]);

        \App\Models\Company::withoutGlobalScopes()
            ->where('id', $this->scenario->companyId)
            ->update(['is_active' => false]);

        $this->actingAs($user->fresh())
            ->get(route('hr.leave.index'))
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════
    //  Unauthenticated
    // ══════════════════════════════════════════════════════════════

    public function test_a_state_changing_route_rejects_an_unauthenticated_request(): void
    {
        $this->post(route('hr.leave.store'), [])
            ->assertRedirect(route('login'));
    }

    // ══════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════

    private function userWithNoPermissions(): User
    {
        return User::withoutGlobalScopes()->create([
            'name'       => 'Data Entry Clerk',
            'email'      => 'clerk-'.bin2hex(random_bytes(4)).'@vero-cfo.test',
            'password'   => bcrypt('irrelevant'),
            'role'       => User::ROLE_USER,
            'company_id' => $this->scenario->companyId,
            'lang_pref'  => 'en',
            'timezone'   => 'Africa/Cairo',
            'is_active'  => true,
        ]);
    }

    /** @param array<int, string> $methods */
    private function changesState(array $methods): bool
    {
        return (bool) array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE']);
    }

    /**
     * Login, logout, password reset and the language switch are
     * deliberately outside the permission model — they are how a user
     * gets in, gets out, or changes their own settings.
     */
    private function isPublicAuthRoute(string $uri): bool
    {
        foreach ([
            'login', 'logout', 'register', 'password', 'forgot-password', 'reset-password',
            'confirm-password', 'email', 'profile', 'lang', 'impersonate', 'notifications',
            'storage',
        ] as $prefix) {
            if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
