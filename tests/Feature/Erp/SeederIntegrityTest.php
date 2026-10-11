<?php

namespace Tests\Feature\Erp;

// ══════════════════════════════════════════════════════════════════
//  SeederIntegrityTest
//
//  The demo-company commands call application services directly, and
//  nothing type-checks those calls until the moment they run — at
//  which point the command's own try/catch swallows the failure and
//  the run still exits 0.
//
//  That is not hypothetical. Both manufacturing seeders passed a
//  string where `User $actor` was required on
//  ProductionOrderService::recordLaborCost() and
//  ::recordOverheadCost(), because those methods stopped taking a
//  manually-typed amount when the Manufacturing Costing Redesign made
//  them derive it from hours x the standard rate, and the callers were
//  never updated. Every production order threw a TypeError, every
//  failure was caught, and the command reported success — which is
//  why the manufacturing tables were empty in a database that had run
//  those seeders (audit finding SEED-002).
//
//  This test compares each call site against the real signature by
//  reflection, so the next signature change breaks a test rather than
//  silently emptying a module.
//
//  @layer    Test
// ══════════════════════════════════════════════════════════════════

use App\Services\Accounting\ProductionOrderService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class SeederIntegrityTest extends TestCase
{
    /**
     * The two methods whose signatures changed, and the argument
     * position at which each expects the acting User.
     */
    private const ACTOR_POSITIONS = [
        'recordLaborCost'    => 2,
        'recordOverheadCost' => 3,
    ];

    private const SEEDERS = [
        'SeedDemoManufacturingCompany.php',
        'SeedDemoManufacturingCompanyV2.php',
    ];

    /** The signature this test is written against still looks that way. */
    public function test_the_production_service_takes_the_actor_where_expected(): void
    {
        foreach (self::ACTOR_POSITIONS as $method => $position) {
            $parameters = (new ReflectionMethod(ProductionOrderService::class, $method))->getParameters();

            $this->assertSame(
                'App\Models\User',
                $parameters[$position]->getType()?->getName(),
                "ProductionOrderService::{$method}() no longer takes User at argument {$position}. "
                .'If the signature changed on purpose, update every seeder call site and this test together.'
            );
        }
    }

    /**
     * No seeder passes an extra amount argument into those methods.
     *
     * A string in the actor's position is the exact shape of the bug:
     * `recordLaborCost($order, $hours, (string) $amount, $actor, $date)`.
     */
    public function test_no_seeder_passes_a_manual_amount_to_the_costing_methods(): void
    {
        foreach (self::SEEDERS as $seeder) {
            // A plain PHPUnit test case, deliberately: this checks
            // source code, not behaviour, so it needs no database and
            // no booted application.
            $path = dirname(__DIR__, 3).'/app/Console/Commands/'.$seeder;

            if (! file_exists($path)) {
                continue;
            }

            $source = file_get_contents($path);

            foreach (array_keys(self::ACTOR_POSITIONS) as $method) {
                preg_match_all('/->'.$method.'\(\s*(.*?)\);/s', $source, $matches);

                foreach ($matches[1] as $arguments) {
                    $this->assertStringNotContainsString(
                        'round(',
                        $arguments,
                        "{$seeder} passes a computed amount into {$method}(), which no longer accepts one. "
                        .'This throws a TypeError that the command swallows, so the seeder reports success '
                        .'while producing no manufacturing data at all (audit finding SEED-002).'
                    );
                }
            }
        }
    }
}
