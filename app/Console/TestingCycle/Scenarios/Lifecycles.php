<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  Lifecycles
//
//  The second half of every instrument the cycle opens.
//
//  Creating a cheque, a lease, a factoring transaction or a letter of
//  guarantee is the easy half, and it is the only half most demo data
//  ever contains. The half that moves real money is what happens
//  afterwards: the cheque clears or bounces, the lease instalment is
//  paid, the factored invoice is collected or comes back, the
//  guarantee is released or called in.
//
//  Those are also the paths where the money actually lands in a bank
//  account, so they are where a costing or posting mistake shows up
//  as a wrong balance rather than a wrong-looking record.
//
//  EVERY ONE OF THESE ACTS ON A REAL INSTRUMENT the cycle created
//  earlier — a cheque from a real collection, a lease with a real
//  schedule, a factored invoice from a real sale.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Verify;
use App\Models\ChequePayable;
use App\Models\ChequeReceivable;
use App\Models\FinancialInstitutions\FactoringTransaction;
use App\Models\FinancialInstitutions\LgIssuance;
use App\Models\SupplierBillLine;
use App\Services\Accounting\ChequePayableService;
use App\Services\Accounting\ChequeReceivableService;
use App\Services\Accounting\PrepaidAmortizationService;
use App\Services\FinancialInstitutions\FactoringTransactionService;
use App\Services\FinancialInstitutions\LgIssuanceService;
use Carbon\Carbon;

class Lifecycles
{
    public function __construct(
        private CycleLog $log,
        private FormGate $gate,
    ) {
    }

