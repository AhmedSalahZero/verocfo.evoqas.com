<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  AuditL16AuditLogTest
//
//  Creating a partner goes through AuditLog::record, so the row
//  keeps the browser and the partner it is about.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\Partner;
use App\Models\User;
use App\Services\PartnerService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class AuditL16AuditLogTest extends ErpTestCase
{
    public function test_creating_a_partner_records_the_browser_and_the_partner(): void
    {
        $scenario = (new ErpScenario($this->actor))->createCompany();

        request()->headers->set('User-Agent', 'AuditL16');

        $user = User::withoutGlobalScopes()->create([
            'name'       => 'Partner Clerk',
            'email'      => 'partner-'.bin2hex(random_bytes(4)).'@vero-cfo.test',
            'password'   => bcrypt('irrelevant'),
            'role'       => User::ROLE_COMPANY_ADMIN,
            'company_id' => $scenario->companyId,
            'lang_pref'  => 'en',
            'timezone'   => 'Africa/Cairo',
            'is_active'  => true,
        ]);

        $partner = app(PartnerService::class)->create($scenario->companyId, [
            'name_en' => 'Audited Customer',
            'types'   => ['customer'],
        ], $user);

        $log = AuditLog::withoutGlobalScopes()
            ->where('auditable_type', Partner::class)
            ->where('auditable_id', $partner->id)
            ->where('action', 'create')
            ->first();

        $this->assertNotNull($log);
        $this->assertNotNull($log->ip_address);
        $this->assertSame('AuditL16', $log->user_agent);
        $this->assertSame($partner->id, $log->auditable_id);
    }
}
