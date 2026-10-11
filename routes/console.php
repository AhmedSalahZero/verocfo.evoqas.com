<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

// ══════════════════════════════════════════════════════════════════
//  VERO CFO — Console Routes & Scheduled Jobs
//
//  Scheduled jobs are registered here as each module stream is built.
//  Nothing is registered until the Job class exists.
//
//  All scheduled jobs must:
//    - Implement ShouldBeUnique (bible Rule 10.1)
//    - Use ->timezone('Africa/Cairo') (bible Rule 10.3)
//    - Use ->withoutOverlapping() as secondary protection (bible Rule 10.4)
//
//  Jobs will be added here in this order as modules are built:
//
//  LEASE AMORTIZATION ENGINE (built July 2026 — the first scheduled
//  job actually registered in this file):
//    LeaseNotifyDuePaymentsJob   → dailyAt('07:00')   Lease payment due reminders
//
//  BANKING MODULE:
//    FacilityExpiryAlertJob      → dailyAt('07:00')   LG/LC expiry warnings
//    LgCommissionAccrualJob      → dailyAt('07:15')   LG recurring commission accrual (built)
//    FacilityDailyAccrualJob     → dailyAt('07:30')   OD facility daily balance snapshot (built Sept 2026)
//
//  ACCOUNTING MODULE:
//    ARAgingSnapshotJob          → dailyAt('06:00')   AR aging daily snapshot
//    APAgingSnapshotJob          → dailyAt('06:00')   AP aging daily snapshot
//    RecurringJournalJob         → dailyAt('00:01')   Auto-post recurring journals
//    PeriodCloseReminderJob      → monthlyOn(25,'09:00') Period close reminder
//    FxRevaluationReminderJob    → lastDayOfMonth('09:00') FX revaluation reminder
//
//  PLANNING MODULE:
//    BudgetVarianceAlertJob      → dailyAt('08:00')   Budget variance alerts
// ══════════════════════════════════════════════════════════════════

use App\Jobs\LeaseNotifyDuePaymentsJob;
use App\Jobs\LgCommissionAccrualJob;
use App\Jobs\FacilityDailyAccrualJob;
use App\Jobs\FacilityMonthEndJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new LeaseNotifyDuePaymentsJob())
    ->dailyAt('07:00')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping();

// LG Issuance — recurring commission accrual (August 2026). Runs
// after the lease reminder job, same time-of-day convention, before
// business hours in Cairo.
Schedule::job(new LgCommissionAccrualJob())
    ->dailyAt('07:15')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping();

// Facility Settlement Engine — daily balance accrual (September
// 2026). Gives every OD-family facility a snapshot for today even on
// a quiet day, so interest visibly accrues before month-end posting.
// Runs last of the three — pure reporting, no GL posting, so order
// relative to the other two doesn't matter functionally, but keeping
// it after them matches this file's existing register-in-build-order
// convention.
Schedule::job(new FacilityDailyAccrualJob())
    ->dailyAt('07:30')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping();

// Facility / lease month-end entries (Audit M21–M23, Oct 2026) —
// overdraft + loan interest accruals and current-portion reclasses.
// Runs every night but only acts on the last day of the month; the
// 23:40 slot is after the day's last business postings and the
// reversal is dated the 1st of the next month by the service itself.
Schedule::job(new FacilityMonthEndJob())
    ->dailyAt('23:40')
    ->timezone('Africa/Cairo')
    ->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
