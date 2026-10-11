<?php

namespace App\Console\Commands;

// ══════════════════════════════════════════════════════════════════
//  FacilitiesMonthEnd  (php artisan facilities:month-end)
//
//  Audit M21–M23 (Oct 2026) — runs the facility / lease month-end
//  entries by hand, e.g. to catch up a month-end the nightly job
//  missed, or to run them for one company only.
//
//    php artisan facilities:month-end --date=2026-09-30
//    php artisan facilities:month-end --date=2026-09-30 --company=3
//
//  Also prints any overdraft whose "outstanding" figure disagrees
//  with the general ledger (M23). Safe to run twice: an item already
//  posted for that month is skipped.
//
//  @layer    Command
//  @depends  FacilityPeriodEndService
// ══════════════════════════════════════════════════════════════════

use App\Models\Company;
use App\Models\User;
use App\Services\FinancialInstitutions\FacilityPeriodEndService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

class FacilitiesMonthEnd extends Command
{
    protected $signature = 'facilities:month-end {--date= : Month-end date, YYYY-MM-DD (the last day of a month)} {--company= : One company id only}';

    protected $description = 'Post month-end interest accruals and current-portion entries for facilities and leases';

    public function handle(FacilityPeriodEndService $service): int
    {
        $date = $this->option('date') ?: now('Africa/Cairo')->endOfMonth()->toDateString();
        $exit = self::SUCCESS;

        $companies = Company::withoutGlobalScopes()->where('is_active', true)
            ->when($this->option('company'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('id')->get();

        foreach ($companies as $company) {
            $actor = User::withoutGlobalScopes()->where('company_id', $company->id)
                ->where('role', User::ROLE_COMPANY_ADMIN)->where('is_active', true)->orderBy('id')->first();

            if (! $actor) {
                $this->warn("Company #{$company->id}: no active admin — skipped.");
                continue;
            }

            try {
                $result = TenantContext::runAs($company->id, fn () => $service->runForCompany($company->id, $date, $actor));
                $diffs  = TenantContext::runAs($company->id, fn () => $service->ledgerReconciliation($company->id, $date));
            } catch (\Throwable $e) {
                $this->error("Company #{$company->id}: ".$e->getMessage());
                $exit = self::FAILURE;
                continue;
            }

            $this->info("Company #{$company->id} — {$date}: ".count($result['posted']).' posted, '.count($result['skipped']).' nothing to post / already done, '.count($result['failed']).' failed.');
            foreach ($result['posted'] as $line) {
                $this->line("  + {$line}");
            }
            foreach ($result['failed'] as $line) {
                $this->warn("  ! {$line}");
                $exit = self::FAILURE;
            }
            foreach ($diffs as $d) {
                if ($d['compared']) {
                    $this->warn("  ≠ {$d['reference']}: outstanding {$d['outstanding']} vs ledger {$d['ledger']} (difference {$d['difference']})");
                }
            }
        }

        return $exit;
    }
}
