<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  EosbPayoutService   (audit M-36)
//
//  Pays an employee's end-of-service benefit when he leaves.
//  Until now the monthly EOSB runs only BUILT the provision (241);
//  nothing released it. EOSB is optional: only employees enrolled in
//  it (an EOSB rate on the payroll profile) have a provision.
//
//  Entry:
//    Dr 241 EOSB Provision         the part covered by the provision
//    Dr 542 EOSB Expense           true-up, if more is owed than provided
//    Cr 239 Salaries Payable       the amount owed to the employee
//  The employee is then paid like any salary (Salaries Payable settles
//  through the normal payment). If the provision was bigger than the
//  amount owed, the unused part stays in 241 (nothing is released).
//
//  @layer    Service
//  @depends  JournalEntryService, FiscalYearService, FxService, EosbProvisionRunLine, EosbPayout
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\EmployeePayrollProfile;
use App\Models\EosbPayout;
use App\Models\EosbProvisionRun;
use App\Models\EosbProvisionRunLine;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Support\Facades\DB;

class EosbPayoutService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
    ) {}

    /** Provision still held for one employee (functional currency) = posted provision lines − released by live payouts. */
    public function provisionBalance(int $companyId, int $partnerId): string
    {
        $fx = app(FxService::class);
        $lines = EosbProvisionRunLine::withoutGlobalScopes()
            ->where('eosb_provision_run_lines.company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->join('eosb_provision_runs as r', 'r.id', '=', 'eosb_provision_run_lines.eosb_provision_run_id')
            ->where('r.status', EosbProvisionRun::STATUS_POSTED)
            ->get(['eosb_provision_run_lines.provision_amount', 'eosb_provision_run_lines.currency_id', 'r.run_date']);

        $total = '0.00';
        foreach ($lines as $l) {
            $rate  = $fx->rateOn($companyId, $l->currency_id ? (int) $l->currency_id : null, (string) $l->run_date);
            $total = bcadd($total, $fx->toFunctional((string) $l->provision_amount, $rate), 2);
        }

        $released = (string) (EosbPayout::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('partner_id', $partnerId)->live()->sum('provision_released') ?: '0');

        $balance = bcsub($total, $released, 2);

        return bccomp($balance, '0', 2) < 0 ? '0.00' : $balance;
    }

    public function pay(int $companyId, int $partnerId, string $payoutDate, string $payableAmount, ?string $notes, User $actor): EosbPayout
    {
        if (bccomp($payableAmount, '0', 2) <= 0) {
            throw new \DomainException('The end-of-service amount must be greater than zero.');
        }
        $profile = EmployeePayrollProfile::withoutGlobalScopes()->where('company_id', $companyId)->where('partner_id', $partnerId)->first();
        if (! $profile || ! $profile->isEnrolledInEosb()) {
            throw new \DomainException('This employee is not enrolled in end-of-service benefits.');
        }
        if ($this->fiscalYearService->isPeriodClosed($companyId, $payoutDate)) {
            throw new \DomainException('The accounting period of the payout date is closed.');
        }

        return DB::transaction(function () use ($companyId, $partnerId, $profile, $payoutDate, $payableAmount, $notes, $actor) {
            $balance  = $this->provisionBalance($companyId, $partnerId);
            $released = bccomp($payableAmount, $balance, 2) <= 0 ? $payableAmount : $balance;
            $trueUp   = bcsub($payableAmount, $released, 2);

            $provision = $this->account($companyId, '241', 'End of Service Benefits Provision');
            $expense   = $this->account($companyId, '542', 'End of Service Benefits Expense');
            $payable   = $this->account($companyId, '239', 'Salaries Payable');

            $lines = [];
            if (bccomp($released, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $provision->id, 'debit' => $released, 'credit' => '0', 'description' => 'EOSB payout — provision released'];
            }
            if (bccomp($trueUp, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $expense->id, 'debit' => $trueUp, 'credit' => '0', 'description' => 'EOSB payout — true-up'];
            }
            $lines[] = ['chart_of_account_id' => $payable->id, 'debit' => '0', 'credit' => $payableAmount, 'description' => 'EOSB payout — owed to employee'];

            $entry = $this->journalEntryService->post(
                companyId: $companyId, entryDate: $payoutDate, lines: $lines,
                sourceType: 'eosb_payout', sourceId: null,
                narration: 'End-of-service benefit payout', actor: $actor,
            );

            $payout = EosbPayout::create([
                'company_id' => $companyId, 'partner_id' => $partnerId, 'employee_payroll_profile_id' => $profile->id,
                'payout_date' => $payoutDate, 'payable_amount' => $payableAmount,
                'provision_released' => $released, 'true_up_expense' => $trueUp,
                'journal_entry_id' => $entry->id, 'notes' => $notes, 'created_by' => $actor->id,
            ]);

            AuditLog::record($actor, 'eosb_payout.created', $payout, null, $payout->toArray());

            return $payout;
        });
    }

    public function reverse(EosbPayout $payout, User $actor): void
    {
        if ($payout->reversed_at) {
            throw new \DomainException('This payout is already reversed.');
        }
        if ($this->fiscalYearService->isPeriodClosed($payout->company_id, (string) $payout->payout_date)) {
            throw new \DomainException('The accounting period of this payout is closed — it cannot be reversed.');
        }

        DB::transaction(function () use ($payout, $actor) {
            $before = $payout->toArray();
            if ($payout->journal_entry_id) {
                $this->journalEntryService->reverse(JournalEntry::findOrFail($payout->journal_entry_id), $actor, 'EOSB payout reversed');
            }
            $payout->update(['reversed_at' => now()]);
            AuditLog::record($actor, 'eosb_payout.reversed', $payout, $before, $payout->fresh()->toArray());
        });
    }

    private function account(int $companyId, string $code, string $label): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code} ({$label}) — check the seeder.");
    }
}
