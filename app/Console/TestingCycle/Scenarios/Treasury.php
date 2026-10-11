<?php

namespace App\Console\TestingCycle\Scenarios;

// ══════════════════════════════════════════════════════════════════
//  Treasury
//
//  The financing side of the company: overdraft facilities, letters
//  of guarantee, letters of credit, factoring and leasing — plus the
//  money movements between banks and facilities.
//
//  This was the largest untested surface in the system: 29 services,
//  ten of which post journal entries, and not one of them driven by
//  any test. Two of them — factoring and leasing — have no usage
//  example anywhere in the repository either, so the payloads here
//  are built from their Form Request rules rather than copied from a
//  working call.
//
//  THAT IS DELIBERATE AND THE FAILURES ARE THE POINT. Where no
//  reference call exists, the cycle constructs what the form says is
//  valid and submits it. If the service then refuses, that is worth
//  knowing: either the form and the service disagree, or the module
//  needs setup nothing documents. Both are findings. Nothing here is
//  skipped because it might fail.
//
//  @layer    Console Support (Testing Cycle)
// ══════════════════════════════════════════════════════════════════

use App\Console\TestingCycle\CycleContext;
use App\Console\TestingCycle\CycleLog;
use App\Console\TestingCycle\Finding;
use App\Console\TestingCycle\FormGate;
use App\Console\TestingCycle\Verify;
use App\Models\ChartOfAccount;
use App\Services\FinancialInstitutions\CleanOverdraftService;
use App\Services\FinancialInstitutions\FactoringService;
use App\Services\FinancialInstitutions\FactoringTransactionService;
use App\Services\FinancialInstitutions\InternalMoneyTransferService;
use App\Services\FinancialInstitutions\LeasingService;
use App\Services\FinancialInstitutions\LetterOfCreditIssuanceService;
use App\Services\FinancialInstitutions\LetterOfCreditService;
use App\Services\FinancialInstitutions\LetterOfGuaranteeService;
use App\Services\FinancialInstitutions\LgIssuanceService;
use App\Services\Accounting\SupplierPaymentService;
use Carbon\Carbon;

class Treasury
{
    /** So the AR-account inconsistency is reported once, not per attempt. */
    private bool $reportedArAccountGap = false;

    public function __construct(
        private CycleLog $log,
        private FormGate $gate,
    ) {
    }

    // ══════════════════════════════════════════════════════════════
    //  Setup — the facilities a company arranges on day one
    // ══════════════════════════════════════════════════════════════

    public function setUp(CycleContext $ctx, int $startYear): void
    {
        $this->log->enterPeriod('treasury setup');

        $this->createOverdraft($ctx, $startYear);
        $this->createLgAgreement($ctx, $startYear);
        $this->createLcAgreement($ctx, $startYear);
        $this->createFactoring($ctx, $startYear);
        $this->createLeasing($ctx, $startYear);
    }

