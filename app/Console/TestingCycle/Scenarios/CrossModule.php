<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  CrossModule
//
//  The checks no single module can make about itself.
//
//  Every module in this ERP keeps its own register — a cheque book,
//  an asset register, a list of open invoices, a stock balance — and
//  every one of those registers is a SUBSIDIARY of a control account
//  in the general ledger. Individually each module can be perfectly
//  self-consistent; the question this scenario asks is whether they
//  still agree with the ledger they all post into.
//
//  This is where the expensive defects live, and it is the last check
//  a system usually gets, because it needs a company with a full year
//  of interlocking history before it means anything. A control
//  account that has drifted from its register is money the accounts
//  say exists and the module says does not, and nobody notices until
//  someone tries to reconcile a bank statement or explain a balance
//  to an auditor.
//
//  EACH CHECK IS AN ACCOUNTING IDENTITY, stated as such, so a failure
//  names the identity rather than reporting that two numbers differ.
//  Where a difference is expected by design, the design is named
//  instead of the check being dropped.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\Verify;
use App\Models\ChequePayable;
use App\Models\ChequeReceivable;
use App\Models\FixedAsset;
use App\Models\SupplierBill;

class CrossModule
{
    public function __construct(
        private CycleLog $log,
    ) {
    }

    public function run(CycleContext $ctx): void
    {
        $this->log->enterPeriod('cross-module reconciliation');
        $this->log->note('Reconciling every module\'s own register against the ledger it posts into.');

        $verify = new Verify($this->log, $ctx->companyId);

        $this->chequeBooks($ctx, $verify);
        $this->assetRegister($ctx, $verify);
        $this->goodsReceivedNotInvoiced($ctx, $verify);
    }

    // ══════════════════════════════════════════════════════════════
    //  Identity — the cheque book == Notes Receivable / Payable
    // ══════════════════════════════════════════════════════════════

    /**
     * A cheque in hand is an asset the customer has not yet paid.
     * The drawer of cheques and account 134 are the same money seen
     * from two sides; likewise cheques we have written and 222.
     *
     * Only cheques still LIVE count — one that has cleared, bounced
     * or been paid has left the register and the ledger together.
     */
    private function chequeBooks(CycleContext $ctx, Verify $verify): void
    {
        $inHand = (string) (ChequeReceivable::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->whereIn('status', [
                ChequeReceivable::STATUS_RECEIVED,
                ChequeReceivable::STATUS_SENT_FOR_COLLECTION,
            ])
            ->sum('cheque_amount') ?: '0.00');

        $ledger = $this->groupBalance($ctx, $verify, '134');

        if (bccomp($inHand, '0', 2) !== 0 || bccomp($ledger, '0', 2) !== 0) {
            $verify->money(
                $inHand,
                $ledger,
                'The cheques the company is holding do not add up to the Notes Receivable balance',
                'Cross-module reconciliation',
                [
                    'cheques still in hand' => $inHand,
                    'GL 134 Notes Receivable' => $ledger,
                ],
                prefix: 'REC'
            );
        }

        $issued = (string) (ChequePayable::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('status', ChequePayable::STATUS_ISSUED)
            ->sum('cheque_amount') ?: '0.00');

        $payableLedger = $this->groupBalance($ctx, $verify, '222');

        if (bccomp($issued, '0', 2) !== 0 || bccomp($payableLedger, '0', 2) !== 0) {
            $verify->money(
                $issued,
                $this->magnitude($payableLedger),
                'The cheques the company has written do not add up to the Notes Payable balance',
                'Cross-module reconciliation',
                [
                    'cheques still outstanding' => $issued,
                    'GL 222 Notes Payable'      => $payableLedger,
                ],
                prefix: 'REC'
            );
        }

        $this->log->operation('cross-module reconciliation');
    }

    // ══════════════════════════════════════════════════════════════
    //  Identity — the asset register == cost less depreciation
    // ══════════════════════════════════════════════════════════════

