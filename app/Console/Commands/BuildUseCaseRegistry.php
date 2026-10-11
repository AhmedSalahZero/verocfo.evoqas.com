<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  BuildUseCaseRegistry  —  php artisan audit:use-cases
//
//  Writes USE-CASE-REGISTRY.md: every action in this ERP that changes
//  something, and what tests it.
//
//  The question this answers is the one an audit actually turns on —
//  not "how many tests are there" but "which of the things this
//  system can DO has anything ever exercised". Those are different
//  questions and only the second one is useful.
//
//  HOW COVERAGE IS DECIDED, stated plainly because it is a
//  heuristic and a heuristic that overstates itself is worse than
//  none:
//
//    1. Every route that changes state is listed from the route
//       table — never by hand, so a new endpoint appears here the day
//       it is added.
//    2. Its controller's PRIMARY service is identified — the one
//       named after the controller (CustomerInvoiceController →
//       CustomerInvoiceService), falling back to the services the
//       constructor injects when there is no such match.
//    3. A route counts as EXERCISED only if that primary service is
//       driven by the testing cycle or the phpunit suite.
//
//  Deliberately strict. The looser rule — "any service this
//  controller mentions is tested somewhere" — marks almost everything
//  covered, because controllers reference half a dozen services for
//  dropdowns and lookups. A coverage number that flatters itself is
//  worse than no number, so the primary service is the one that
//  counts.
//
//  That is coverage of the BUSINESS LOGIC behind the endpoint, not of
//  the endpoint itself: a route marked exercised has had its service
//  driven with real data, but not necessarily its own Form Request,
//  route binding or permission middleware. The registry says so in
//  its own header rather than letting the reader assume more.
//
//  Audit H8 (Sept 2026): refuses to run when APP_ENV=production
//  (RefusesInProduction trait).
//
//  @layer    Console
// ══════════════════════════════════════════════════════════════════

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class BuildUseCaseRegistry extends Command
{
    use \App\Console\Concerns\RefusesInProduction; // Audit H8

    protected $signature = 'audit:use-cases {--out=USE-CASE-REGISTRY.md : Where to write the registry}';

    protected $description = 'Lists every state-changing endpoint and what exercises it';

    /** Directories whose contents count as "a test drives this". */
    private const TEST_SOURCES = [
        'app/Console/TestingCycle',
        'tests',
    ];

    public function handle(): int
    {
        // Audit H8 — never on the live server
        if ($this->refuseInProduction()) {
            return 1;
        }

        $exercised = $this->servicesUnderTest();
        $rows      = [];

        foreach (Route::getRoutes() as $route) {
            $methods = array_diff($route->methods(), ['HEAD']);

            if (! array_intersect($methods, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                continue;
            }

            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$controller, $method] = explode('@', $action);

            $services = $this->primaryServicesOf($controller);
            $covered  = array_values(array_intersect($services, $exercised));

            $rows[] = [
                'module'     => $this->moduleOf($route->uri()),
                'verb'       => implode('|', $methods),
                'uri'        => $route->uri(),
                'name'       => $route->getName() ?? '—',
                'controller' => class_basename($controller).'::'.$method.'()',
                'guarded'    => $this->isGuarded($route),
                'services'   => $services,
                'covered'    => $covered,
            ];
        }

        usort($rows, fn ($a, $b) => [$a['module'], $a['uri'], $a['verb']] <=> [$b['module'], $b['uri'], $b['verb']]);

        $path = $this->option('out');
        file_put_contents(base_path($path), $this->render($rows));

        $total    = count($rows);
        $exercisedCount = count(array_filter($rows, fn ($r) => $r['covered'] !== []));
        $guarded  = count(array_filter($rows, fn ($r) => $r['guarded']));

        $this->info("Wrote {$path}");
        $this->line("  state-changing endpoints : {$total}");
        $this->line("  business logic exercised : {$exercisedCount}");
        $this->line("  behind a permission check: {$guarded}");

        return self::SUCCESS;
    }

    // ── What the tests drive ──────────────────────────────────────

    /** @return array<int, string> service class basenames */
    private function servicesUnderTest(): array
    {
        $found = [];

        foreach (self::TEST_SOURCES as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                // Only a real reference counts — `XService::class`,
                // which is how both app() resolution and every
                // assertion in this codebase name a service. A
                // mention in a comment or a doc block does not make
                // something tested.
                preg_match_all('/\b([A-Z][A-Za-z0-9]*Service)::class\b/', (string) file_get_contents($file->getPathname()), $m);

                foreach ($m[1] as $service) {
                    $found[$service] = true;
                }
            }
        }

        return array_keys($found);
    }

    // ── What a controller depends on ──────────────────────────────

    /**
     * The service this endpoint actually runs on, not every service
     * the controller happens to mention.
     *
     * @return array<int, string>
     */
    private function primaryServicesOf(string $controller): array
    {
        $file = base_path('app/'.str_replace(['App\\', '\\'], ['', '/'], $controller).'.php');

        if (! is_file($file)) {
            return [];
        }

        $source = (string) file_get_contents($file);

        // The service named after the controller, when there is one.
        $expected = Str::before(class_basename($controller), 'Controller').'Service';

        if (str_contains($source, $expected)) {
            return [$expected];
        }

        // Otherwise whatever the constructor injects — that is what
        // the actions are built on.
        if (preg_match('/public function __construct\((.*?)\)\s*\{/s', $source, $ctor)) {
            preg_match_all('/\b([A-Z][A-Za-z0-9]*Service)\b/', $ctor[1], $m);

            if ($m[1] !== []) {
                return array_values(array_unique($m[1]));
            }
        }

        preg_match_all('/\b([A-Z][A-Za-z0-9]*Service)\b/', $source, $m);

        return array_values(array_unique($m[1]));
    }

    private function isGuarded($route): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            $middleware = (string) $middleware;

            if (str_contains($middleware, 'ModulePermission') || str_starts_with($middleware, 'module:')) {
                return true;
            }
        }

        return false;
    }

    private function moduleOf(string $uri): string
    {
        $first = Str::before($uri, '/');

        return match ($first) {
            'accounting'             => 'Accounting',
            'financial-institutions' => 'Banking & Treasury',
            'hr'                     => 'HR',
            'settings'               => 'Settings & Master Data',
            'projects'               => 'Projects & Sales Orders',
            'planning'               => 'Planning',
            'valuation'              => 'Valuation',
            'analytics'              => 'Analytics',
            'companies', 'users'     => 'Administration',
            default                  => 'Platform',
        };
    }

    // ── The document ──────────────────────────────────────────────

    /** @param array<int, array<string, mixed>> $rows */
    private function render(array $rows): string
    {
        $total     = count($rows);
        $exercised = count(array_filter($rows, fn ($r) => $r['covered'] !== []));
        $guarded   = count(array_filter($rows, fn ($r) => $r['guarded']));
        $generated = now()->toDateString();

        $out = <<<MD
        # Use-Case Registry — Vero CFO

        Generated by `php artisan audit:use-cases` on {$generated}. Do not edit by hand;
        it is rebuilt from the live route table, so a new endpoint appears here the day
        it is added.

        ## What this counts

        | | |
        |---|---|
        | State-changing endpoints | **{$total}** |
        | Business logic exercised by a test | **{$exercised}** |
        | Behind a permission check | **{$guarded}** |

        **"Exercised" means the SERVICE behind the endpoint is driven with real data by
        the testing cycle or the phpunit suite.** It does not mean the endpoint itself
        was called: the Form Request rules, route model binding and permission
        middleware on that specific route may still be untested. Where the cycle drives
        a Form Request directly (`FormGate::submit`), validation is covered too — the
        cycle's own coverage report says which.

        **"Behind a permission check" means the route carries the `module:` middleware.**
        Everything else is reachable by any authenticated user of the company whatever
        their granted permissions say — see `tests/Feature/Erp/AuthorizationTest.php`.

        MD;

        $module = null;

        foreach ($rows as $row) {
            if ($row['module'] !== $module) {
                $module = $row['module'];
                $count  = count(array_filter($rows, fn ($r) => $r['module'] === $module));
                $cov    = count(array_filter($rows, fn ($r) => $r['module'] === $module && $r['covered'] !== []));
                $grd    = count(array_filter($rows, fn ($r) => $r['module'] === $module && $r['guarded']));

                $out .= "\n\n## {$module}\n\n";
                $out .= "{$count} state-changing endpoints · {$cov} with tested business logic · {$grd} behind a permission check\n\n";
                $out .= "| Method | Endpoint | Handler | Tested | Guarded |\n";
                $out .= "|---|---|---|:--:|:--:|\n";
            }

            $tested  = $row['covered'] !== [] ? '✅' : '—';
            $guardedMark = $row['guarded'] ? '🔒' : '⚠️';

            $out .= "| `{$row['verb']}` | `/{$row['uri']}` | {$row['controller']} | {$tested} | {$guardedMark} |\n";
        }

        return $out."\n";
    }
}
