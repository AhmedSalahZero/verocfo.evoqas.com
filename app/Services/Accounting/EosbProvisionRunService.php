<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  EosbProvisionRunService
//
//  A separate, dedicated monthly run from Payroll Run — confirmed
//  with Mahmoud, September 2026 (not folded into Payroll Run's own
//  posting). See migration 000235 header for the full design.
//
//  create() reads every EOSB-enrolled employee
//  (EmployeePayrollProfile::isEnrolledInEosb()) and snapshots their
//  base salary (via salaryAsOfDate(), same helper Payroll Run uses)
//  and current eosb_rate_percent, computing:
//    provision_amount = base_salary × eosb_rate_percent / 100
//
//  post() creates exactly ONE journal entry, no per-category split
//  (unlike Payroll Run's 521/531/541) — 542/241 are single,
//  company-wide accounts:
//    Dr 542 End of Service Benefits Expense   sum(provision_amount)
//        Cr 241 End of Service Benefits Provision   sum(provision_amount)
//
//  Manufacturing employees ARE included in this single posting, same
//  as everyone else — confirmed with Mahmoud this is the one place
//  Manufacturing does NOT get Payroll Run's "no fresh expense, settle
//  via WIP/237 instead" treatment. EOSB is a real future obligation
//  regardless of department, not a product cost.
//
//  @layer    Service
//  @depends  EosbProvisionRun, EosbProvisionRunLine,
//            EmployeePayrollProfile, ChartOfAccount, JournalEntry,
//            JournalEntryService, FiscalYearService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\EmployeePayrollProfile;
use App\Models\EosbProvisionRun;
use App\Models\EosbProvisionRunLine;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class EosbProvisionRunService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
    ) {}

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return EosbProvisionRun::when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->withCount('lines')
            ->withSum('lines as provision_total', 'provision_amount')
            ->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    public function create(int $companyId, int $periodYear, int $periodMonth, string $runDate, User $actor): EosbProvisionRun
    {
        $exists = EosbProvisionRun::where('company_id', $companyId)
            ->where('period_year', $periodYear)->where('period_month', $periodMonth)
            ->exists();

        if ($exists) {
            throw new \DomainException("An EOSB Provision Run for {$periodMonth}/{$periodYear} already exists.");
        }

        // Same reasoning as Payroll Run — the salary snapshotted is
        // whichever revision was effective by the LAST day of this
        // run's period.
        $periodEnd = \Carbon\Carbon::create($periodYear, $periodMonth, 1)->endOfMonth()->toDateString();

        return DB::transaction(function () use ($companyId, $periodYear, $periodMonth, $runDate, $periodEnd, $actor) {
            $run = EosbProvisionRun::create([
                'company_id'   => $companyId,
                'period_year'  => $periodYear,
                'period_month' => $periodMonth,
                'run_date'     => $runDate,
                'status'       => EosbProvisionRun::STATUS_DRAFT,
                'created_by'   => $actor->id,
            ]);

            $profiles = EmployeePayrollProfile::where('company_id', $companyId)->active()->get()
                ->filter->isEnrolledInEosb();

            foreach ($profiles as $profile) {
                $salaryForPeriod = $profile->salaryAsOfDate($periodEnd);
                $provisionAmount = bcdiv(bcmul($salaryForPeriod, (string) $profile->eosb_rate_percent, 4), '100', 2);

                EosbProvisionRunLine::create([
                    'company_id'                   => $companyId,
                    'eosb_provision_run_id'        => $run->id,
                    'partner_id'                   => $profile->partner_id,
                    'employee_payroll_profile_id'  => $profile->id,
                    'base_salary'                  => $salaryForPeriod,
                    'eosb_rate_percent'             => $profile->eosb_rate_percent,
                    'currency_id'                   => $profile->currency_id,
                    'provision_amount'              => $provisionAmount,
                ]);
            }

            AuditLog::record($actor, 'eosb_provision_run.created', $run, null, $run->fresh(['lines'])->toArray());

            return $run->fresh(['lines.partner']);
        });
    }

    public function post(EosbProvisionRun $run, User $actor): EosbProvisionRun
    {
        $this->assertRunDraft($run);

        if ($this->fiscalYearService->isPeriodClosed($run->company_id, $run->run_date)) {
            throw new \DomainException('This EOSB Provision Run\'s accounting period is closed — it cannot be posted.');
        }

        $run->loadMissing('lines');

        if ($run->lines->isEmpty()) {
            throw new \DomainException('This EOSB Provision Run has no enrolled employees — nothing to post.');
        }

        return DB::transaction(function () use ($run, $actor) {
            $eosbExpense = $this->resolveSystemAccount($run->company_id, '542', 'End of Service Benefits Expense');
            $eosbProvision = $this->resolveSystemAccount($run->company_id, '241', 'End of Service Benefits Provision');

            // Audit C8 FIX (Sept 2026): each employee's provision is in
            // HIS salary currency — it used to be summed and posted 1:1
            // into the EGP ledger. Now each line is converted at its
            // currency's rate on the run date (FxService; refuses if a
            // rate is missing; EGP lines use 1).
            $fx = app(FxService::class);
            $total = '0.00';
            foreach ($run->lines as $line) {
                $rate  = $fx->rateOn($run->company_id, $line->currency_id ? (int) $line->currency_id : null, (string) $run->run_date);
                $total = bcadd($total, $fx->toFunctional((string) $line->provision_amount, $rate), 2);
            }

            $journalEntry = $this->journalEntryService->post(
                companyId: $run->company_id,
                entryDate: $run->run_date,
                lines: [
                    ['chart_of_account_id' => $eosbExpense->id, 'debit' => $total, 'credit' => '0', 'description' => "EOSB Provision — {$run->periodLabel()}"],
                    ['chart_of_account_id' => $eosbProvision->id, 'debit' => '0', 'credit' => $total, 'description' => "EOSB Provision — {$run->periodLabel()}"],
                ],
                sourceType: 'eosb_provision_run',
                sourceId: $run->id,
                narration: "EOSB Provision Run — {$run->periodLabel()}",
                actor: $actor,
            );

            $run->update([
                'status'           => EosbProvisionRun::STATUS_POSTED,
                'journal_entry_id' => $journalEntry->id,
                'posted_by'        => $actor->id,
                'posted_at'        => now(),
            ]);

            AuditLog::record($actor, 'eosb_provision_run.posted', $run, null, $run->fresh()->toArray());

            return $run->fresh(['lines', 'journalEntry.lines']);
        });
    }

    public function setToDraft(EosbProvisionRun $run, User $actor): EosbProvisionRun
    {
        if (! $run->isPosted()) {
            throw new \DomainException('Only a posted EOSB Provision Run can be set back to draft.');
        }
        if ($this->fiscalYearService->isPeriodClosed($run->company_id, $run->run_date)) {
            throw new \DomainException('This EOSB Provision Run\'s accounting period is closed — it cannot be reopened.');
        }

        return DB::transaction(function () use ($run, $actor) {
            $before = $run->toArray();

            if ($run->journal_entry_id) {
                $entry = JournalEntry::findOrFail($run->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — EOSB Provision Run '.$run->periodLabel());
            }

            $run->update([
                'status'           => EosbProvisionRun::STATUS_DRAFT,
                'journal_entry_id' => null,
                'posted_by'        => null,
                'posted_at'        => null,
            ]);

            AuditLog::record($actor, 'eosb_provision_run.set_to_draft', $run, $before, $run->fresh()->toArray());

            return $run->fresh(['lines']);
        });
    }

    // ── Internal helpers ──────────────────────────────────────────

    private function assertRunDraft(EosbProvisionRun $run): void
    {
        if (! $run->isDraft()) {
            throw new \DomainException('This EOSB Provision Run is no longer a draft — reopen it first (Set to Draft) before editing.');
        }
    }

    private function resolveSystemAccount(int $companyId, string $code, string $label): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code} ({$label}) — check the seeder.");
    }
}