    public function monthly(CycleContext $ctx, Carbon $monthStart, int $month): void
    {
        $this->clearCheques($ctx, $monthStart);
        $this->presentPayableCheques($ctx, $monthStart);
        $this->amortisePrepaid($ctx, $monthStart);

        if ($month % 6 === 5) {
            $this->settleFactoring($ctx, $monthStart);
        }

        if ($month % 4 === 2) {
            $this->releaseGuarantees($ctx, $monthStart);
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Cheques receivable — clearing and bouncing
    // ══════════════════════════════════════════════════════════════

    /**
     * A cheque that has reached its due date clears into the bank.
     * Every third one bounces, because a cycle where nothing ever
     * goes wrong does not test the path that matters most.
     */
    private function clearCheques(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify = new Verify($this->log, $ctx->companyId);

        $due = ChequeReceivable::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->whereIn('status', [ChequeReceivable::STATUS_RECEIVED, ChequeReceivable::STATUS_SENT_FOR_COLLECTION])
            ->whereDate('due_date', '<=', $monthStart->copy()->endOfMonth()->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($due as $index => $cheque) {
            $date       = $monthStart->copy()->endOfMonth()->toDateString();
            $amount     = (string) $cheque->cheque_amount;
            $bankBefore = $verify->trueBalance((int) $ctx->bankAccounts[0]->coa_account_id);
            $bounce     = ($ctx->chequesHandled % 3) === 2;
            $ctx->chequesHandled++;

            if ($bounce) {
                if ($cheque->fresh()->status === ChequeReceivable::STATUS_RECEIVED) {
                    app(ChequeReceivableService::class)->sendForCollection(
                        $cheque,
                        [
                            'collection_destination_type'            => 'bank_account',
                            'destination_id'                         => $ctx->bankAccounts[0]->id,
                            'sent_for_collection_at'                 => $date,
                        ],
                        $ctx->actor
                    );
                    $this->log->operation('cheque sent for collection');
                }

                $rejected = $this->gate->submit(
                    null,
                    [],
                    fn () => app(ChequeReceivableService::class)->reject($cheque->fresh(), $date, $ctx->actor),
                    'Customer cheque bounced — '.$monthStart->format('M Y'),
                    'cheque rejected'
                );

                if ($rejected) {
                    // A bounced cheque puts the receivable back. The
                    // bank must not have moved at any point.
                    $verify->money(
                        $bankBefore,
                        $verify->trueBalance((int) $ctx->bankAccounts[0]->coa_account_id),
                        'A bounced cheque still moved the bank balance',
                        'Cheque bounced — '.$monthStart->format('M Y'),
                        prefix: 'BNK'
                    );
                    $verify->ledgerBalanced('Cheque bounced — '.$monthStart->format('M Y'));
                }

                continue;
            }

            // A cheque cannot go straight from "received" to
            // "collected" — it is deposited for collection first, which
            // is also the date OD-against-commercial-paper prices from.
            if ($cheque->fresh()->status === ChequeReceivable::STATUS_RECEIVED) {
                $sent = $this->gate->submit(
                    null,
                    [],
                    fn () => app(ChequeReceivableService::class)->sendForCollection(
                        $cheque,
                        [
                            'collection_destination_type'            => 'bank_account',
                            'destination_id'                         => $ctx->bankAccounts[0]->id,
                            'sent_for_collection_at'                 => $date,
                        ],
                        $ctx->actor
                    ),
                    'Deposit a cheque for collection — '.$monthStart->format('M Y'),
                    'cheque sent for collection'
                );

                if (! $sent) {
                    continue;
                }
            }

            $collected = $this->gate->submit(
                null,
                [],
                fn () => app(ChequeReceivableService::class)->collect($cheque->fresh(), $date, $ctx->actor),
                'Customer cheque cleared — '.$monthStart->format('M Y'),
                'cheque collected'
            );

            if (! $collected) {
                continue;
            }

            // NOW the money is really in the bank — this is the whole
            // point of tracking cheques separately from cash.
            $verify->money(
                bcadd($bankBefore, $amount, 2),
                $verify->trueBalance((int) $ctx->bankAccounts[0]->coa_account_id),
                'A cleared cheque did not reach the bank account',
                'Cheque cleared — '.$monthStart->format('M Y'),
                ['cheque' => (string) $cheque->cheque_number, 'amount' => $amount],
                prefix: 'BNK'
            );

            $verify->ledgerBalanced('Cheque cleared — '.$monthStart->format('M Y'));
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Cheques payable — presentation
    // ══════════════════════════════════════════════════════════════

    /** A cheque the company issued is presented and the money leaves. */
    private function presentPayableCheques(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify = new Verify($this->log, $ctx->companyId);

        $due = ChequePayable::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('status', ChequePayable::STATUS_ISSUED)
            ->whereDate('due_date', '<=', $monthStart->copy()->endOfMonth()->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($due as $cheque) {
            $amount     = (string) $cheque->cheque_amount;
            $bankBefore = $verify->trueBalance((int) $ctx->bankAccounts[0]->coa_account_id);

            $paid = $this->gate->submit(
                null,
                [],
                fn () => app(ChequePayableService::class)->markAsPaid(
                    $cheque,
                    $monthStart->copy()->endOfMonth()->toDateString(),
                    $ctx->actor
                ),
                'Payable cheque presented — '.$monthStart->format('M Y'),
                'cheque paid'
            );

            if (! $paid) {
                continue;
            }

            $verify->money(
                bcsub($bankBefore, $amount, 2),
                $verify->trueBalance((int) $ctx->bankAccounts[0]->coa_account_id),
                'A presented cheque did not leave the bank account',
                'Cheque presented — '.$monthStart->format('M Y'),
                ['cheque' => (string) $cheque->cheque_number, 'amount' => $amount],
                prefix: 'BNK'
            );

            $verify->ledgerBalanced('Cheque presented — '.$monthStart->format('M Y'));
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Prepaid amortisation
    // ══════════════════════════════════════════════════════════════

    /**
     * A prepaid expense is only half-tested by paying it. The other
     * half is releasing one twelfth into the income statement every
     * month, which is what makes the prepayment an asset at all.
     */
    private function amortisePrepaid(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify = new Verify($this->log, $ctx->companyId);

        $lines = SupplierBillLine::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->whereNotNull('amortization_months')
            ->where('amortization_months', '>', 0)
            ->get();

        foreach ($lines as $line) {
            // A twelve-month prepayment is fully released after twelve
            // months. Asking for a thirteenth is a scenario bug, not a
            // product one — the service is right to refuse it — so the
            // cycle stops at the last period the line actually has.
            $totalPeriods  = (int) $line->amortization_months;
            $alreadyPosted = $line->amortizationEntries()->where('status', 'posted')->count();

            if ($alreadyPosted >= $totalPeriods) {
                continue;
            }

            $entry = $this->gate->submit(
                null,
                [],
                fn () => app(PrepaidAmortizationService::class)->postNextPeriod(
                    $line, $ctx->actor, \Illuminate\Support\Carbon::instance($monthStart)
                ),
                'Amortise a prepaid expense — '.$monthStart->format('M Y'),
                'prepaid amortisation'
            );

            if ($entry) {
                $verify->ledgerBalanced('Prepaid amortisation — '.$monthStart->format('M Y'));
            }
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Factoring — collection and recourse
    // ══════════════════════════════════════════════════════════════

    /**
     * A factored invoice either gets collected by the factor, or comes
     * back to the company. Under a WITH-RECOURSE agreement the second
     * one matters most: the company owes the advance back.
     */
    private function settleFactoring(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify = new Verify($this->log, $ctx->companyId);

        $open = FactoringTransaction::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('status', FactoringTransaction::STATUS_PENDING)
            ->orderBy('id')
            ->get();

        foreach ($open as $transaction) {
            $date = $monthStart->copy()->addDays(20)->toDateString();

            // Every third FACTORED INVOICE across the whole run comes
            // back under recourse — counted on the company, not on
            // this month's batch. Indexing within the batch meant
            // recourse needed four pending transactions in one month
            // and therefore never happened at all.
            $ctx->factoringSettlements++;
            $recourse = ($ctx->factoringSettlements % 3) === 0;

            if ($recourse) {
                $this->gate->submit(
                    \App\Http\Requests\FinancialInstitutions\MarkFactoringRejectedRequest::class,
                    [
                        'rejection_date'  => $date,
                        'bank_account_id' => $ctx->bankAccounts[0]->id,
                        'notes'           => 'Customer did not pay — recourse invoked',
                    ],
                    fn (array $data) => app(FactoringTransactionService::class)->markRejected(
                        $ctx->companyId,
                        $transaction,
                        $data + ['settlement_bank_account_id' => $ctx->bankAccounts[0]->id],
                        $ctx->actor
                    ),
                    'Factored invoice returned under recourse — '.$monthStart->format('M Y'),
                    'factoring recourse'
                );
            } else {
                $this->gate->submit(
                    \App\Http\Requests\FinancialInstitutions\MarkFactoringCollectedRequest::class,
                    [
                        'collection_date' => $date,
                        'bank_account_id' => $ctx->bankAccounts[0]->id,
                    ],
                    fn (array $data) => app(FactoringTransactionService::class)->markCollected(
                        $ctx->companyId,
                        $transaction,
                        [
                            'collection_date'            => $data['collection_date'],
                            'settlement_bank_account_id' => $ctx->bankAccounts[0]->id,
                            'notes'                      => 'Factor collected from the customer',
                        ],
                        $ctx->actor
                    ),
                    'Factored invoice collected — '.$monthStart->format('M Y'),
                    'factoring collection'
                );
            }

            $verify->ledgerBalanced('Factoring settlement — '.$monthStart->format('M Y'));
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Letters of guarantee — release and call-in
    // ══════════════════════════════════════════════════════════════

    /**
     * A guarantee ends one of two ways: released when the obligation
     * is met, or liquidated when the beneficiary calls it in. The
     * second is the expensive one and the one nobody tests.
     */
    private function releaseGuarantees(CycleContext $ctx, Carbon $monthStart): void
    {
        $verify = new Verify($this->log, $ctx->companyId);

        $running = LgIssuance::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('status', 'running')
            ->orderBy('id')
            ->limit(2)
            ->get();

        foreach ($running as $index => $issuance) {
            $date = $monthStart->copy()->addDays(15)->toDateString();

            if (($ctx->guaranteesHandled % 3) === 2) {
                $this->gate->submit(
                    \App\Http\Requests\FinancialInstitutions\LiquidateLgIssuanceRequest::class,
                    [
                        'liquidated_at'                    => $date,
                        'notes'                            => 'Beneficiary called the guarantee in',
                        'liquidation_shortfall_account_id' => $ctx->bankAccounts[0]->id,
                        'liquidation_expense_account_id'   => $ctx->expenseAccounts['Professional Fees'],
                    ],
                    fn (array $data) => app(LgIssuanceService::class)->liquidate($issuance, $data, $ctx->actor),
                    'Guarantee called in — '.$monthStart->format('M Y'),
                    'LG liquidation'
                );
            } else {
                $this->gate->submit(
                    \App\Http\Requests\FinancialInstitutions\CancelLgIssuanceRequest::class,
                    [
                        'cancelled_at' => $date,
                        'notes'        => 'Obligation met — guarantee released',
                    ],
                    fn (array $data) => app(LgIssuanceService::class)->cancel($issuance, $data, $ctx->actor),
                    'Guarantee released — '.$monthStart->format('M Y'),
                    'LG release'
                );
            }

            $ctx->guaranteesHandled++;
            $verify->ledgerBalanced('Guarantee settlement — '.$monthStart->format('M Y'));
        }
    }
}
