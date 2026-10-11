<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM42TenantHttpTest
//
//  Audit M-42 (test coverage): tenant isolation OVER HTTP.
//
//  The existing isolation tests drive services. This one asks the
//  question a real attacker asks: "I am logged in to company A — what
//  happens if I type the address of a record that belongs to company B?"
//
//  It builds a busy company B (partner, product, purchase, sale,
//  invoice, bill, ...), then logs in as an admin of company A and opens
//  EVERY page of the application that takes one record id in its
//  address and whose record type is company-owned. Each of those must
//  answer "not found" (404) or "forbidden" (403) — never show the page.
//
//  The list of pages is not typed in by hand: it is discovered from the
//  real route table, so a page added next month is covered automatically.
//  A guard assertion fails the test if discovery ever finds almost
//  nothing (which would make the test pass while checking nothing).
//
//  Run with:  php artisan test
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\Traits\BelongsToCompany;
use App\Models\User;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Support\Facades\Route;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditM42TenantHttpTest extends ErpTestCase
{
    private ErpScenario $companyA;
    private ErpScenario $companyB;
    private User $adminOfA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = (new ErpScenario($this->actor))->createCompany();
        $this->adminOfA = User::withoutGlobalScopes()->create([
            'name'       => 'Admin of A',
            'email'      => 'a-'.bin2hex(random_bytes(4)).'@vero-cfo.test',
            'password'   => bcrypt('irrelevant'),
            'role'       => User::ROLE_COMPANY_ADMIN,
            'company_id' => $this->companyA->companyId,
            'lang_pref'  => 'en',
            'timezone'   => 'Africa/Cairo',
            'is_active'  => true,
        ]);

        // Company B: give it as many kinds of record as the harness can make.
        $this->companyB = (new ErpScenario($this->actor))->bootstrap(1);
        $grn = $this->companyB->receiveStock([[$this->companyB->products[0], 20, 10.00]], '2026-01-10');
        $this->companyB->billGrn($grn, '2026-01-12');
        $so = $this->companyB->createSalesOrder([[$this->companyB->products[0], 2, 25.00]], '2026-02-01');
        $this->companyB->deliver($so, '2026-02-05');
        $this->companyB->invoice($so, '2026-02-05');
        $this->companyB->createEmployee('B Employee');
        $this->companyB->createLeaveType('B Leave');
        $this->companyB->createBankAccount('B Bank');
    }

    public function test_company_a_cannot_open_any_single_record_page_of_company_b(): void
    {
        $tested   = 0;
        $leaks    = [];
        $skipped  = [];

        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }
            if (! in_array('active.company', $route->gatherMiddleware(), true)) {
                continue;
            }
            if (count($route->parameterNames()) !== 1) {
                continue;
            }

            $model = $this->boundCompanyOwnedModel($route);
            if ($model === null) {
                continue;
            }

            $record = $model::withoutGlobalScopes()
                ->where('company_id', $this->companyB->companyId)
                ->first();
            if ($record === null) {
                $skipped[$model] = true; // company B has no such record: nothing to try
                continue;
            }

            $url = '/'.preg_replace('/\{[^}]+\}/', (string) $record->getRouteKey(), $route->uri());
            $status = $this->actingAs($this->adminOfA)->get($url)->getStatusCode();
            $tested++;

            if (! in_array($status, [403, 404], true)) {
                $leaks[] = "{$url}  →  HTTP {$status}  ({$model})";
            }
        }

        $this->assertSame(
            [], $leaks,
            "Company A could reach company B's records through these pages:\n".implode("\n", $leaks)
        );

        $this->assertGreaterThan(
            10, $tested,
            'The route walk found almost nothing to test (skipped record types: '.implode(', ', array_keys($skipped)).'). '.
            'This test would be passing while checking nothing.'
        );
    }

    public function test_company_b_records_are_still_reachable_by_company_b(): void
    {
        // The control: the same kind of request, from the owner, works.
        // Without this the test above could pass simply because every URL is wrong.
        $adminOfB = User::withoutGlobalScopes()->create([
            'name'       => 'Admin of B',
            'email'      => 'b-'.bin2hex(random_bytes(4)).'@vero-cfo.test',
            'password'   => bcrypt('irrelevant'),
            'role'       => User::ROLE_COMPANY_ADMIN,
            'company_id' => $this->companyB->companyId,
            'lang_pref'  => 'en',
            'timezone'   => 'Africa/Cairo',
            'is_active'  => true,
        ]);

        $owned = 0;
        foreach (Route::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)
                || ! in_array('active.company', $route->gatherMiddleware(), true)
                || count($route->parameterNames()) !== 1) {
                continue;
            }
            $model = $this->boundCompanyOwnedModel($route);
            if ($model === null || $model::withoutGlobalScopes()->where('company_id', $this->companyB->companyId)->doesntExist()) {
                continue;
            }
            $record = $model::withoutGlobalScopes()->where('company_id', $this->companyB->companyId)->first();
            $url    = '/'.preg_replace('/\{[^}]+\}/', (string) $record->getRouteKey(), $route->uri());

            // Some pages legitimately redirect or need extra input; what matters is
            // that the OWNER is never told the record does not exist.
            if ($this->actingAs($adminOfB)->get($url)->getStatusCode() !== 404) {
                $owned++;
            }
        }

        $this->assertGreaterThan(0, $owned, 'The owner can open at least some of its own record pages.');
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * The company-owned model class this route's single address
     * parameter is bound to, or null when it is not a tenant model.
     *
     * @return class-string|null
     */
    private function boundCompanyOwnedModel($route): ?string
    {
        try {
            $parameters = $route->signatureParameters(['subClass' => UrlRoutable::class]);
        } catch (\Throwable) {
            return null; // controller class missing / not reflectable: not our concern here
        }

        $name = $route->parameterNames()[0];

        foreach ($parameters as $parameter) {
            if ($parameter->getName() !== $name) {
                continue;
            }
            $class = $parameter->getType()?->getName();
            if ($class && in_array(BelongsToCompany::class, class_uses_recursive($class), true)) {
                return $class;
            }
        }

        return null;
    }
}
