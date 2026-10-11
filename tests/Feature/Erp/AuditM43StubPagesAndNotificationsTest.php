<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM43StubPagesAndNotificationsTest
//
//  Audit M-43:
//   1. Menu items whose screen is not built yet open the "not available
//      yet" page (component ComingSoon) — not the company Dashboard.
//      They are served by a real controller, not a Closure, so
//      `php artisan route:cache` works.
//   2. The bell icon shows REAL notifications: only the user's own, only
//      from the company they are working in; "mark all read" touches
//      only their own unread ones.
//
//  Run with:  php artisan test
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Http\Controllers\ComingSoonController;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditM43StubPagesAndNotificationsTest extends ErpTestCase
{
    private ErpScenario $s;
    private User $admin;
    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->s     = (new ErpScenario($this->actor))->createCompany();
        $this->admin = $this->makeAdmin('me');
        $this->colleague = $this->makeAdmin('colleague');
    }

    // ── 1. Stub pages ─────────────────────────────────────────────

    public function test_every_unbuilt_menu_page_is_served_by_the_coming_soon_controller(): void
    {
        $names = $this->comingSoonRouteNames();

        $this->assertGreaterThanOrEqual(19, count($names), 'All 19 known unbuilt pages are routed to ComingSoon.');

        foreach ([
            'analytics.profitability.index', 'analytics.ratios.index', 'analytics.dashboard',
            'planning.dashboard', 'planning.bva.index', 'planning.scenarios.index',
            'valuation.dashboard', 'valuation.dcf.index', 'valuation.multiples.index',
            'valuation.scenarios.index', 'valuation.report.index',
            'fi.cash-position.index', 'fi.cash-forecast.index', 'fi.treasury.index',
            'projects.pl.index',
        ] as $expected) {
            $this->assertContains($expected, $names, "{$expected} must open the Coming Soon page.");
        }
    }

    public function test_an_unbuilt_page_renders_coming_soon_and_names_the_page(): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeaders($this->inertiaHeaders())
            ->get(route('analytics.ratios.index'));

        $response->assertOk();
        $this->assertSame('ComingSoon', $response->json('component'));
        $this->assertSame('analytics_ratios_index', $response->json('props.feature'));
    }

    public function test_every_coming_soon_page_opens_for_an_admin(): void
    {
        foreach ($this->comingSoonRouteNames() as $name) {
            $response = $this->actingAs($this->admin)
                ->withHeaders($this->inertiaHeaders())
                ->get(route($name));

            $this->assertSame(200, $response->getStatusCode(), "{$name} should open.");
            $this->assertSame('ComingSoon', $response->json('component'), "{$name} should show the Coming Soon page.");
        }
    }

    public function test_the_unbuilt_pages_are_no_longer_closures(): void
    {
        foreach ($this->comingSoonRouteNames() as $name) {
            $this->assertNotInstanceOf(
                \Closure::class,
                Route::getRoutes()->getByName($name)->getAction('uses'),
                "{$name} must not be a Closure (route:cache cannot store those)."
            );
        }
    }

    // ── 2. Notifications ──────────────────────────────────────────

    public function test_the_bell_shows_only_my_own_notifications_from_this_company(): void
    {
        $this->notify($this->admin, 'Closed month reopened', false);
        $this->notify($this->admin, 'Lease cheque due', false);
        $this->notify($this->admin, 'Old news', true);
        $this->notify($this->colleague, 'Not mine', false);

        // Same user, but a notification that belongs to some other company.
        $other = (new ErpScenario($this->actor))->createCompany();
        Notification::create([
            'company_id' => $other->companyId, 'user_id' => $this->admin->id, 'type' => 'info',
            'severity' => 'low', 'title' => 'Other company', 'message' => 'x', 'is_read' => false,
        ]);

        $response = $this->actingAs($this->admin)->getJson(route('notifications.index'));

        $response->assertOk();
        $titles = collect($response->json('notifications'))->pluck('title')->sort()->values()->all();
        $this->assertSame(['Closed month reopened', 'Lease cheque due', 'Old news'], $titles);
        $this->assertSame(2, $response->json('unread_count'));

        $this->actingAs($this->admin)->getJson(route('notifications.unread-count'))
            ->assertOk()->assertJson(['count' => 2]);
    }

    public function test_marking_all_read_clears_my_unread_and_leaves_everyone_elses(): void
    {
        $this->notify($this->admin, 'A', false);
        $this->notify($this->admin, 'B', false);
        $this->notify($this->colleague, 'Theirs', false);

        $this->actingAs($this->admin)->postJson(route('notifications.mark-all-read'))
            ->assertOk()->assertJson(['success' => true]);

        $this->actingAs($this->admin)->getJson(route('notifications.unread-count'))->assertJson(['count' => 0]);

        $this->assertSame(1, Notification::withoutGlobalScopes()
            ->where('user_id', $this->colleague->id)->where('is_read', false)->count(),
            'A colleague\'s unread notification must be left alone.');

        $this->assertNotNull(Notification::withoutGlobalScopes()
            ->where('user_id', $this->admin->id)->where('title', 'A')->value('read_at'));
    }

    public function test_notifications_need_a_login(): void
    {
        $this->getJson(route('notifications.index'))->assertUnauthorized();
    }

    // ── Helpers ───────────────────────────────────────────────────

    /** Inertia headers incl. the asset version (a mismatch gives HTTP 409). */
    private function inertiaHeaders(): array
    {
        $version = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());

        return ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-Inertia-Version' => (string) $version];
    }

    /** @return array<int, string> */
    private function comingSoonRouteNames(): array
    {
        $names = [];
        foreach (Route::getRoutes() as $route) {
            // getActionName() is always a string ("Closure" for closures).
            if (str_starts_with($route->getActionName(), ComingSoonController::class)) {
                $names[] = $route->getName();
            }
        }

        return array_values(array_filter($names));
    }

    private function makeAdmin(string $label): User
    {
        return User::withoutGlobalScopes()->create([
            'name'       => "Admin {$label}",
            'email'      => "{$label}-".bin2hex(random_bytes(4)).'@vero-cfo.test',
            'password'   => bcrypt('irrelevant'),
            'role'       => User::ROLE_COMPANY_ADMIN,
            'company_id' => $this->s->companyId,
            'lang_pref'  => 'en',
            'timezone'   => 'Africa/Cairo',
            'is_active'  => true,
        ]);
    }

    private function notify(User $user, string $title, bool $read): Notification
    {
        return Notification::create([
            'company_id' => $this->s->companyId,
            'user_id'    => $user->id,
            'type'       => 'info',
            'severity'   => 'low',
            'title'      => $title,
            'message'    => 'Test message',
            'is_read'    => $read,
            'read_at'    => $read ? now() : null,
        ]);
    }
}