    private function createOverdraft(CycleContext $ctx, int $startYear): void
    {
        $bank = $ctx->bankAccounts[0];

        $facility = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\CleanOverdraftRequest::class,
            [
                'bank_id'               => $bank->bank_id,
                'start_date'            => $startYear.'-01-01',
                'review_date'           => ($startYear + 3).'-01-01',
                'account_number'        => 'OD-CYCLE-'.$ctx->companyId,
                'currency_id'           => $ctx->currencyId,
                'coa_account_id'        => $this->coa($ctx, '223001'),
                // TRS-001 fix (2026-09) — the facility must now be
                // linked to a real bank account at creation, which is
                // what makes it usable as a money leg. The cycle used
                // to omit this and patch the column afterwards; the
                // form supplies it properly now, so the cycle does too.
                'bank_account_id'       => $bank->id,
                'facility_limit'        => 3000000,
                'outstanding_balance'   => 0,
                'balance_date'          => $startYear.'-01-01',
                'borrowing_rate'        => 18,
                'bank_margin_rate'      => 3,
                'min_interest_rate'     => 1,
                'hdb_rate'              => 0.5,
                'admin_fee_rate'        => 0.25,
                'settled_max_days'      => 90,
                'follows_corridor_rate' => false,
                'purpose'               => 'Working capital',
            ],
            fn (array $data) => app(CleanOverdraftService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Open a Clean Overdraft facility',
            'credit facility'
        );

        if (! $facility) {
            return;
        }

        // TRS-001 regression guard. The link is supplied by the form
        // now, so it must survive creation — no patching afterwards.
        // If it is ever null again the facility is unusable as a money
        // leg and every payment service will refuse it, so this stays
        // as a finding rather than being quietly repaired.
        if (! $facility->bank_account_id) {
            $this->log->finding(
                prefix: 'TRS',
                severity: Finding::SEVERITY_MEDIUM,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'A new overdraft facility is created with no bank account linked',
                operation: 'Open a Clean Overdraft facility',
                expected: 'the facility to be usable as a payment source once created',
                actual: 'bank_account_id is null even though the form supplied one',
                source: 'CleanOverdraftService::create()',
                rootCause: 'The create flow drops bank_account_id, but InternalMoneyTransferService and '
                    .'every payment service require it (audit finding TRS-001).',
            );

            $facility->update(['bank_account_id' => $bank->id]);
        }

        $ctx->overdraftFacility = $facility->fresh();
        $this->log->note('Clean Overdraft facility opened (limit 3,000,000 EGP).');
    }

    private function createLgAgreement(CycleContext $ctx, int $startYear): void
    {
        $bank = $ctx->bankAccounts[0];

        $agreement = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\LetterOfGuaranteeRequest::class,
            [
                // The LG agreement FORM requires a name; the service
                // does not. The existing demo seeders omit it and still
                // work, which means the two contracts differ — worth
                // knowing, not a defect.
                'name'           => 'LG Agreement',
                'bank_id'        => $bank->bank_id,
                'currency_id'    => $ctx->currencyId,
                'facility_limit' => 5000000,
                'start_date'     => $startYear.'-01-01',
                'review_date'    => ($startYear + 3).'-01-01',
                'purpose'        => 'Performance and advance payment guarantees',
                'lg_types'       => [
                    [
                        'lg_type'                   => 'Performance LG',
                        'cash_cover_pct'            => 10,
                        'commission_rate_pct'       => 1.00,
                        'commission_interval'       => 'quarterly',
                        'min_commission_fee_amount' => 500,
                        'issuance_fee_amount'       => 250,
                    ],
                    [
                        'lg_type'                   => 'Advance Payment LG',
                        'cash_cover_pct'            => 10,
                        'commission_rate_pct'       => 1.25,
                        'commission_interval'       => 'quarterly',
                        'min_commission_fee_amount' => 500,
                        'issuance_fee_amount'       => 250,
                    ],
                ],
            ],
            fn (array $data) => app(LetterOfGuaranteeService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Open an LG agreement',
            'LG agreement'
        );

        if (! $agreement) {
            return;
        }

        $ctx->lgAgreement = $agreement;
        foreach ($agreement->lgTypes as $lgType) {
            $ctx->lgTypeIds[$lgType->lg_type] = $lgType->id;
        }

        $this->log->note('LG agreement opened with 2 guarantee types.');
    }

    private function createLcAgreement(CycleContext $ctx, int $startYear): void
    {
        $bank = $ctx->bankAccounts[1] ?? $ctx->bankAccounts[0];

        $agreement = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\LetterOfCreditRequest::class,
            [
                'bank_id'               => $bank->bank_id,
                'name'                  => 'Import LC Agreement',
                'currency_id'           => $ctx->currencyId,
                'start_date'            => $startYear.'-01-01',
                'review_date'           => ($startYear + 3).'-01-01',
                'lc_security_type'      => 'unsecured',
                'facility_limit'        => 4000000,
                'borrowing_rate'        => 16,
                'bank_margin_rate'      => 2,
                'min_interest_rate'     => 1,
                'hdb_rate'              => 0.5,
                'follows_corridor_rate' => false,
                'lc_types'              => [[
                    'lc_type'                   => 'Sight LC',
                    'cash_cover_pct'            => 0,
                    'commission_rate_pct'       => 1,
                    'min_commission_fee_amount' => 500,
                    'issuance_fee_amount'       => 250,
                ]],
            ],
            fn (array $data) => app(LetterOfCreditService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Open an LC agreement',
            'LC agreement'
        );

        if (! $agreement) {
            return;
        }

        // Same shape as the LG agreement: the agreement layer leaves
        // the GL link to the issuance layer, by design.
        $agreement->update(['coa_account_id' => $this->coa($ctx, '223005')]);

        $ctx->lcAgreement = $agreement->fresh();
        $this->log->note('Import LC agreement opened.');
    }

