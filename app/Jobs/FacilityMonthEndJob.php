<?php

namespace App\Jobs;

// ══════════════════════════════════════════════════════════════════
//  FacilityMonthEndJob
//
//  Audit M21–M23 (Oct 2026) — posts the month-end entries for
//  facilities and leases (overdraft interest accrual, loan interest
//  accrual, current-portion reclasses) on the LAST DAY of every month,
//  for every active company. See FacilityPeriodEndService for what is
//  posted and why.
//
//  It is scheduled every night but does nothing unless today is a
//  month-end, so a missed night is simply repeated the next evening
//  only on month-end days; to catch up a missed month-end, run
//  `php artisan facilities:month-end --date=YYYY-MM-DD`.
//
//  Each company runs inside TenantContext::runAs() with an active
//  admin of THAT company as the posting user (the same rule as the LG
//  commission job); a company with no active admin is skipped. One
//  company failing is logged and does not stop the others.
//
//  @layer    Job
//  @depends  FacilityPeriodEndService
// ══════════════════════════════════════════════════════════════════

use App\Models\Company;
use App\Models\User;
use App\Services\FinancialInstitutions\FacilityPeriodEndService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FacilityMonthEndJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?string $date = null) {}

    public function uniqueId(): string
    {
        return 'facility-month-end';
    }

    public function handle(FacilityPeriodEndService $service): void
    {
        $date = $this->date
            ? Carbon::parse($this->date)->toDateString()
            : now('Africa/Cairo')->toDateString();

        if (! Carbon::parse($date)->isSameDay(Carbon::parse($date)->endOfMonth())) {
            return; // not a month-end — nothing to do tonight
        }

        Company::withoutGlobalScopes()->where('is_active', true)->orderBy('id')
            ->each(function (Company $company) use ($service, $date) {
                try {
                    $actor = User::withoutGlobalScopes()
                        ->where('company_id', $company->id)
                        ->where('role', User::ROLE_COMPANY_ADMIN)
                        ->where('is_active', true)
                        ->orderBy('id')->first();

                    if (! $actor) {
                        Log::warning("FacilityMonthEndJob: company #{$company->id} has no active admin — skipped.");
                        return;
                    }

                    $result = TenantContext::runAs($company->id, fn () => $service->runForCompany($company->id, $date, $actor));

                    foreach ($result['failed'] as $failure) {
                        Log::warning("FacilityMonthEndJob company #{$company->id}: {$failure}");
                    }
                } catch (\Throwable $e) {
                    Log::error("FacilityMonthEndJob failed for company #{$company->id}: ".$e->getMessage());
                }
            });
    }
}
