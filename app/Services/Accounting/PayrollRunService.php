<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  PayrollRunService
//
//  The monthly Payroll accrual engine. Confirmed with Mahmoud across
//  several rounds of design, before any code: one run per company
//  per period, one line per active employee, a generic addition/
//  deduction shape (forward-compatible with a future Leave
//  Management module — see PayrollRunLineItem header), and posting
//  split by expense category.
//
//  ── The two posting shapes ──────────────────────────────────────
//
//  Sales / Marketing / G&A — a fresh salary expense, per category:
//    Dr 521/531/541 Salaries — [category]   gross_pay + si_employer
//                                             - payout_penalty (net,
//                                             see recalculateLine()
//                                             for why payout_penalty
//                                             nets against the
//                                             expense itself rather
//                                             than a separate credit
//                                             line — this is a
//                                             deliberate simplification,
//                                             flagged for review, not
//                                             the only defensible
//                                             treatment)
//        Cr 239 Salaries Payable                net_pay
//        Cr 226005 Salaries Tax Payable         tax_amount
//        Cr 240 Social Insurance Payable        si_employee + si_employer
//        Cr 147 Advances to Employees           advance_deduction (only
//                                                 if any this run)
//
//  Manufacturing — NO fresh expense. recordLaborCost() (Production
//  Order) already debited 135002 WIP / credited 237 Direct Labor
//  Clearing all month, for a POOLED total across every order — not
//  per employee. This settlement clears that pool:
//    Dr 237 Direct Labor Clearing            whatever 237's balance
//                                             actually is right now
//                                             (resolveAccountBalance())
//    Dr/Cr 514 Manufacturing Labor Variance   the plug — see class
//                                             header math below
//        Cr 239 / 226005 / 240 / 147           same shape as above,
//                                             summed over manufacturing
//                                             lines only
//
//  MANUFACTURING OVERHEAD (Section 7, Sept 2026 — Applied vs Actual
//  variance closing). Manufacturing employees are split by their
//  Manufacturing Role (Employee Manufacturing Role, Section 2) —
//  Direct Labor keeps working exactly as above, unchanged. Overhead-
//  role employees are grouped further by their own overhead_pool_id
//  (every Overhead-role profile must have one — see the guard in
//  postManufacturingSettlement()), and PER POOL:
//    Dr/Cr [pool]'s own Applied account       whatever it currently
//                                              holds (each pool has
//                                              its own dedicated GL
//                                              sub-account under 238
//                                              — see OverheadPoolService
//                                              ::ensureAppliedAccount())
//    Dr/Cr 515 Manufacturing Overhead Variance the plug for THIS pool
//                                              — a separate labeled
//                                              line per pool, never
//                                              lumped into one number
//                                              (confirmed with Mahmoud)
//  Actual, per pool, is never a posted GL balance either (same
//  principle as labor) — it's computed directly each run from:
//    - real payroll cost for that pool's Overhead-role employees
//      (same gross_pay + si_employer - payout_penalty formula labor
//      uses), plus
//    - real Accrual rows tagged to that pool (Accrual::overhead_pool_id,
//      Section 7) with accrual_date falling in this run's own period.
//  Every manufacturing employee (labor AND overhead) still contributes
//  to the SAME combined 239/226005/240/147 credit lines below — the
//  role split only affects which debit-side account absorbs their
//  cost, never whether their real salary gets recorded as owed.
//
//  VARIANCE PERIOD RECORDS (2026-09, Manufacturing Variance Closing
//  Design v1.0, Section 1/Step 3): alongside the 514/515 journal
//  lines below, post() now also writes a ManufacturingVariancePeriod
//  row for the labor figure and one per overhead pool — the exact
//  same numbers, just kept in queryable form instead of only living
//  inside a posted JE. This is what the monthly Variance Closing step
//  (Step 3) reads to push these figures down to individual orders.
//  setToDraft() deletes them when a run is reopened, same reversal
//  spirit as everything else it undoes.
//
//  TAX / SOCIAL INSURANCE — MANUAL INPUT, this slice. Deliberately
//  not auto-calculated — see migration 000228 header for why
//  hardcoding Egyptian rate tables here would be irresponsible
//  without a confirmed, currently-correct source. A rate-table-driven
//  calculator is real future work.
//
//  @layer    Service
//  @depends  PayrollRun, PayrollRunLine, PayrollRunLineItem,
//            PayrollRunPosting, EmployeePayrollProfile, DeductionReason,
//            ChartOfAccount, JournalEntry, JournalEntryService,
//            FiscalYearService, OverheadPoolService, OverheadPool,
//            Accrual, OtherPayment, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\Accrual;
use App\Models\ChartOfAccount;
use App\Models\DeductionReason;
use App\Models\EmployeePayrollProfile;
use App\Models\JournalEntry;
use App\Models\ManufacturingVariancePeriod;
use App\Models\OtherCollection;
use App\Models\OtherPayment;
use App\Models\OverheadPool;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\PayrollRunLineItem;
use App\Models\PayrollRunPayment;
use App\Models\PayrollRunPosting;
use App\Models\User;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PayrollRunService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
        private OverheadPoolService $overheadPools,
    ) {}

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return PayrollRun::when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->withCount('lines')
            ->withSum('lines as net_pay_total', 'net_pay')
            ->orderByDesc('period_year')->orderByDesc('period_month')->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create — snapshots every active employee into a line ─────

    /**
     * M-36: $options = ['run_type' => regular|bonus|final_settlement,
     * 'partner_ids' => [...], 'label' => '...']. The regular run is
     * still one per month. Bonus / final-settlement runs may be added
     * any number of times in the same month for the employees named in
     * partner_ids; their lines start at zero base salary — the bonus or
     * final dues are entered as additions / deductions on each line.
     */
    public function create(int $companyId, int $periodYear, int $periodMonth, string $runDate, User $actor, array $options = []): PayrollRun
    {
        $runType = $options['run_type'] ?? 'regular';
        if (! in_array($runType, ['regular', 'bonus', 'final_settlement'], true)) {
            throw new \DomainException('Unknown payroll run type.');
        }
        $partnerIds = array_values(array_filter(array_map('intval', $options['partner_ids'] ?? [])));
        if ($runType !== 'regular' && ! $partnerIds) {
            throw new \DomainException('Choose the employees this '.($runType === 'bonus' ? 'bonus' : 'final settlement').' run is for.');
        }

        $sequence = 1;
        if ($runType === 'regular') {
            $exists = PayrollRun::where('company_id', $companyId)
                ->where('period_year', $periodYear)->where('period_month', $periodMonth)
                ->where('run_type', 'regular')->exists();

            if ($exists) {
                throw new \DomainException("A Payroll Run for {$periodMonth}/{$periodYear} already exists.");
            }
        } else {
            $sequence = 1 + (int) PayrollRun::where('company_id', $companyId)
                ->where('period_year', $periodYear)->where('period_month', $periodMonth)
                ->where('run_type', $runType)->max('sequence');
        }

        // The salary snapshotted per employee is whichever revision was
        // effective by the LAST day of this run's period — see
        // EmployeePayrollProfile::salaryAsOfDate(). A raise dated
        // anywhere in July applies to the July run; one dated 1 Aug
        // only starts applying from the August run onward.
        $periodEnd = \Carbon\Carbon::create($periodYear, $periodMonth, 1)->endOfMonth()->toDateString();

        return DB::transaction(function () use ($companyId, $periodYear, $periodMonth, $runDate, $periodEnd, $actor, $runType, $sequence, $partnerIds, $options) {
            $run = PayrollRun::create([
                'company_id'   => $companyId,
                'period_year'  => $periodYear,
                'period_month' => $periodMonth,
                'run_type'     => $runType,
                'sequence'     => $sequence,
                'label'        => $runType === 'regular' ? null : ($options['label'] ?? null),
                'run_date'     => $runDate,
                'status'       => PayrollRun::STATUS_DRAFT,
                'created_by'   => $actor->id,
            ]);

            // Regular run: every active employee. Bonus / final settlement: only
            // the chosen employees (a leaver may already be inactive).
            $profiles = $runType === 'regular'
                ? EmployeePayrollProfile::where('company_id', $companyId)->active()->get()
                : EmployeePayrollProfile::where('company_id', $companyId)->whereIn('partner_id', $partnerIds)->get();

            foreach ($profiles as $profile) {
                $salaryForPeriod = $runType === 'regular' ? $profile->salaryAsOfDate($periodEnd) : '0.00';

                // Employee classification history fix (VeroCFO_
                // Manufacturing_Costing_Redesign_v1_0.md, Section 1) —
                // resolved by the period's own end date, exactly like
                // salary above, instead of reading whatever the
                // category happens to be today. A reclassification
                // dated after this period never reaches back and
                // recategorizes it.
                $categoryForPeriod = $profile->classificationAsOfDate($periodEnd);

                PayrollRunLine::create([
                    'company_id'                  => $companyId,
                    'payroll_run_id'               => $run->id,
                    'partner_id'                   => $profile->partner_id,
                    'employee_payroll_profile_id'  => $profile->id,
                    'base_salary'                  => $salaryForPeriod,
                    'currency_id'                  => $profile->currency_id,
                    'expense_category'             => $categoryForPeriod,
                    'gross_pay'                     => $salaryForPeriod,
                    'net_pay'                       => $salaryForPeriod,
                ]);
            }

            AuditLog::record($actor, 'payroll_run.created', $run, null, $run->fresh(['lines'])->toArray());

            return $run->fresh(['lines.partner']);
        });
    }

    // ── Line items — generic addition/deduction ───────────────────

    public function addLineItem(PayrollRunLine $line, array $data, User $actor): PayrollRunLineItem
    {
        $this->assertRunDraft($line->payrollRun);

        return DB::transaction(function () use ($line, $data, $actor) {
            $item = PayrollRunLineItem::create([
                'company_id'          => $line->company_id,
                'payroll_run_line_id' => $line->id,
                'type'                 => $data['type'],
                'deduction_reason_id'  => $data['deduction_reason_id'] ?? null,
                'amount'               => $data['amount'],
                'description'          => $data['description'] ?? null,
                'created_by'           => $actor->id,
            ]);

            $this->recalculateLine($line->fresh());

            AuditLog::record($actor, 'payroll_run_line.item_added', $line, null, $item->toArray());

            return $item;
        });
    }

    public function removeLineItem(PayrollRunLineItem $item, User $actor): void
    {
        $line = $item->payrollRunLine;
        $this->assertRunDraft($line->payrollRun);

        DB::transaction(function () use ($item, $line, $actor) {
            $before = $item->toArray();
            $item->delete();
            $this->recalculateLine($line->fresh());
            AuditLog::record($actor, 'payroll_run_line.item_removed', $line, $before, null);
        });
    }

    /**
     * Sets this line's manual-input figures (tax, Social Insurance,
     * advance recovery) and recalculates net_pay. advance_deduction
     * is capped at whatever's actually outstanding on 147 for this
     * employee — never lets a run silently over-recover an advance.
     */
    public function setManualAmounts(PayrollRunLine $line, string $taxAmount, string $siEmployee, string $siEmployer, string $advanceDeduction, User $actor): PayrollRunLine
    {
        $this->assertRunDraft($line->payrollRun);

        $outstanding = $this->outstandingAdvanceBalance($line->company_id, $line->partner_id);
        if (bccomp($advanceDeduction, $outstanding, 2) > 0) {
            throw new \DomainException(
                "Advance deduction ({$advanceDeduction}) exceeds this employee's outstanding advance balance ({$outstanding})."
            );
        }

        return DB::transaction(function () use ($line, $taxAmount, $siEmployee, $siEmployer, $advanceDeduction, $actor) {
            $line->update([
                'tax_amount'                        => $taxAmount,
                'social_insurance_employee_amount'  => $siEmployee,
                'social_insurance_employer_amount'  => $siEmployer,
                'advance_deduction_amount'          => $advanceDeduction,
            ]);

            $this->recalculateLine($line->fresh());

            AuditLog::record($actor, 'payroll_run_line.manual_amounts_set', $line, null, $line->fresh()->toArray());

            return $line->fresh();
        });
    }

    /**
     * Recomputes additions_total/earned_reduction_total/
     * payout_penalty_total/gross_pay/net_pay from this line's own
     * items plus its already-stored manual tax/SI/advance figures.
     * Never touches base_salary or the manual fields themselves.
     */
    private function recalculateLine(PayrollRunLine $line): void
    {
        $items = $line->lineItems()->with('deductionReason')->get();

        $additions = '0.00';
        $earnedReduction = '0.00';
        $payoutPenalty = '0.00';

        foreach ($items as $item) {
            if ($item->isAddition()) {
                $additions = bcadd($additions, (string) $item->amount, 2);
                continue;
            }

            // Deduction — behavior comes from the reason, never
            // re-decided per line item (confirmed with Mahmoud: set
            // once when the reason is created).
            if ($item->deductionReason?->isEarnedReduction()) {
                $earnedReduction = bcadd($earnedReduction, (string) $item->amount, 2);
            } else {
                $payoutPenalty = bcadd($payoutPenalty, (string) $item->amount, 2);
            }
        }

        $grossPay = bcsub(bcadd((string) $line->base_salary, $additions, 2), $earnedReduction, 2);

        $netPay = bcsub($grossPay, $payoutPenalty, 2);
        $netPay = bcsub($netPay, (string) $line->tax_amount, 2);
        $netPay = bcsub($netPay, (string) $line->social_insurance_employee_amount, 2);
        $netPay = bcsub($netPay, (string) $line->advance_deduction_amount, 2);

        $line->update([
            'additions_total'         => $additions,
            'earned_reduction_total'  => $earnedReduction,
            'payout_penalty_total'    => $payoutPenalty,
            'gross_pay'               => $grossPay,
            'net_pay'                 => $netPay,
        ]);
    }

    /**
     * Every OtherPayment ever posted debiting 147 for this employee,
     * minus every advance_deduction_amount already applied against
     * them in a POSTED payroll run, minus every manual repayment
     * recorded via Other Collection (employee_advance_type =
     * advance_salary, credited to 147, posted).
     *
     * BUG FIX (2026-09, Advances & Settlements): this used to ignore
     * manual repayments entirely — an employee who paid back part of
     * an advance directly (cash/bank, not through payroll) would
     * still show the FULL original amount as outstanding here, which
     * could let a payroll run deduct more than was actually still
     * owed. Now nets against Other Collection the same way it
     * already netted against payroll recoveries.
     */
    public function outstandingAdvanceBalance(int $companyId, int $partnerId): string
    {
        $advancesAccount = ChartOfAccount::findByCode($companyId, '147');
        if (! $advancesAccount) {
            return '0.00';
        }

        $granted = (string) OtherPayment::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('debit_account_id', $advancesAccount->id)
            ->where('status', OtherPayment::STATUS_POSTED)
            ->sum('amount_functional');

        $recoveredViaPayroll = (string) PayrollRunLine::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->whereHas('payrollRun', fn ($q) => $q->where('status', PayrollRun::STATUS_POSTED))
            ->sum('advance_deduction_amount');

        $recoveredManually = (string) OtherCollection::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('credit_account_id', $advancesAccount->id)
            ->where('status', OtherCollection::STATUS_POSTED)
            ->sum('amount_functional');

        $outstanding = bcsub(bcsub($granted, $recoveredViaPayroll, 2), $recoveredManually, 2);

        return bccomp($outstanding, '0', 2) > 0 ? $outstanding : '0.00';
    }

    // ── Post — the actual GL posting, split by category ──────────

    public function post(PayrollRun $run, User $actor): PayrollRun
    {
        $this->assertRunDraft($run);

        if ($this->fiscalYearService->isPeriodClosed($run->company_id, $run->run_date)) {
            throw new \DomainException('This Payroll Run\'s accounting period is closed — it cannot be posted.');
        }

        $run->loadMissing('lines');

        if ($run->lines->isEmpty()) {
            throw new \DomainException('This Payroll Run has no employees — nothing to post.');
        }

        return DB::transaction(function () use ($run, $actor) {
            $linesByCategory = $run->lines->groupBy('expense_category');

            // M-36: in a bonus / final-settlement run the factory lines join the G&A group
            // (one posting per category per run), see the manufacturing branch below.
            if (! $run->isRegular() && $linesByCategory->has(EmployeePayrollProfile::CATEGORY_MANUFACTURING)) {
                $linesByCategory[EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN] = ($linesByCategory->get(EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN) ?? collect())
                    ->concat($linesByCategory->get(EmployeePayrollProfile::CATEGORY_MANUFACTURING));
                $linesByCategory->forget(EmployeePayrollProfile::CATEGORY_MANUFACTURING);
            }

            foreach ([EmployeePayrollProfile::CATEGORY_SALES, EmployeePayrollProfile::CATEGORY_MARKETING, EmployeePayrollProfile::CATEGORY_GENERAL_ADMIN, EmployeePayrollProfile::CATEGORY_COST_OF_SERVICE] as $category) {
                $lines = $linesByCategory->get($category);
                if (! $lines || $lines->isEmpty()) {
                    continue;
                }
                $this->postCategoryExpense($run, $category, $lines, $actor);
            }

            $manufacturingLines = $linesByCategory->get(EmployeePayrollProfile::CATEGORY_MANUFACTURING);
            if ($manufacturingLines && $manufacturingLines->isNotEmpty()) {
                // Only a regular run reaches here (extra runs were merged into G&A above):
                // the monthly labour-clearing settlement happens once, with the regular run.
                $this->postManufacturingSettlement($run, $manufacturingLines, $actor);
            }

            $run->update([
                'status'    => PayrollRun::STATUS_POSTED,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ]);

            AuditLog::record($actor, 'payroll_run.posted', $run, null, $run->fresh()->toArray());

            return $run->fresh(['lines', 'postings']);
        });
    }

    /** Sales / Marketing / G&A — a fresh salary expense. See class header. */
    private function postCategoryExpense(PayrollRun $run, string $category, \Illuminate\Support\Collection $lines, User $actor): void
    {
        $salaryAccount = $this->resolveSystemAccount($run->company_id, EmployeePayrollProfile::SALARY_ACCOUNT_CODE[$category], "Salaries — {$category}");

        $expenseDebit = '0.00';
        $netPayTotal = '0.00';
        $taxTotal = '0.00';
        $siTotal = '0.00';
        $advanceTotal = '0.00';

        foreach ($lines as $line) {
            // gross_pay + employer's SI share - payout penalty — the
            // penalty nets against the expense itself, never a
            // separate credit line. See class header.
            // Audit C8: every figure converted to functional currency
            // first (see lineFunctional()).
            $f = $this->lineFunctional($run, $line);
            $expenseDebit = bcadd($expenseDebit, $f['expense'], 2);
            $netPayTotal  = bcadd($netPayTotal, $f['net'], 2);
            $taxTotal     = bcadd($taxTotal, $f['tax'], 2);
            $siTotal      = bcadd($siTotal, $f['si'], 2);
            $advanceTotal = bcadd($advanceTotal, $f['advance'], 2);
        }

        $lines2 = $this->buildSettlementLines($run, $salaryAccount->id, $expenseDebit, $netPayTotal, $taxTotal, $siTotal, $advanceTotal, "Payroll — {$run->periodLabel()}");

        $journalEntry = $this->journalEntryService->post(
            companyId: $run->company_id,
            entryDate: $run->run_date,
            lines: $lines2,
            sourceType: 'payroll_run',
            sourceId: $run->id,
            narration: "Payroll Run {$run->periodLabel()} — ".ucfirst(str_replace('_', ' ', $category)),
            actor: $actor,
        );

        PayrollRunPosting::create([
            'company_id'       => $run->company_id,
            'payroll_run_id'   => $run->id,
            'category'         => $category,
            'journal_entry_id' => $journalEntry->id,
        ]);
    }

    /** Manufacturing — clears 237, plugs 514. See class header. */
    private function postManufacturingSettlement(PayrollRun $run, \Illuminate\Support\Collection $lines, User $actor): void
    {
        $lines->load('employeePayrollProfile', 'partner');

        $laborClearing = $this->resolveSystemAccount($run->company_id, '237', 'Direct Labor Clearing');
        $laborVariance = $this->resolveSystemAccount($run->company_id, '514', 'Manufacturing Labor Variance');
        $overheadVariance = $this->resolveSystemAccount($run->company_id, '515', 'Manufacturing Overhead Variance');

        // Combined credit side (239/226005/240/147) covers every
        // manufacturing employee regardless of role — real salary is
        // owed the same way either way; only the debit side below
        // splits by role/pool.
        $netPayTotal = '0.00';
        $taxTotal = '0.00';
        $siTotal = '0.00';
        $advanceTotal = '0.00';

        // Direct Labor — unchanged from before this section.
        $laborExpenseOwed = '0.00';

        // Overhead — Section 7. Grouped by overhead_pool_id; each
        // pool's real payroll contribution accumulates here, keyed by
        // pool id, then Accrual rows are added on top per pool below.
        $overheadExpenseByPool = [];

        foreach ($lines as $line) {
            // Audit C8: functional-currency figures (see lineFunctional()).
            $f = $this->lineFunctional($run, $line);
            $lineExpense  = $f['expense'];
            $netPayTotal  = bcadd($netPayTotal, $f['net'], 2);
            $taxTotal     = bcadd($taxTotal, $f['tax'], 2);
            $siTotal      = bcadd($siTotal, $f['si'], 2);
            $advanceTotal = bcadd($advanceTotal, $f['advance'], 2);

            $roleInfo = $line->employeePayrollProfile->manufacturingRoleAsOfDate($run->run_date);

            if ($roleInfo['role'] === EmployeePayrollProfile::ROLE_OVERHEAD) {
                if (! $roleInfo['overhead_pool_id']) {
                    throw new \DomainException(
                        "Employee \"{$line->partner->name_en}\" is classified as Manufacturing Overhead but has no Overhead Pool assigned — assign one on their payroll profile before posting this run."
                    );
                }
                $poolId = $roleInfo['overhead_pool_id'];
                $overheadExpenseByPool[$poolId] = bcadd($overheadExpenseByPool[$poolId] ?? '0.00', $lineExpense, 2);
            } else {
                // Direct Labor is the default when a manufacturing
                // employee's role hasn't been set yet — same
                // permissive fallback isDirectLabor()/isOverheadRole()
                // already use elsewhere, never a hard block on an
                // unset role.
                $laborExpenseOwed = bcadd($laborExpenseOwed, $lineExpense, 2);
            }
        }

        $debitLines = [];

        // ── Direct Labor — unchanged mechanics ─────────────────────
        $clearingBalance = $this->resolveAccountBalance($run->company_id, $laborClearing->id, $run->run_date);
        $laborVarianceAmount = bcsub($laborExpenseOwed, $clearingBalance, 2);

        if (bccomp($clearingBalance, '0', 2) != 0) {
            $debitLines[] = ['chart_of_account_id' => $laborClearing->id, 'debit' => bccomp($clearingBalance, '0', 2) > 0 ? $clearingBalance : '0', 'credit' => bccomp($clearingBalance, '0', 2) < 0 ? bcmul($clearingBalance, '-1', 2) : '0', 'description' => "Payroll — {$run->periodLabel()} — clear Direct Labor Clearing"];
        }
        if (bccomp($laborVarianceAmount, '0', 2) != 0) {
            $debitLines[] = ['chart_of_account_id' => $laborVariance->id, 'debit' => bccomp($laborVarianceAmount, '0', 2) > 0 ? $laborVarianceAmount : '0', 'credit' => bccomp($laborVarianceAmount, '0', 2) < 0 ? bcmul($laborVarianceAmount, '-1', 2) : '0', 'description' => "Payroll — {$run->periodLabel()} — labor variance"];
        }

        // Manufacturing Variance Closing Design v1.0, Section 1/Step
        // 3 — keep this exact figure in a queryable form, not just
        // inside the journal entry below. Written even when the
        // amount is zero, so Step 3 can tell "no variance this month"
        // apart from "not calculated yet."
        ManufacturingVariancePeriod::updateOrCreate(
            [
                'company_id'       => $run->company_id,
                'period_year'      => $run->period_year,
                'period_month'     => $run->period_month,
                'variance_type'    => ManufacturingVariancePeriod::TYPE_LABOR,
                'overhead_pool_id' => null,
            ],
            ['payroll_run_id' => $run->id, 'amount' => $laborVarianceAmount]
        );

        // ── Manufacturing Overhead — Section 7, per pool ───────────
        foreach ($overheadExpenseByPool as $poolId => $payrollExpense) {
            $pool = OverheadPool::findOrFail($poolId);
            $appliedAccount = $this->overheadPools->ensureAppliedAccount($pool, $actor);

            // Real Accrual rows tagged to this pool, for this run's
            // own period — same "no separate reconciled-flag needed"
            // reasoning as labor: this simply adds to the pool's real
            // cost for the period, on top of its Overhead-role
            // employees' payroll cost above.
            $accrualTotal = (string) Accrual::where('company_id', $run->company_id)
                ->where('overhead_pool_id', $poolId)
                ->whereYear('accrual_date', $run->period_year)
                ->whereMonth('accrual_date', $run->period_month)
                ->sum('amount');

            $poolActual = bcadd($payrollExpense, $accrualTotal, 2);
            $poolApplied = $this->resolveAccountBalance($run->company_id, $appliedAccount->id, $run->run_date);
            $poolVariance = bcsub($poolActual, $poolApplied, 2);

            if (bccomp($poolApplied, '0', 2) != 0) {
                $debitLines[] = ['chart_of_account_id' => $appliedAccount->id, 'debit' => bccomp($poolApplied, '0', 2) > 0 ? $poolApplied : '0', 'credit' => bccomp($poolApplied, '0', 2) < 0 ? bcmul($poolApplied, '-1', 2) : '0', 'description' => "Payroll — {$run->periodLabel()} — clear {$pool->name} Applied"];
            }
            if (bccomp($poolVariance, '0', 2) != 0) {
                // Confirmed with Mahmoud: each pool gets its OWN
                // labeled line under 515 — never lumped into one
                // combined overhead variance number.
                $debitLines[] = ['chart_of_account_id' => $overheadVariance->id, 'debit' => bccomp($poolVariance, '0', 2) > 0 ? $poolVariance : '0', 'credit' => bccomp($poolVariance, '0', 2) < 0 ? bcmul($poolVariance, '-1', 2) : '0', 'description' => "Payroll — {$run->periodLabel()} — {$pool->name} overhead variance"];
            }

            // Manufacturing Variance Closing Design v1.0, Section
            // 1/Step 3 — same reasoning as the labor record above,
            // one row per pool, written even when zero.
            ManufacturingVariancePeriod::updateOrCreate(
                [
                    'company_id'       => $run->company_id,
                    'period_year'      => $run->period_year,
                    'period_month'     => $run->period_month,
                    'variance_type'    => ManufacturingVariancePeriod::TYPE_OVERHEAD,
                    'overhead_pool_id' => $poolId,
                ],
                ['payroll_run_id' => $run->id, 'amount' => $poolVariance]
            );
        }

        $creditLines = $this->buildCreditLines($run->company_id, $netPayTotal, $taxTotal, $siTotal, $advanceTotal, "Payroll — {$run->periodLabel()}");

        $journalEntry = $this->journalEntryService->post(
            companyId: $run->company_id,
            entryDate: $run->run_date,
            lines: array_merge($debitLines, $creditLines),
            sourceType: 'payroll_run',
            sourceId: $run->id,
            narration: "Payroll Run {$run->periodLabel()} — Manufacturing (clears Direct Labor Clearing + per-pool Overhead Applied)",
            actor: $actor,
        );

        PayrollRunPosting::create([
            'company_id'       => $run->company_id,
            'payroll_run_id'   => $run->id,
            'category'         => EmployeePayrollProfile::CATEGORY_MANUFACTURING,
            'journal_entry_id' => $journalEntry->id,
        ]);
    }

    /** Shared credit-side shape: 239 / 226005 / 240 / 147 — used by both posting paths. */
    /**
     * Audit C8 FIX (Sept 2026) — an employee paid in USD had his USD
     * figures posted 1:1 into the EGP ledger. Each run line is now
     * converted at its own currency's rate on the run date (FxService;
     * refuses if the rate is missing; EGP lines use 1).
     *
     * Net pay is derived as the balancing figure
     * (gross − penalty − tax − employee SI − advance) so the rounded
     * functional amounts always balance exactly, line by line.
     */
    private function lineFunctional(PayrollRun $run, $line): array
    {
        $fx = app(FxService::class);
        $rate = $fx->rateOn($run->company_id, $line->currency_id ? (int) $line->currency_id : null, (string) $run->run_date);
        $conv = fn ($v) => $fx->toFunctional((string) ($v ?? '0'), $rate);

        $gross   = $conv($line->gross_pay);
        $penalty = $conv($line->payout_penalty_total);
        $tax     = $conv($line->tax_amount);
        $siEe    = $conv($line->social_insurance_employee_amount);
        $siEr    = $conv($line->social_insurance_employer_amount);
        $advance = $conv($line->advance_deduction_amount);

        $net = bcsub(bcsub(bcsub(bcsub($gross, $penalty, 2), $tax, 2), $siEe, 2), $advance, 2);

        return [
            'expense' => bcsub(bcadd($gross, $siEr, 2), $penalty, 2),
            'net'     => $net,
            'tax'     => $tax,
            'si'      => bcadd($siEe, $siEr, 2),
            'advance' => $advance,
        ];
    }

    private function buildSettlementLines(PayrollRun $run, int $debitAccountId, string $debitAmount, string $netPayTotal, string $taxTotal, string $siTotal, string $advanceTotal, string $description): array
    {
        $lines = [
            ['chart_of_account_id' => $debitAccountId, 'debit' => $debitAmount, 'credit' => '0', 'description' => $description],
        ];

        return array_merge($lines, $this->buildCreditLines($run->company_id, $netPayTotal, $taxTotal, $siTotal, $advanceTotal, $description));
    }

    private function buildCreditLines(int $companyId, string $netPayTotal, string $taxTotal, string $siTotal, string $advanceTotal, string $description): array
    {
        $lines = [];

        $salariesPayable = $this->resolveSystemAccount($companyId, '239', 'Salaries Payable');
        $lines[] = ['chart_of_account_id' => $salariesPayable->id, 'debit' => '0', 'credit' => $netPayTotal, 'description' => $description];

        if (bccomp($taxTotal, '0', 2) > 0) {
            $taxPayable = $this->resolveSystemAccount($companyId, '226005', 'Salaries Tax Payable');
            $lines[] = ['chart_of_account_id' => $taxPayable->id, 'debit' => '0', 'credit' => $taxTotal, 'description' => $description];
        }

        if (bccomp($siTotal, '0', 2) > 0) {
            $siPayable = $this->resolveSystemAccount($companyId, '240', 'Social Insurance Payable');
            $lines[] = ['chart_of_account_id' => $siPayable->id, 'debit' => '0', 'credit' => $siTotal, 'description' => $description];
        }

        if (bccomp($advanceTotal, '0', 2) > 0) {
            $advances = $this->resolveSystemAccount($companyId, '147', 'Advances to Employees');
            $lines[] = ['chart_of_account_id' => $advances->id, 'debit' => '0', 'credit' => $advanceTotal, 'description' => $description];
        }

        return $lines;
    }

    /**
     * Lightweight inline balance read for ONE account, up to a date —
     * NOT the full Financial Reports AccountBalanceService (which
     * doesn't exist yet, see the Financial Reports spec doc). Scoped
     * narrowly to what this one settlement needs. Reversed entries
     * are included per the same rule that engine will eventually
     * follow — a reversal always nets to zero on its own, never
     * specially excluded.
     */
    private function resolveAccountBalance(int $companyId, int $accountId, string $asOfDate): string
    {
        $totals = \App\Models\JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.company_id', $companyId)
            ->where('journal_entry_lines.chart_of_account_id', $accountId)
            ->where('journal_entries.entry_date', '<=', $asOfDate)
            ->selectRaw('COALESCE(SUM(journal_entry_lines.credit),0) as total_credit, COALESCE(SUM(journal_entry_lines.debit),0) as total_debit')
            ->first();

        // 237 is credit-normal (a liability) — balance = credit - debit.
        return bcsub((string) $totals->total_credit, (string) $totals->total_debit, 2);
    }

    // ── Set to Draft — full reversal ──────────────────────────────

    public function setToDraft(PayrollRun $run, User $actor): PayrollRun
    {
        if (! $run->isPosted()) {
            throw new \DomainException('Only a posted Payroll Run can be set back to draft.');
        }
        if ($this->fiscalYearService->isPeriodClosed($run->company_id, $run->run_date)) {
            throw new \DomainException('This Payroll Run\'s accounting period is closed — it cannot be reopened.');
        }

        if ($run->payments()->whereNull('reversed_at')->exists()) {
            throw new \DomainException('The net pay of this run has been paid — reverse that payment first, then reopen the run.');
        }

        $run->loadMissing('postings');

        return DB::transaction(function () use ($run, $actor) {
            $before = $run->toArray();

            foreach ($run->postings as $posting) {
                $entry = JournalEntry::findOrFail($posting->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Reopened to draft — Payroll Run '.$run->periodLabel());
                $posting->delete();
            }

            // Manufacturing Variance Closing Design v1.0, Section
            // 1/Step 3 — these figures are only meaningful for a
            // POSTED run; reopening this run means they'll be
            // recalculated fresh (possibly differently) when it's
            // posted again, so the stale ones must not linger.
            ManufacturingVariancePeriod::where('company_id', $run->company_id)
                ->where('payroll_run_id', $run->id)
                ->delete();

            $run->update([
                'status'    => PayrollRun::STATUS_DRAFT,
                'posted_by' => null,
                'posted_at' => null,
            ]);

            AuditLog::record($actor, 'payroll_run.set_to_draft', $run, $before, $run->fresh()->toArray());

            return $run->fresh(['lines', 'postings']);
        });
    }

    /** M-36: employees an extra (bonus / final-settlement) run can cover — active or not. */
    public function employeeChoices(int $companyId): array
    {
        return EmployeePayrollProfile::withoutGlobalScopes()->where('company_id', $companyId)->with('partner:id,name_en')->get()
            ->map(fn ($p) => ['id' => $p->partner_id, 'name' => $p->partner?->name_en, 'active' => (bool) $p->is_active])
            ->sortBy('name')->values()->all();
    }

    /** M-36: bank accounts the net pay can be paid from (those with a ledger account). */
    public function payableBankAccounts(int $companyId): array
    {
        return \App\Models\FinancialInstitutions\BankAccount::where('company_id', $companyId)->whereNotNull('coa_account_id')
            ->get(['id', 'account_number', 'currency_id'])->map(fn ($b) => ['id' => $b->id, 'name' => (string) $b->account_number])->all();
    }

    // ── Net-pay payment (M-36) ────────────────────────────────────

    /**
     * Pays the net salaries of a POSTED run out of a bank account:
     * Dr 239 Salaries Payable / Cr bank. Kept on the run, so the run
     * shows whether it has been paid. The bank account must be in the
     * company's main currency (salaries in another currency are paid
     * through a normal payment).
     */
    public function payNetPay(PayrollRun $run, int $bankAccountId, string $paymentDate, User $actor): PayrollRunPayment
    {
        if (! $run->isPosted()) {
            throw new \DomainException('Only a posted Payroll Run can be paid.');
        }
        if ($run->payments()->whereNull('reversed_at')->exists()) {
            throw new \DomainException('This Payroll Run has already been paid.');
        }
        if ($this->fiscalYearService->isPeriodClosed($run->company_id, $paymentDate)) {
            throw new \DomainException('The accounting period of the payment date is closed.');
        }

        $bank = \App\Models\FinancialInstitutions\BankAccount::where('company_id', $run->company_id)->find($bankAccountId);
        if (! $bank || ! $bank->coa_account_id) {
            throw new \DomainException('The selected bank account does not belong to this company, or has no ledger account.');
        }
        if (! app(FxService::class)->isFunctional($run->company_id, $bank->currency_id ? (int) $bank->currency_id : null)) {
            throw new \DomainException('Pay the salaries from a bank account in the company\'s main currency.');
        }

        $run->loadMissing('lines');
        $net = '0.00';
        foreach ($run->lines as $line) {
            $net = bcadd($net, $this->lineFunctional($run, $line)['net'], 2);
        }
        if (bccomp($net, '0', 2) <= 0) {
            throw new \DomainException('There is no net pay to pay on this run.');
        }

        return DB::transaction(function () use ($run, $bank, $paymentDate, $net, $actor) {
            $payable = $this->resolveSystemAccount($run->company_id, '239', 'Salaries Payable');
            $entry = $this->journalEntryService->post(
                companyId: $run->company_id,
                entryDate: $paymentDate,
                lines: [
                    ['chart_of_account_id' => $payable->id, 'debit' => $net, 'credit' => '0', 'description' => "Net pay — {$run->periodLabel()}"],
                    ['chart_of_account_id' => $bank->coa_account_id, 'debit' => '0', 'credit' => $net, 'description' => "Net pay — {$run->periodLabel()}"],
                ],
                sourceType: 'payroll_run_payment',
                sourceId: $run->id,
                narration: "Net pay paid — Payroll Run {$run->periodLabel()}",
                actor: $actor,
            );

            $payment = PayrollRunPayment::create([
                'company_id' => $run->company_id, 'payroll_run_id' => $run->id, 'bank_account_id' => $bank->id,
                'payment_date' => $paymentDate, 'amount' => $net, 'journal_entry_id' => $entry->id, 'created_by' => $actor->id,
            ]);

            AuditLog::record($actor, 'payroll_run.net_paid', $run, null, $payment->toArray());

            return $payment;
        });
    }

    public function reverseNetPayment(PayrollRun $run, User $actor): void
    {
        $payment = $run->payments()->whereNull('reversed_at')->latest('id')->first();
        if (! $payment) {
            throw new \DomainException('This Payroll Run has no payment to reverse.');
        }
        if ($this->fiscalYearService->isPeriodClosed($run->company_id, (string) $payment->payment_date)) {
            throw new \DomainException('The accounting period of this payment is closed — it cannot be reversed.');
        }

        DB::transaction(function () use ($run, $payment, $actor) {
            if ($payment->journal_entry_id) {
                $this->journalEntryService->reverse(JournalEntry::findOrFail($payment->journal_entry_id), $actor, 'Net pay payment reversed — '.$run->periodLabel());
            }
            $payment->update(['reversed_at' => now()]);
            AuditLog::record($actor, 'payroll_run.net_pay_reversed', $run, $payment->toArray(), null);
        });
    }

    /** M-36: everything a printed payslip needs for one line, converted nowhere — in the employee's own salary currency. */
    public function payslipData(PayrollRunLine $line): array
    {
        $line->loadMissing('partner', 'currency', 'lineItems.deductionReason');
        $run = PayrollRun::withoutGlobalScopes()->findOrFail($line->payroll_run_id);

        return [
            'company'  => (string) DB::table('companies')->where('id', $line->company_id)->value('name_en'),
            'period'   => $run->periodLabel(),
            'run_date' => (string) $run->run_date,
            'employee' => $line->partner?->name_en,
            'currency' => $line->currency?->code,
            'line'     => $line,
            'items'    => $line->lineItems,
        ];
    }

    // ── Internal helpers ──────────────────────────────────────────

    private function assertRunDraft(PayrollRun $run): void
    {
        if (! $run->isDraft()) {
            throw new \DomainException('This Payroll Run is no longer a draft — reopen it first (Set to Draft) before editing.');
        }
    }

    private function resolveSystemAccount(int $companyId, string $code, string $label): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code} ({$label}) — check the seeder.");
    }
}
