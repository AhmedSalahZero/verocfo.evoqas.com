<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  ChartOfAccountCodeTest
//
//  REGRESSION GUARD — auto-generated account codes collided with the
//  seeded system chart. Reported live: adding a sub-account under
//  111 Tangible Fixed Assets produced code 111001, which 111001 Land
//  already held.
//
//  Two independent defects, both guarded here:
//
//    1. ChartOfAccount::nextCode() filtered on company_id alone.
//       System accounts carry company_id = NULL, so every seeded
//       sibling was invisible to it and the counter restarted at 001.
//
//    2. Nothing rejected the duplicate afterwards. The database index
//       is unique(company_id, code), and in SQL a NULL company_id
//       never equals a real one — so the two rows are "different" as
//       far as the index is concerned.
//
//  The consequence was not cosmetic: ChartOfAccount::findByCode()
//  resolves by code, so on a collision the company's own account
//  became unreachable and postings landed on the system account
//  instead. On the live database 182 journal lines had gone to the
//  system 223001 while the company's own 223001 held none.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Services\ChartOfAccountService;
use Tests\Erp\ErpTestCase;
use Tests\Erp\Support\ErpScenario;

class ChartOfAccountCodeTest extends ErpTestCase
{
    private ErpScenario $scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scenario = (new ErpScenario($this->actor))->createCompany();
    }

    private function add(array $data): ChartOfAccount
    {
        return app(ChartOfAccountService::class)->create(
            $this->scenario->companyId,
            array_merge(['use_auto_code' => true, 'name_en' => 'Test Account'], $data),
            $this->actor
        );
    }

    private function parentId(string $code): int
    {
        return (int) ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')->where('code', $code)->value('id');
    }

    /**
     * 111 Tangible Fixed Assets is seeded with six children —
     * 111001 Land through 111006 Computer Equipment. The first
     * account a company adds under it must be 111007, not 111001.
     */
    public function test_auto_code_continues_after_the_seeded_system_children(): void
    {
        $parent = $this->parentId('111');

        $seeded = ChartOfAccount::withoutGlobalScopes()
            ->where('parent_id', $parent)->whereNull('company_id')
            ->orderBy('code')->pluck('code');

        $this->assertContains('111001', $seeded->all(), 'Precondition: 111001 Land is seeded.');
        $this->assertSame('111006', $seeded->last(), 'Precondition: six seeded children.');

        $account = $this->add(['parent_id' => $parent, 'name_en' => 'dd']);

        $this->assertSame(
            '111007', $account->code,
            'The generator must see the seeded siblings. It used to filter on company_id '.
            'alone — system accounts carry NULL — so it restarted at 001 and handed out '.
            '111001, which Land already held.'
        );
    }

    /** And the one after that continues from the company's own account. */
    public function test_auto_code_continues_across_repeated_additions(): void
    {
        $parent = $this->parentId('111');

        $this->assertSame('111007', $this->add(['parent_id' => $parent, 'name_en' => 'First'])->code);
        $this->assertSame('111008', $this->add(['parent_id' => $parent, 'name_en' => 'Second'])->code);
        $this->assertSame('111009', $this->add(['parent_id' => $parent, 'name_en' => 'Third'])->code);
    }

    /**
     * The collision that actually shipped: 223 Overdrafts, where the
     * company's generated codes shadowed the system's by one place —
     * the company's "223001 OD Against Commercial Paper" sat on top of
     * the system's "223001 Clean Overdraft".
     */
    public function test_overdraft_accounts_do_not_shadow_the_system_chart(): void
    {
        $parent = $this->parentId('223');

        $created = collect([
            $this->add(['parent_id' => $parent, 'name_en' => 'OD Against Commercial Paper']),
            $this->add(['parent_id' => $parent, 'name_en' => 'Fully Secured Overdraft']),
        ]);

        foreach ($created as $account) {
            $systemTwin = ChartOfAccount::withoutGlobalScopes()
                ->whereNull('company_id')->where('code', $account->code)->first();

            $this->assertNull(
                $systemTwin,
                "Code {$account->code} is already a system account. On the live database this "
                .'made the company\'s own account unreachable by findByCode(), so 182 journal '
                .'lines went to the system account instead.'
            );
        }
    }

    /**
     * A manually typed code that a system account already holds must
     * be refused. The database index cannot do this — NULL never
     * equals a company id — so the service does.
     */
    public function test_a_manual_code_that_a_system_account_holds_is_refused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/111001.*already used/i');

        $this->add([
            'parent_id'     => $this->parentId('111'),
            'name_en'       => 'Shadow Land',
            'use_auto_code' => false,
            'code'          => '111001',
        ]);
    }

    /** And one the company itself already holds. */
    public function test_a_manual_code_the_company_already_holds_is_refused(): void
    {
        $parent = $this->parentId('111');
        $mine = $this->add(['parent_id' => $parent, 'name_en' => 'Mine']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/already used/i');

        $this->add([
            'parent_id'     => $parent,
            'name_en'       => 'Duplicate',
            'use_auto_code' => false,
            'code'          => $mine->code,
        ]);
    }

    /** A genuinely free manual code still works. */
    public function test_a_free_manual_code_is_accepted(): void
    {
        $account = $this->add([
            'parent_id'     => $this->parentId('111'),
            'name_en'       => 'Leasehold Improvements',
            'use_auto_code' => false,
            'code'          => '111042',
        ]);

        $this->assertSame('111042', $account->code);
    }

    /**
     * The property the whole fix exists to protect: an account the
     * company can see must resolve to exactly one row.
     */
    public function test_every_code_the_company_can_see_resolves_to_one_account(): void
    {
        $this->add(['parent_id' => $this->parentId('111'), 'name_en' => 'dd']);
        $this->add(['parent_id' => $this->parentId('223'), 'name_en' => 'Extra Overdraft']);

        $duplicates = ChartOfAccount::withoutGlobalScopes()
            ->forCompany($this->scenario->companyId)
            ->selectRaw('code, COUNT(*) as n')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('code');

        $this->assertSame(
            [], $duplicates->all(),
            'A code visible to this company must name exactly one account — otherwise '
            .'findByCode() returns whichever row the database offers first.'
        );
    }
}