    /**
     * Two separate identities, checked separately because they fail
     * for different reasons:
     *
     *   the register's COST     == the fixed asset accounts
     *   the register's DEPRECIATION == the accumulated depreciation
     *                                  accounts
     *
     * Netting them first would let a cost error and a depreciation
     * error of the same size cancel out and report a clean book.
     */
    private function assetRegister(CycleContext $ctx, Verify $verify): void
    {
        $assets = FixedAsset::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->whereNot('status', 'disposed')
            ->get();

        if ($assets->isEmpty()) {
            return;
        }

        $cost         = '0.00';
        $depreciation = '0.00';
        $costAccounts = [];
        $accumAccounts = [];

        foreach ($assets as $asset) {
            $cost         = bcadd($cost, (string) $asset->cost_basis, 2);
            $depreciation = bcadd($depreciation, (string) $asset->accumulated_depreciation, 2);

            if ($asset->fixed_asset_account_id) {
                $costAccounts[$asset->fixed_asset_account_id] = true;
            }

            if ($asset->accumulated_depreciation_account_id) {
                $accumAccounts[$asset->accumulated_depreciation_account_id] = true;
            }
        }

        $ledgerCost = '0.00';
        foreach (array_keys($costAccounts) as $id) {
            $ledgerCost = bcadd($ledgerCost, $verify->reportedBalance((int) $id), 2);
        }

        $ledgerAccum = '0.00';
        foreach (array_keys($accumAccounts) as $id) {
            $ledgerAccum = bcadd($ledgerAccum, $verify->reportedBalance((int) $id), 2);
        }

        $verify->money(
            $cost,
            $ledgerCost,
            'The fixed asset register does not agree with the asset accounts in the ledger',
            'Cross-module reconciliation',
            [
                'register cost'  => $cost,
                'ledger cost'    => $ledgerCost,
                'assets counted' => (string) $assets->count(),
            ],
            prefix: 'REC'
        );

        $verify->money(
            $depreciation,
            $this->magnitude($ledgerAccum),
            'The depreciation recorded against the assets does not agree with the accumulated depreciation accounts',
            'Cross-module reconciliation',
            [
                'register depreciation' => $depreciation,
                'ledger accumulated'    => $ledgerAccum,
            ],
            prefix: 'REC'
        );

        $this->log->operation('cross-module reconciliation');
    }

    // ══════════════════════════════════════════════════════════════
    //  Identity — GRNI == goods received but not yet billed
    // ══════════════════════════════════════════════════════════════

    /**
     * Goods Received Not Invoiced is the three-way match's memory: a
     * receipt credits it, the supplier's bill debits it back. Its
     * balance is therefore, by construction, exactly the value of
     * everything received and not yet billed.
     *
     * A GRNI balance that will not reduce to that figure is the
     * classic year-end problem — an accrual nobody can explain and
     * nobody can clear.
     */
    private function goodsReceivedNotInvoiced(CycleContext $ctx, Verify $verify): void
    {
        $accountId = $verify->accountId('233');

        if (! $accountId) {
            return;
        }

        $balance = $verify->reportedBalance($accountId);

        // Nothing outstanding is a perfectly good answer — it means
        // every receipt has been billed.
        if (bccomp($balance, '0', 2) === 0) {
            $this->log->checkPassed();
            $this->log->operation('cross-module reconciliation');

            return;
        }

        // Every posted bill in the company, against every receipt.
        // The identity only holds in aggregate, which is the level a
        // control account is reconciled at anyway.
        $billed = (string) (SupplierBill::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('status', 'posted')
            ->where('bill_type', 'goods')
            ->sum('bill_value_functional') ?: '0.00');

        $this->log->finding(
            prefix: 'REC',
            severity: Finding::SEVERITY_MEDIUM,
            confidence: Finding::CONFIDENCE_UNVERIFIED,
            title: 'Goods Received Not Invoiced does not clear at the end of the cycle',
            operation: 'Cross-module reconciliation',
            expected: 'GRNI to hold only receipts that genuinely have no supplier bill yet',
            actual: "a balance of {$balance} remains",
            source: 'GoodsReceiptNoteService and SupplierBillService, account 233',
            rootCause: 'Reported UNVERIFIED rather than confirmed: a non-zero GRNI is legitimate whenever '
                .'goods were received in the final period and the supplier has not billed them yet, which '
                .'this cycle deliberately produces. It is recorded so the figure is looked at rather than '
                .'assumed, because the same balance is what an unclearable GRNI looks like.',
            context: [
                'GRNI balance'           => $balance,
                'goods bills posted'     => $billed,
            ],
        );

        $this->log->operation('cross-module reconciliation');
    }

    // ══════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════

    private function groupBalance(CycleContext $ctx, Verify $verify, string $prefix): string
    {
        $total = '0.00';

        $accounts = \App\Models\ChartOfAccount::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('company_id', $ctx->companyId)->orWhereNull('company_id'))
            ->where('code', 'like', $prefix.'%')
            ->pluck('id');

        foreach ($accounts as $id) {
            $total = bcadd($total, $verify->reportedBalance((int) $id), 2);
        }

        return $total;
    }

    private function magnitude(string $balance): string
    {
        return bccomp($balance, '0', 2) < 0 ? bcmul($balance, '-1', 2) : $balance;
    }
}