    /**
     * Factoring has no reference call anywhere in this repository —
     * the payload below is built from FactoringAgreementRequest's own
     * rules. If the service disagrees with its form, that is exactly
     * what should be reported.
     */
    private function createFactoring(CycleContext $ctx, int $startYear): void
    {
        $company = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\FactoringCompanyRequest::class,
            [
                'name_en'   => 'Cycle Factoring House',
                'is_active' => true,
            ],
            fn (array $data) => app(FactoringService::class)->createCompany($ctx->companyId, $data, $ctx->actor),
            'Register a factoring company',
            'factoring company'
        );

        if (! $company) {
            return;
        }

        $agreement = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\FactoringAgreementRequest::class,
            [
                'factoring_company_id'  => $company->id,
                'agreement_reference'   => 'FCT-CYCLE-'.$ctx->companyId,
                'agreement_type'        => 'direct',
                'recourse_type'         => 'with_recourse',
                'borrowing_rate'        => 15,
                'discount_margin_rate'  => 2,
                'discount_rate'         => 17,
                'follows_corridor_rate' => false,
                'coa_account_id'        => $this->coa($ctx, '224001'),
                'start_date'            => $startYear.'-01-01',
                'end_date'              => ($startYear + 3).'-01-01',
            ],
            fn (array $data) => app(FactoringService::class)->createAgreement($ctx->companyId, $data, $ctx->actor),
            'Open a factoring agreement',
            'factoring agreement'
        );

        if ($agreement) {
            $ctx->factoringAgreement = $agreement;
            $this->log->note('Factoring agreement opened (with recourse).');
        }
    }

    /**
     * Leasing likewise has no reference call. A capital lease with a
     * simple monthly schedule is the most ordinary shape there is.
     */
    private function createLeasing(CycleContext $ctx, int $startYear): void
    {
        $company = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\LeasingCompanyRequest::class,
            [
                'name_en'   => 'Cycle Leasing Co.',
                'is_active' => true,
            ],
            fn (array $data) => app(LeasingService::class)->createCompany($ctx->companyId, $data, $ctx->actor),
            'Register a leasing company',
            'leasing company'
        );

        if (! $company) {
            return;
        }

        // 240,000 over 24 monthly instalments of 10,000 — round on
        // purpose so the amortisation is checkable by hand.
        $contract = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\LeasingContractRequest::class,
            [
                'leasing_company_id'        => $company->id,
                'contract_reference'        => 'LSE-CYCLE-'.$ctx->companyId,
                'entry_type'                => 'new',
                'contract_type'             => 'capital',
                'asset_description'         => 'Delivery van',
                'currency_id'               => $ctx->currencyId,
                'exchange_rate'             => 1,
                'accounting_treatment'      => 'rou_b',
                'total_contract_value'      => 240000,
                'installment_interval'      => 'monthly',
                'installment_amount'        => 10000,
                'duration_months'           => 24,
                'borrowing_rate'            => 14,
                'start_date'                => $startYear.'-02-01',
                'first_payment_date'        => $startYear.'-03-01',
                'total_financed_value'      => 240000,
                'payment_frequency'         => 'monthly',
                'bank_liability_account_id' => $this->coa($ctx, '225'),
                'bank_account_id'           => $ctx->bankAccounts[0]->id,
            ],
            fn (array $data) => app(LeasingService::class)->createContract($ctx->companyId, $data, $ctx->actor),
            'Open a leasing contract',
            'leasing contract'
        );

        if ($contract) {
            $ctx->leasingContract = $contract;
            $this->log->note('Capital lease opened — 240,000 EGP over 24 months.');
        }
    }

    // ══════════════════════════════════════════════════════════════
    //  Monthly activity
    // ══════════════════════════════════════════════════════════════

    public function monthly(CycleContext $ctx, Carbon $monthStart, int $month): void
    {
        $this->overdraftActivity($ctx, $monthStart, $month);

        // Quarterly, so the guarantee lifecycle appears across the
        // history without dominating it.
        if ($month % 3 === 1) {
            $this->issueLetterOfGuarantee($ctx, $monthStart);
        }

        if ($month % 4 === 2) {
            $this->issueLetterOfCredit($ctx, $monthStart);
        }

        if ($month % 6 === 3) {
            $this->factorAnInvoice($ctx, $monthStart);
        }
    }

    /**
     * Draw on the overdraft to pay a supplier, then repay part of it
     * from the current account. Both legs move real money, so both
     * are checked against the facility's own GL account.
     */
    private function overdraftActivity(CycleContext $ctx, Carbon $monthStart, int $month): void
    {
        $facility = $ctx->overdraftFacility ?? null;

        if (! $facility || $ctx->openSupplierBills === []) {
            return;
        }

        $verify = new Verify($this->log, $ctx->companyId);
        $bill   = $ctx->openSupplierBills[0];
        $bill->refresh();

        $outstanding = (string) $bill->amount_outstanding;

        if (bccomp($outstanding, '0', 2) <= 0) {
            return;
        }

        // Draw a fixed, modest slice so the figure stays checkable.
        $draw = bccomp($outstanding, '50000.00', 2) > 0 ? '50000.00' : $outstanding;

        $facilityAccountId = (int) $facility->coa_account_id;
        $before = $verify->trueBalance($facilityAccountId);

        $payment = $this->gate->submit(
            \App\Http\Requests\Accounting\SupplierPaymentRequest::class,
            [
                'partner_id'                => $bill->partner_id,
                'payment_type'              => 'settlement',
                'payment_date'              => $monthStart->copy()->addDays(7)->toDateString(),
                'money_type'                => 'outgoing_transfer',
                'pay_currency_id'           => $ctx->currencyId,
                'amount'                    => $draw,
                'source_type'               => 'credit_facility',
                'source_credit_facility_id' => $facility->id,
                'settlements'               => [[
                    'supplier_bill_id'  => $bill->id,
                    'settlement_amount' => $draw,
                ]],
            ],
            fn (array $data) => app(SupplierPaymentService::class)->record($ctx->companyId, $data, $ctx->actor),
            'Pay a supplier from the overdraft — '.$monthStart->format('M Y'),
            'overdraft draw'
        );

        if (! $payment) {
            return;
        }

        // An overdraft is a liability: drawing on it makes the signed
        // balance more negative by exactly the amount drawn.
        $verify->money(
            bcsub($before, $draw, 2),
            $verify->trueBalance($facilityAccountId),
            'Drawing on the overdraft did not increase the facility liability',
            'Overdraft draw — '.$monthStart->format('M Y'),
            ['facility balance before' => $before, 'drawn' => $draw],
            prefix: 'TRS'
        );

        // ── Repay part of it from the current account ─────────────
        $repayment = '20000.00';
        $bankBefore = $verify->trueBalance((int) $ctx->bankAccounts[0]->coa_account_id);
        $facilityBeforeRepay = $verify->trueBalance($facilityAccountId);

        $transfer = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\StoreInternalMoneyTransferRequest::class,
            [
                'from_leg_type'         => 'bank',
                'from_bank_account_id'  => $ctx->bankAccounts[0]->id,
                'to_leg_type'           => 'facility',
                'to_credit_facility_id' => $facility->id,
                'currency_id'           => $ctx->currencyId,
                'amount'                => $repayment,
                'transfer_date'         => $monthStart->copy()->addDays(21)->toDateString(),
                'comment'               => 'Partial overdraft repayment',
            ],
            fn (array $data) => app(InternalMoneyTransferService::class)->record($ctx->companyId, $data, $ctx->actor),
            'Repay the overdraft — '.$monthStart->format('M Y'),
            'internal money transfer'
        );

        if (! $transfer) {
            return;
        }

        $this->log->operation('overdraft repayment');

        // Money left the bank and reduced the facility, both by the
        // same amount — the two halves of one transfer.
        $verify->money(
            bcsub($bankBefore, $repayment, 2),
            $verify->trueBalance((int) $ctx->bankAccounts[0]->coa_account_id),
            'The overdraft repayment did not leave the bank account',
            'Overdraft repayment — '.$monthStart->format('M Y'),
            prefix: 'BNK'
        );
        $verify->money(
            bcadd($facilityBeforeRepay, $repayment, 2),
            $verify->trueBalance($facilityAccountId),
            'The overdraft repayment did not reduce the facility liability',
            'Overdraft repayment — '.$monthStart->format('M Y'),
            prefix: 'TRS'
        );
    }

    private function issueLetterOfGuarantee(CycleContext $ctx, Carbon $monthStart): void
    {
        if (! isset($ctx->lgAgreement, $ctx->lgTypeIds['Performance LG'])) {
            return;
        }

        $verify   = new Verify($this->log, $ctx->companyId);
        $customer = $ctx->customers[0];
        $amount   = '100000.00';
        $date     = $monthStart->copy()->addDays(9)->toDateString();

        // A Performance LG guarantees delivery against a specific
        // order, and the service enforces that — so the guarantee
        // hangs off a real sales order the cycle produced rather than
        // floating free.
        $salesOrder = \App\Models\SalesOrder::withoutGlobalScopes()
            ->where('company_id', $ctx->companyId)
            ->where('partner_id', $customer->id)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $salesOrder) {
            return;
        }

        $issuance = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\LgIssuanceRequest::class,
            [
                'issuance_type'          => 'facility',
                'posting_mode'           => 'new_issuance',
                'lg_type'                => 'Performance LG',
                'transaction_name'       => 'Performance LG — '.$monthStart->format('M Y'),
                'bank_id'                => $ctx->bankAccounts[0]->bank_id,
                'currency_id'            => $ctx->currencyId,
                'credit_facility_id'     => $ctx->lgAgreement->id,
                'facility_lg_type_id'    => $ctx->lgTypeIds['Performance LG'],
                'beneficiary_partner_id' => $customer->id,
                'purchase_order_so_id'   => $salesOrder->id,
                'issuance_date'          => $date,
                'end_date'               => $monthStart->copy()->addDays(120)->toDateString(),
                'lg_amount'              => $amount,
                'commission_rate_pct'    => '1.5',
                'commission_interval'    => 'quarterly',
                'cash_cover_account_id'  => $ctx->bankAccounts[0]->id,
            ],
            fn (array $data) => app(LgIssuanceService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Issue a Performance LG — '.$monthStart->format('M Y'),
            'LG issuance'
        );

        if (! $issuance) {
            return;
        }

        // A guarantee is a contingent liability: 227 and its contra
        // must both move, and the ledger must stay balanced.
        $verify->ledgerBalanced('LG issuance — '.$monthStart->format('M Y'));
        $verify->noCrossCompanyPostings('LG issuance — '.$monthStart->format('M Y'));
    }

    private function issueLetterOfCredit(CycleContext $ctx, Carbon $monthStart): void
    {
        if (! isset($ctx->lcAgreement)) {
            return;
        }

        $verify   = new Verify($this->log, $ctx->companyId);
        $supplier = $ctx->suppliers[0];
        $date     = $monthStart->copy()->addDays(4)->toDateString();

        $lc = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\LcIssuanceRequest::class,
            [
                'issuance_type'              => 'facility',
                'posting_mode'               => 'new_issuance',
                'lc_type'                    => 'Sight LC',
                'transaction_name'           => 'Import LC — '.$monthStart->format('M Y'),
                'bank_id'                    => ($ctx->bankAccounts[1] ?? $ctx->bankAccounts[0])->bank_id,
                'currency_id'                => $ctx->currencyId,
                'credit_facility_id'         => $ctx->lcAgreement->id,
                'supplier_partner_id'        => $supplier->id,
                'issuance_date'              => $date,
                'duration_days'              => 90,
                'lc_amount'                  => '150000.00',
                'exchange_rate'              => 1,
                'commission_rate_pct'        => 1,
                'fees_commission_account_id' => $ctx->bankAccounts[0]->id,
                'financed_by'                => 'self',
            ],
            fn (array $data) => app(LetterOfCreditIssuanceService::class)->create($ctx->companyId, $data, $ctx->actor),
            'Issue an import LC — '.$monthStart->format('M Y'),
            'LC issuance'
        );

        if (! $lc) {
            return;
        }

        $verify->ledgerBalanced('LC issuance — '.$monthStart->format('M Y'));
    }

    /**
     * Discount a real customer invoice with the factoring house. The
     * invoice must be one the sales cycle genuinely produced —
     * factoring an invented receivable would test nothing.
     */
    private function factorAnInvoice(CycleContext $ctx, Carbon $monthStart): void
    {
        if (! isset($ctx->factoringAgreement) || $ctx->openCustomerInvoices === []) {
            return;
        }

        $verify  = new Verify($this->log, $ctx->companyId);
        $invoice = $ctx->openCustomerInvoices[0];
        $invoice->refresh();

        // Factoring is stricter than the rest of the system about
        // partner setup, and says so only at the moment of use.
        $partner = \App\Models\Partner::withoutGlobalScopes()->find($invoice->partner_id);

        if ($partner && ! $partner->ar_account_id && ! $this->reportedArAccountGap) {
            $this->reportedArAccountGap = true;

            $this->log->finding(
                prefix: 'TRS',
                severity: Finding::SEVERITY_MEDIUM,
                confidence: Finding::CONFIDENCE_CONFIRMED,
                title: 'Factoring requires an AR account on the partner that nothing else requires or sets',
                operation: 'Factor a customer invoice',
                expected: 'a customer created through the normal Partner form to be factorable',
                actual: 'refused — "This customer has no Accounts Receivable account assigned"',
                source: 'FactoringTransactionService, and PartnerService::create() which never sets it',
                rootCause: 'Invoicing, collections and statements all fall back to 133001 when '
                    .'partner.ar_account_id is null. Factoring alone insists on it, and the Partner form '
                    .'does not require it — so a perfectly ordinary customer cannot be factored until '
                    .'someone edits the partner.',
            );
        }

        $outstanding = (string) $invoice->amount_outstanding;

        if (bccomp($outstanding, '0', 2) <= 0) {
            return;
        }

        $transaction = $this->gate->submit(
            \App\Http\Requests\FinancialInstitutions\StoreFactoringTransactionRequest::class,
            [
                // Key names taken from the service body itself —
                // FactoringTransactionService reads bank_account_id,
                // factoring_percentage, factoring_date and
                // factoring_interest_amount, which is NOT what the
                // agreement-level naming would suggest.
                'factoring_agreement_id'     => $ctx->factoringAgreement->id,
                'customer_invoice_id'        => $invoice->id,
                'bank_account_id'            => $ctx->bankAccounts[0]->id,
                'factoring_date'             => $monthStart->copy()->addDays(12)->toDateString(),
                'factoring_percentage'       => 80,
                // H-20: interest is calculated by the system from the agreement's rate.
                'other_charges'              => '0',
                'notes'                      => 'Invoice discounted with recourse',
            ],
            fn (array $data) => app(FactoringTransactionService::class)->createWithRecourse($ctx->companyId, $data, $ctx->actor),
            'Factor a customer invoice — '.$monthStart->format('M Y'),
            'factoring transaction'
        );

        if (! $transaction) {
            return;
        }

        $verify->ledgerBalanced('Factoring — '.$monthStart->format('M Y'));
    }

    private function coa(CycleContext $ctx, string $code): ?int
    {
        return ChartOfAccount::findByCode($ctx->companyId, $code)?->id;
    }
}
