<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditM37ForeignKeyTest
//
//  An id that points at a missing parent is cleared when the column
//  is allowed to be empty, and it stops the repair when the column
//  is required. The location key on projects is one of the keys the
//  repair is responsible for.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Support\ForeignKeyRepair;
use Illuminate\Support\Facades\DB;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditM37ForeignKeyTest extends ErpTestCase
{
    public function test_set_null_orphan_is_cleared_and_a_required_orphan_is_refused(): void
    {
        $scenario = (new ErpScenario($this->actor))->bootstrap(1);
        $repair   = app(ForeignKeyRepair::class);

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        $id = DB::table('projects')->insertGetId([
            'company_id'               => $scenario->companyId,
            'contract_code'            => 'CC-M37-'.bin2hex(random_bytes(4)),
            'name_en'                  => 'Orphan project',
            'partner_id'               => 999999999,
            'currency_id'              => $scenario->currencyId,
            'contract_value'           => 0,
            'exchange_rate'            => 1,
            'start_date'               => '2026-01-01',
            'end_date'                 => '2026-12-31',
            'status'                   => 'active',
            'project_site_location_id' => 999999999,
            'created_by'               => $this->actor->id,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $repair->resolveOrphans('projects', 'project_site_location_id', 'inventory_locations', 'set null');

        $this->assertNull(DB::table('projects')->where('id', $id)->value('project_site_location_id'));

        try {
            $repair->resolveOrphans('projects', 'partner_id', 'partners', 'restrict');
            $this->fail('A required orphan id was accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('projects.partner_id has', $e->getMessage());
        }

        $this->assertTrue($repair->constraintExists('projects', 'project_site_location_id'));
    }
}
