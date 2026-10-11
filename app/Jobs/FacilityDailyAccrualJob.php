<?php

namespace App\Jobs;

// ══════════════════════════════════════════════════════════════════
//  FacilityDailyAccrualJob
//
//  Facility Settlement Engine (Building Bible Rule 34) — the daily
//  job flagged as not-yet-built when the engine first shipped
//  (2026-09), now built at Mahmoud's request to make OD facility
//  numbers fully, continuously correct.
//
//  Every active OD-family facility (Clean OD, Commercial Paper OD,
//  Contract-Assignment OD, Fully Secured OD), across every company,
//  gets a facility_daily_balances row for today — even if nothing
//  happened today. Without this, a quiet day has no snapshot at all,
//  so interest doesn't visibly accrue day by day before it's posted
//  at month-end, and the monthly theoretical-vs-actual comparison
//  shown on the "Post a Charge" screen undercounts.
//
//  Does NOT post anything to the GL — purely a derived reporting
//  snapshot (see FacilitySettlementEngine::updateDailyBalance()
//  docblock). The facility's real outstanding_balance (what
//  available room is actually calculated from) is a separate running
//  total, already correct at all times regardless of this job.
//
//  Follows the conventions already established by
//  LeaseNotifyDuePaymentsJob / LgCommissionAccrualJob (see
//  routes/console.php):
//    - ShouldBeUnique (Building Bible Rule 10.1)
//    - registered with ->timezone('Africa/Cairo') (Rule 10.3)
//    - ->withoutOverlapping() as secondary protection (Rule 10.4)
//
//  AUDIT H9 FIX (Sept 2026): jobs have no logged-in user, so the
//  automatic company filter never applied. This job now loops ACTIVE
//  companies only and runs each one inside TenantContext::runAs(), so
//  every query is filtered to that company. One company failing is
//  logged and skipped — it no longer stops the run for the others.
//
//  @layer    Job
//  @depends  FacilitySettlementEngine
// ══════════════════════════════════════════════════════════════════

use App\Models\Company;
use App\Services\FinancialInstitutions\FacilitySettlementEngine;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FacilityDailyAccrualJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function uniqueId(): string
    {
        return 'facility-daily-accrual';
    }

    public function handle(FacilitySettlementEngine $settlementEngine): void
    {
        Company::withoutGlobalScopes()->where('is_active', true)->orderBy('id')
            ->each(function (Company $company) use ($settlementEngine) {
                try {
                    TenantContext::runAs($company->id, fn () => $settlementEngine->accrueDailyForCompany($company->id));
                } catch (\Throwable $e) {
                    Log::error("FacilityDailyAccrualJob failed for company #{$company->id}: ".$e->getMessage());
                }
            });
    }
}
