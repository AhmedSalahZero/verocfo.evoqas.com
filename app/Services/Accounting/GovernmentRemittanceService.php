<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  GovernmentRemittanceService
//
//  Government Remittance Tracking (2026-09) — mirrors
//  AdvancesSettlementsService, but for LIABILITIES instead of
//  RECEIVABLES: Payroll Run credits 226005 (Salaries Tax Payable) and
//  240 (Social Insurance Payable) every time it posts; this tracks
//  how much of that accrued liability has actually been paid to the
//  Tax Authority / Social Insurance Authority, via Other Payment.
//
//  Confirmed with Mahmoud (September 2026): a SIMPLE running balance
//  (accrued minus remitted), no period-matching — the same choice
//  already made for Advances. Remittance is company-wide, not
//  per-employee, so unlike Advances & Settlements there is no
//  per-partner drill-down page: one balance pair, one combined
//  history, on a single page.
//
//  Remittances are recorded exactly like Advances: through the
//  existing Other Payment screen, debit_account_id = 226005 or 240,
//  partner_id = whichever Partner represents the Tax Authority /
//  Social Insurance Authority (an ordinary "Other" partner — no new
//  partner type was added for this, per Mahmoud). This service is a
//  pure READ layer; it posts nothing itself.
//
//  @layer    Service
//  @depends  ChartOfAccount, OtherPayment, PayrollRun, PayrollRunLine
// ══════════════════════════════════════════════════════════════════

use App\Models\ChartOfAccount;
use App\Models\OtherPayment;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use Illuminate\Support\Collection;

class GovernmentRemittanceService
{
    /** Both current balances — ['tax' => X, 'social_insurance' => Y]. */
    public function balances(int $companyId): array
    {
        return [
            'tax'              => $this->balanceFor($companyId, '226005', 'tax'),
            'social_insurance' => $this->balanceFor($companyId, '240', 'social_insurance'),
        ];
    }

    private function balanceFor(int $companyId, string $accountCode, string $kind): string
    {
        $account = ChartOfAccount::findByCode($companyId, $accountCode);
        if (! $account) {
            return '0.00';
        }

        $accrued = $kind === 'tax'
            ? (string) PayrollRunLine::where('company_id', $companyId)
                ->whereHas('payrollRun', fn ($q) => $q->where('status', PayrollRun::STATUS_POSTED))
                ->sum('tax_amount')
            : (string) PayrollRunLine::where('company_id', $companyId)
                ->whereHas('payrollRun', fn ($q) => $q->where('status', PayrollRun::STATUS_POSTED))
                ->selectRaw('SUM(social_insurance_employee_amount + social_insurance_employer_amount) as total')
                ->value('total') ?? '0';

        $remitted = (string) OtherPayment::where('company_id', $companyId)
            ->where('debit_account_id', $account->id)
            ->where('status', OtherPayment::STATUS_POSTED)
            ->sum('amount_functional');

        $balance = bcsub((string) $accrued, $remitted, 2);

        return bccomp($balance, '0', 2) > 0 ? $balance : '0.00';
    }

    /**
     * Combined company-wide timeline, newest first: every payroll run
     * that accrued tax/SI (one row per run, both amounts shown), and
     * every remittance paid via Other Payment.
     */
    public function history(int $companyId): Collection
    {
        $taxAccount = ChartOfAccount::findByCode($companyId, '226005');
        $siAccount  = ChartOfAccount::findByCode($companyId, '240');

        $accrued = PayrollRun::where('company_id', $companyId)
            ->where('status', PayrollRun::STATUS_POSTED)
            ->withSum('lines as tax_total', 'tax_amount')
            ->withSum('lines as si_employee_total', 'social_insurance_employee_amount')
            ->withSum('lines as si_employer_total', 'social_insurance_employer_amount')
            ->get(['id', 'period_year', 'period_month', 'run_date'])
            ->flatMap(function ($run) {
                $rows = [];
                $tax = (string) ($run->tax_total ?? '0');
                $si  = bcadd((string) ($run->si_employee_total ?? '0'), (string) ($run->si_employer_total ?? '0'), 2);

                if (bccomp($tax, '0', 2) > 0) {
                    $rows[] = [
                        'date' => (string) $run->run_date, 'kind' => 'tax', 'type' => 'accrued',
                        'amount' => $tax, 'reference' => $run->periodLabel(), 'note' => null,
                    ];
                }
                if (bccomp($si, '0', 2) > 0) {
                    $rows[] = [
                        'date' => (string) $run->run_date, 'kind' => 'social_insurance', 'type' => 'accrued',
                        'amount' => $si, 'reference' => $run->periodLabel(), 'note' => null,
                    ];
                }
                return $rows;
            });

        $remitted = OtherPayment::where('company_id', $companyId)
            ->whereIn('debit_account_id', array_filter([$taxAccount?->id, $siAccount?->id]))
            ->where('status', OtherPayment::STATUS_POSTED)
            ->get(['id', 'payment_code', 'payment_date', 'amount_functional', 'debit_account_id', 'internal_notes'])
            ->map(fn ($p) => [
                'date'      => (string) $p->payment_date,
                'kind'      => $p->debit_account_id === $taxAccount?->id ? 'tax' : 'social_insurance',
                'type'      => 'remitted',
                'amount'    => (string) $p->amount_functional,
                'reference' => $p->payment_code,
                'note'      => $p->internal_notes,
            ]);

        return $accrued->concat($remitted)->sortByDesc('date')->values();
    }
}
