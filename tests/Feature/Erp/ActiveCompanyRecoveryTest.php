<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  ActiveCompanyRecoveryTest
//
//  REGRESSION GUARD — a super admin whose impersonated company has
//  been deleted or deactivated must be sent back to pick another,
//  not left on a bare 403.
//
//  Reported live: "403 — No company associated with this account"
//  appeared mid-session while a company was already selected. Cause:
//  the company had been deleted since the session key was set, which
//  demo:seed-testing-cycle does on every run (it rebuilds its three
//  companies with new ids each time). Deleting a company cannot reach
//  into anyone's session, so the id in it simply goes stale.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Http\Middleware\ActiveCompany;
use App\Models\Company;
use Illuminate\Http\Request;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class ActiveCompanyRecoveryTest extends ErpTestCase
{
    private function runMiddleware(?int $impersonatedId, $user)
    {
        $request = Request::create('/accounting/customer-invoices', 'GET');
        $request->setUserResolver(fn () => $user);

        session(['impersonate_company_id' => $impersonatedId]);

        return (new ActiveCompany)->handle($request, fn ($r) => response('OK'));
    }

    private function superAdmin()
    {
        $user = $this->actor;

        if (! $user->isSuperAdmin()) {
            $this->markTestSkipped('The suite actor is not a super admin on this database.');
        }

        return $user;
    }

    /**
     * The reported failure: an id in the session that no longer
     * resolves to a company.
     *
     * A deleted company must redirect, clear the dead key, and say
     * why — never 403. The user can fix this in one click, so the
     * middleware must let them.
     */
    public function test_a_deleted_impersonated_company_redirects_instead_of_403(): void
    {
        $user = $this->superAdmin();

        $missingId = ((int) Company::withoutGlobalScopes()->max('id')) + 5000;

        $response = $this->runMiddleware($missingId, $user);

        $this->assertSame(
            302, $response->getStatusCode(),
            'A stale impersonation id must redirect, not abort. This was a 403 with no '.
            'navigation on it, in the middle of the user\'s work.'
        );

        $this->assertStringContainsString(
            '/companies',
            (string) $response->headers->get('Location'),
            'And it must land on the company picker, which is where the problem is fixed.'
        );

        $this->assertNull(
            session('impersonate_company_id'),
            'The dead key must be cleared, or the next request repeats the failure.'
        );
    }

    /**
     * A deactivated company is the same shape of problem for a super
     * admin: recoverable in one click, so not a dead end either.
     */
    public function test_a_deactivated_impersonated_company_redirects_instead_of_403(): void
    {
        $user = $this->superAdmin();
        $scenario = (new ErpScenario($this->actor))->createCompany();

        Company::withoutGlobalScopes()
            ->where('id', $scenario->companyId)
            ->update(['is_active' => false]);

        $response = $this->runMiddleware($scenario->companyId, $user);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull(session('impersonate_company_id'));
    }

    /**
     * Guard the guard: a company that is present and active must pass
     * straight through and bind the container. Without this a fix
     * that redirected unconditionally would look correct.
     */
    public function test_a_live_company_passes_through_and_binds_the_container(): void
    {
        $user = $this->superAdmin();
        $scenario = (new ErpScenario($this->actor))->createCompany();

        $response = $this->runMiddleware($scenario->companyId, $user);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getContent());
        $this->assertSame(
            $scenario->companyId,
            app('currentCompanyId'),
            'The resolved company id must be bound — it is the only authorised source '.
            'of company scope in the application.'
        );
    }

    /** No impersonation at all keeps its existing behaviour. */
    public function test_no_impersonation_session_redirects_to_the_company_picker(): void
    {
        $response = $this->runMiddleware(null, $this->superAdmin());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/companies', (string) $response->headers->get('Location'));
    }
}
