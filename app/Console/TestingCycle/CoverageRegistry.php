<?php

namespace App\Console\TestingCycle;

// ══════════════════════════════════════════════════════════════════
//  CoverageRegistry
//
//  The answer to "does the cycle actually cover everything?" — asked
//  of the tool rather than of the person who wrote it.
//
//  Every domain this ERP has is listed below with the operation
//  labels that would prove it was genuinely exercised. At the end of
//  a run the registry compares that list against what the CycleLog
//  actually recorded and reports, per domain:
//
//      COVERED       the operation ran at least once
//      NOT COVERED   it never ran — either the cycle does not attempt
//                    it, or every attempt failed
//
//  The second case is the important one. A scenario that throws on
//  every call still produces findings, but it produces NO operations
//  — so a domain can look "attempted" in the source and still be
//  completely unexercised. Only the recorded operations count.
//
//  ADDING A DOMAIN HERE IS A CLAIM THAT THE CYCLE SHOULD COVER IT.
//  Anything listed and not covered shows up in the final report as a
//  gap, deliberately, rather than being quietly dropped from the
//  list to make the summary look complete.
//
//  @layer    Console Support
// ══════════════════════════════════════════════════════════════════

class CoverageRegistry
{
    /**
     * domain => [operation labels that prove it ran]
     *
     * The labels must match exactly what scenarios pass to
     * CycleLog::operation().
     */
    private const DOMAINS = [
        'Company & master data' => ['company', 'warehouse', 'product', 'partner', 'chart of accounts leaf'],
        'Fiscal years & periods' => ['fiscal year', 'accounting period close', 'fiscal year close'],

        'Purchasing' => ['purchase order', 'goods receipt note', 'supplier bill', 'service bill'],
        'Sales' => ['sales order', 'delivery note', 'customer invoice'],
        'Collections' => ['customer collection'],
        'Supplier payments' => ['supplier payment'],

        'Inventory — movements' => ['warehouse transfer', 'stock adjustment'],
        'Inventory — landing costs' => ['landing cost'],

        'Manufacturing — production orders' => ['production order', 'material consumption', 'production completion'],
        'Manufacturing — standard costing' => ['standard costing setup', 'bill of material', 'labour cost entry', 'overhead cost entry'],
        'Manufacturing — job orders' => ['job order', 'job order consumption', 'job order completion'],
        'Manufacturing — variance closing' => ['variance closing'],

        'Expenses' => ['cash expense payment'],
        'Accruals & prepaid' => ['accrual', 'prepaid bill', 'prepaid amortisation'],
        'Other collections & payments' => ['capital injection', 'other payment'],

        'Credit & debit notes' => ['customer credit note', 'customer debit note', 'supplier debit note'],
        'Internal settlements' => ['internal settlement'],

        'Banking — bank accounts' => ['bank account'],
        'Banking — transfers' => ['internal money transfer'],
        'Banking — cheques' => ['cheque receivable', 'cheque payable', 'cheque sent for collection'],
        'Banking — cheque lifecycle' => ['cheque collected', 'cheque rejected', 'cheque paid'],

        'Treasury — overdraft facilities' => ['credit facility', 'overdraft draw', 'overdraft repayment'],
        'Treasury — letters of guarantee' => ['LG agreement', 'LG issuance'],
        'Treasury — guarantee lifecycle' => ['LG release', 'LG liquidation'],
        'Treasury — letters of credit' => ['LC agreement', 'LC issuance'],
        'Treasury — factoring' => ['factoring company', 'factoring agreement', 'factoring transaction'],
        'Treasury — factoring lifecycle' => ['factoring collection', 'factoring recourse'],
        'Treasury — leasing' => ['leasing company', 'leasing contract'],

        'Fixed assets' => ['fixed asset type', 'fixed asset purchase', 'depreciation posting'],

        'HR — payroll' => ['payroll profile', 'payroll run', 'payroll posting', 'salary revision'],
        'HR — end of service' => ['EOSB provision run', 'EOSB provision posting'],
        'HR — leave management' => ['leave request', 'leave approval', 'leave type'],
        'HR — expense claims' => ['expense claim', 'expense claim approval', 'expense claim payment'],

        'Multi-currency' => ['foreign currency invoice'],

        'Negative & edge cases' => ['negative test'],

        // ── The bank products a company actually uses.
        'Treasury — medium-term loans'   => ['medium-term loan', 'loan schedule', 'loan settlement'],
        'Treasury — time deposits'       => ['time deposit', 'time deposit interest', 'time deposit renewal'],
        'Treasury — secured overdrafts'  => ['commercial paper overdraft', 'fully secured overdraft',
                                             'contract assignment overdraft', 'contract assignment'],
        'Treasury — reverse factoring'   => ['reverse factoring agreement', 'reverse factoring',
                                             'reverse factoring settlement'],
        'Treasury — currency dealing'    => ['currency deal', 'currency deal posting'],

        // ── Inventory beyond receive-and-issue.
        'Inventory — kits'          => ['kit recipe', 'kit assembly'],
        'Inventory — reservations'  => ['stock reservation', 'stock reservation release'],
        'Projects'                  => ['project', 'project status change', 'project inventory statement'],
        'Supplier credit notes'     => ['supplier credit note', 'supplier credit note posting'],

        // ── The settings screens everything else depends on.
        'Settings — cost centres'    => ['cost centre'],
        'Settings — payment terms'   => ['payment term'],
        'Settings — locations'       => ['inventory location'],
        'Settings — sales channels'  => ['sales channel'],
        'Settings — payroll reasons' => ['deduction reason'],
        'Settings — tax'             => ['tax settings', 'tax override'],
        'Settings — exchange rates'  => ['exchange rate'],
        'Manufacturing — job order templates' => ['job order template'],
        'Balance screens'            => ['balance screen'],

        'Cross-module reconciliation' => ['cross-module reconciliation'],

        // ── Migrating an existing company in.
        'Opening balances' => ['opening balance session', 'opening balance line',
                               'opening balance validation', 'opening balance posting'],

        // ── Planning and valuation.
        'Planning — plan setup'     => ['plan', 'plan product', 'plan sales forecast', 'plan collection policy',
                                        'plan cost assumption', 'plan expense', 'plan manpower snapshot',
                                        'plan manpower title', 'plan hiring', 'plan fixed asset'],
        'Planning — projection'     => ['plan projection', 'plan opening balance'],
        'Valuation'                 => ['plan valuation'],

        // ── Reporting. A company that posts correctly and reports
        //    wrongly is still wrong, so the read side is claimed too.
        'Reporting — receivables & payables' => ['receivables report', 'payables report'],
        'Reporting — account statements'     => ['account statement'],
        'Reporting — aging'                  => ['invoice aging report', 'cheque aging report'],
        'Reporting — cash position'          => ['cash status dashboard'],
        'Reporting — inventory valuation'    => ['inventory value statement'],
        'Reporting — dashboards & analytics' => ['report read'],
    ];

