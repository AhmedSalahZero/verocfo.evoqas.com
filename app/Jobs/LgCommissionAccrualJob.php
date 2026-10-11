<?php

namespace App\Jobs;

// ══════════════════════════════════════════════════════════════════
//  LgCommissionAccrualJob
//
//  Part of the August 2026 LG Issuance real-GL-posting fix (confirmed
//  with Mahmoud). The FIRST commission accrual on an LG posts for
//  free at issuance (LgIssuanceService::create()) — every accrual
//  after that (per the LG's own commission_interval — monthly /
//  quarterly / semi_annual / annual) is driven by
//  next_commission_accrual_date and posted here, daily, across every
//  company. Each due accrual posts its own real journal entry
//  (Dr 553 Fees / Cr the fees/commission bank account) for the LG's
//  CURRENT commission_amount — already kept correct by decrease()'s
//  recalculation — then advances the schedule by one interval.
//  Catch-up safe (see LgIssuanceService::postDueAccrualsForIssuance()
//  for the per-issuance loop and its runaway guard).
//
//  Follows the conventions already established by
//  LeaseNotifyDuePaymentsJob (the first scheduled job in this app —
//  see routes/console.php):
//    - ShouldBeUnique (Building Bible Rule 10.1)
//    - registered with ->timezone('Africa/Cairo') (Rule 10.3)
//    - ->withoutOverlapping() as secondary protection (Rule 10.4)
//
//  Unlike LeaseNotifyDuePaymentsJob, this one DOES post real money to
//  the GL — LgIssuanceService::postDueCommissionAccruals(null) already
//  scopes across every company itself (LgIssuance::withoutGlobalScopes()
//  + a company_id filter per row), so this job stays a thin wrapper,
//  not a per-company loop — one accrual should never double-post
//  because of two different iteration styles disagreeing.
//
//  AUDIT H9 FIX (Sept 2026): jobs have no logged-in user, so the
//  automatic company filter never applied. This job now loops ACTIVE
//  companies only and runs each one inside TenantContext::runAs(), so
//  every query is filtered to that company. One company failing is
//  logged and skipped — it no longer stops the run for the others.
//
//  @layer    Job
//  @depends  LgIssuanceService
// ══════════════════════════════════════════════════════════════════

use App\Models\Company;
use App\Services\FinancialInstitutions\LgIssuanceService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LgCommissionAccrualJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function uniqueId(): string
    {
        return 'lg-commission-accrual';
    }

    public function handle(LgIssuanceService $lgIssuanceService): void
    {
        Company::withoutGlobalScopes()->where('is_active', true)->orderBy('id')
            ->each(function (Company $company) use ($lgIssuanceService) {
                try {
                    TenantContext::runAs($company->id, fn () => $lgIssuanceService->postDueCommissionAccruals($company->id));
                } catch (\Throwable $e) {
                    Log::error("LgCommissionAccrualJob failed for company #{$company->id}: ".$e->getMessage());
                }
            });
    }
}
