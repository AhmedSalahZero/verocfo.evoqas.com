<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditL15L21Test
//
//  L-15  a business-rule refusal becomes a normal message, not a 500.
//  L-16  audit rows written with AuditLog::create get IP / browser.
//  L-18  search on products does not escape the other filters.
//  L-19  closed / cancelled projects and orders cannot be re-opened
//        or closed again.
//  L-21  the self-registration route is gone.
//
//  Run with:  php artisan test
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Services\ProductService;
use App\Services\Projects\ProjectService;
use App\Services\Accounting\PurchaseOrderService;
use Illuminate\Support\Facades\Route;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditL15L21Test extends ErpTestCase
{
    public function test_a_refused_action_is_a_message_not_a_server_error(): void
    {
        Route::middleware('web')->post('/_audit_l15', fn () => throw new \DomainException('Not allowed right now.'));

        $this->from('/somewhere')->post('/_audit_l15')
            ->assertRedirect('/somewhere')
            ->assertSessionHas('error', 'Not allowed right now.');

        $this->postJson('/_audit_l15')
            ->assertStatus(422)
            ->assertJson(['message' => 'Not allowed right now.']);
    }

    public function test_audit_rows_written_directly_still_get_ip_and_browser(): void
    {
        $cid = (new ErpScenario($this->actor))->bootstrap(1)->companyId;
        request()->server->set('REMOTE_ADDR', '10.1.2.3');

        $log = AuditLog::create([
            'company_id' => $cid, 'user_id' => $this->actor->id, 'action' => 'create',
            'auditable_type' => Project::class, 'auditable_id' => 1, 'before' => null, 'after' => ['x' => 1],
        ]);

        $this->assertSame('10.1.2.3', $log->fresh()->ip_address);
        $this->assertNotNull($log->fresh()->created_at);
    }

    public function test_product_search_keeps_the_other_filters(): void
    {
        $s   = (new ErpScenario($this->actor))->bootstrap(2);
        $one = \App\Models\Product::withoutGlobalScopes()->find($s->products[0]->id);

        $hit = app(ProductService::class)->paginate($s->companyId, ['search' => $one->code]);
        $this->assertGreaterThanOrEqual(1, $hit->total());

        $none = app(ProductService::class)->paginate($s->companyId, ['search' => $one->code, 'category_id' => 999999999]);
        $this->assertSame(0, $none->total(), 'The search must not escape the category filter.');
    }

    public function test_a_closed_or_cancelled_project_cannot_be_made_active_again(): void
    {
        foreach (['closed', 'cancelled'] as $final) {
            $project = (new Project)->forceFill(['status' => $final]);

            try {
                app(ProjectService::class)->changeStatus(1, $project, 'active', $this->actor);
                $this->fail("A {$final} project must not become active.");
            } catch (\DomainException $e) {
                $this->assertStringContainsString($final, $e->getMessage());
            }
        }
    }

    public function test_a_draft_purchase_order_cannot_be_closed(): void
    {
        $po = (new PurchaseOrder)->forceFill(['status' => 'draft']);

        $this->expectException(\DomainException::class);
        app(PurchaseOrderService::class)->close($po, $this->actor);
    }

    public function test_self_registration_is_gone(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x'])->assertStatus(404);
    }

}
