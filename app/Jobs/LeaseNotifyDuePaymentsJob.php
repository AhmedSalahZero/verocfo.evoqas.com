<?php

namespace App\Jobs;

// ══════════════════════════════════════════════════════════════════
//  LeaseNotifyDuePaymentsJob
//
//  Lease Amortization Engine — Piece 3 (July 2026). Runs daily,
//  across every company, and creates a notification for each lease
//  payment due in the next 7 days that hasn't already been notified.
//  Never posts anything or touches money — informational only,
//  confirmed with Mahmoud (payments are always user-recorded, never
//  automatic).
//
//  This is the first scheduled job built in the app — follows the
//  conventions already documented in routes/console.php:
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
//  @depends  LeaseAmortizationService, Company
// ══════════════════════════════════════════════════════════════════

use App\Models\Company;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use App\Services\FinancialInstitutions\LeaseAmortizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class LeaseNotifyDuePaymentsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function uniqueId(): string
    {
        return 'lease-notify-due-payments';
    }

    public function handle(LeaseAmortizationService $amortization): void
    {
        Company::withoutGlobalScopes()->where('is_active', true)->orderBy('id')
            ->each(function (Company $company) use ($amortization) {
                try {
                    TenantContext::runAs($company->id, fn () => $amortization->notifyDuePayments($company->id));
                } catch (\Throwable $e) {
                    Log::error("LeaseNotifyDuePaymentsJob failed for company #{$company->id}: ".$e->getMessage());
                }
            });
    }
}