    /**
     * @return array<string, array{covered: array<int,string>, missing: array<int,string>, status: string}>
     */
    public function assess(CycleLog $log): array
    {
        $performed = $log->operationCounts();
        $result    = [];

        foreach (self::DOMAINS as $domain => $labels) {
            $covered = [];
            $missing = [];

            foreach ($labels as $label) {
                if (($performed[$label] ?? 0) > 0) {
                    $covered[] = $label;
                } else {
                    $missing[] = $label;
                }
            }

            $result[$domain] = [
                'covered' => $covered,
                'missing' => $missing,
                'status'  => match (true) {
                    $missing === [] => 'COVERED',
                    $covered === [] => 'NOT COVERED',
                    default         => 'PARTIAL',
                },
            ];
        }

        return $result;
    }

    /**
     * Operations the cycle performed that no domain claims. Not a
     * problem — it means the registry is behind the scenarios — but
     * worth surfacing so the two do not drift apart.
     *
     * @return array<int, string>
     */
    public function unclaimed(CycleLog $log): array
    {
        $claimed = [];
        foreach (self::DOMAINS as $labels) {
            foreach ($labels as $label) {
                $claimed[$label] = true;
            }
        }

        return array_values(array_diff(array_keys($log->operationCounts()), array_keys($claimed)));
    }

    public function totals(array $assessment): array
    {
        $counts = ['COVERED' => 0, 'PARTIAL' => 0, 'NOT COVERED' => 0];

        foreach ($assessment as $domain) {
            $counts[$domain['status']]++;
        }

        return $counts;
    }
}
