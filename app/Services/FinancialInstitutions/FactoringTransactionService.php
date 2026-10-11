<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  FactoringTransactionService
//
//  Factoring Transactions — Direct Factoring, With Recourse
//  disbursement (createWithRecourse()) plus the lookup/calculate
//  endpoints the Create form's cascading dropdowns call. Ported from
//  CashVero's FactoringWithRecourseController, adapted to Vero CFO's
//  real AR (CustomerInvoice) and accounting engine (JournalEntryService).
//
//  Posting rule — Vero CFO Chart of Accounts doc, Section 8.2
//  (Direct Factoring — With Recourse):
//      Dr  Bank Account (its own coa_account_id)   — Received Amount
//      Dr  557 Factoring Fees & Discount            — Interest (own line)
//      Dr  557 Factoring Fees & Discount            — Other Charges (own line)
//      Cr  Agreement's coa_account_id (224001)      — Factoring Amount
//  Interest and Other Charges post as two separate lines under the
//  SAME 557 account (confirmed with Mahmoud) — kept visible on their
//  own GL rows for a clearer audit trail, even though COA doc rule 15
//  says one account covers both. Customer AR is deliberately left
//  untouched — risk hasn't transferred yet ("with recourse"), so the
//  receivable stays on the books exactly as the COA doc's note says.
//
//  received_amount is ALWAYS derived here (Factoring Amount − Interest
//  − Other Charges), never trusted from the request — see
//  StoreFactoringTransactionRequest's header for why: unlike
//  CashVero, Vero CFO's JournalEntryService rejects any entry that
//  doesn't balance to the cent.
//
//  Collect / Reject are now built (markCollected/revertCollected,
//  markRejected/revertRejected) — Mahmoud confirmed both posting
//  rules and the "difference/repayment bank account defaults to the
//  disbursement account, user can override" design.
//
//  createWithoutRecourse() + markCollected()'s without_recourse
//  branch handle Direct Factoring — Without Recourse (COA doc
//  Section 8.1): AR is derecognized immediately (invoice tagged
//  paid), the retained percentage posts to 140001 Due From Factoring
//  Companies instead of a liability account, and there's no Reject —
//  risk has already transferred, so markRejected() refuses to run
//  against a without_recourse agreement.
//
//  setToDraft()/delete() — same pattern as CustomerPaymentService and
//  LgIssuanceService: undoes a mistaken disbursement (wrong invoice,
//  percentage, or bank account) by reversing its journal entry and
//  restoring the invoice's paid amount, then allows deleting the
//  now-harmless empty shell. Only ever works from Pending — a
//  Collected/Rejected transaction must be undone via its own action
//  first.
//
//  @layer    Service
//  @depends  FactoringAgreement, FactoringTransaction, FactoringCompany,
//            CustomerInvoice, BankAccount, ChartOfAccount, Partner,
//            JournalEntryService, FiscalYearService, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\CustomerInvoice;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\FactoringAgreement;
use App\Models\FinancialInstitutions\FactoringCompany;
use App\Models\FinancialInstitutions\FactoringTransaction;
use App\Models\Partner;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FactoringTransactionService
{
    /** System account 557 — Factoring Fees & Discount (COA doc rule 15: covers both discount and admin fees). */
    private const FACTORING_FEES_ACCOUNT_CODE = '557';

    /** System account 140001 — Due From Factoring Companies (COA doc Section 8.1, retained % on a Without Recourse sale). */
    private const DUE_FROM_FACTORING_ACCOUNT_CODE = '140001';

    public function __construct(
        private JournalEntryService $journalEntryService,
        private \App\Services\FiscalYearService $fiscalYearService,
    ) {}

    // ── Create-form data ─────────────────────────────────────────

    public function factoringCompaniesForCompany(int $companyId)
    {
        return FactoringCompany::where('company_id', $companyId)->active()->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar']);
    }

    /**
     * Agreements eligible for a Direct Factoring disbursement of the
     * given recourse type ('with_recourse' | 'without_recourse'),
     * active, and belonging to the chosen Factoring Company. Mirrors
     * CashVero's contractsForCompany().
     */
    public function eligibleAgreements(int $companyId, int $factoringCompanyId, string $recourseType = 'with_recourse')
    {
        return FactoringAgreement::where('company_id', $companyId)
            ->where('factoring_company_id', $factoringCompanyId)
            ->where('agreement_type', 'direct')
            ->where('recourse_type', $recourseType)
            ->active()
            ->get(['id', 'agreement_reference', 'discount_rate', 'eligible_partner_id', 'coa_account_id']);
    }

    /**
     * Customers eligible under the chosen agreement — locked to a
     * single partner if the agreement's own eligible_partner_id is
     * set (the field finally gets used for its intended purpose),
     * otherwise every active customer-type partner.
     */
    public function eligibleCustomers(int $companyId, FactoringAgreement $agreement)
    {
        if ($agreement->eligible_partner_id) {
            return Partner::where('company_id', $companyId)
                ->where('id', $agreement->eligible_partner_id)
                ->get(['id', 'name_en', 'ar_account_id']);
        }

        return Partner::where('company_id', $companyId)->active()->ofType('customer')
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'ar_account_id']);
    }

    /**
     * Open invoices for the chosen customer, fully unpaid (matches
     * CashVero's own collected_amount = 0 rule — a partially-paid
     * invoice can't be factored) and not already tied to a pending
     * factoring transaction under ANY agreement.
     */
    public function eligibleInvoices(int $companyId, int $partnerId)
    {
        $pendingInvoiceIds = FactoringTransaction::where('company_id', $companyId)
            ->pending()
            ->pluck('customer_invoice_id');

        return CustomerInvoice::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->open()
            ->whereColumn('amount_outstanding', 'invoice_value_functional')
            ->whereNotIn('id', $pendingInvoiceIds)
            ->orderBy('invoice_date')
            ->get(['id', 'invoice_code', 'invoice_date', 'invoice_value_functional', 'payment_term_id']);
    }

    public function activeBankAccounts(int $companyId)
    {
        return BankAccount::where('company_id', $companyId)->active()
            ->with('bank:id,name_en')
            ->orderBy('account_name')
            ->get(['id', 'bank_id', 'account_name', 'currency_id']);
    }

    /** Live preview for the form — same numbers store() will use, minus the actual posting. */
    public function calculate(int $companyId, int $customerInvoiceId, int $factoringAgreementId, string $factoringPercentage, string $otherCharges, string $factoringDate): array
    {
        $invoice   = CustomerInvoice::where('company_id', $companyId)->findOrFail($customerInvoiceId);
        $agreement = FactoringAgreement::where('company_id', $companyId)->findOrFail($factoringAgreementId);

        $invoiceDueDate = $this->resolveInvoiceDueDate($invoice);

        $amounts = FactoringTransaction::calculateAmounts(
            (string) $invoice->invoice_value_functional,
            FactoringTransaction::num($factoringPercentage),
            (string) $agreement->discount_rate,
            FactoringTransaction::num($otherCharges),
            $factoringDate,
            $invoiceDueDate->format('Y-m-d')
        );

        // Display-only numbers for the form's JSON — never used for posting (H-20).
        return [
            // Audit L5: exact decimal strings (JSON carries them as-is); the form only displays them.
            'invoice_amount'          => Money::round($invoice->invoice_value_functional),
            'invoice_due_date'        => $invoiceDueDate->format('Y-m-d'),
            'contract_interest_rate'  => Money::round($agreement->discount_rate, 6),
            'factoring_amount'          => Money::round($amounts['factoring_amount']),
            'diff_in_days'              => $amounts['diff_in_days'],
            'factoring_interest_amount' => Money::round($amounts['factoring_interest_amount']),
            'received_amount'           => Money::round($amounts['received_amount']),
        ];
    }

    // ── Create (disbursement) ────────────────────────────────────

    public function createWithRecourse(int $companyId, array $data, User $actor): FactoringTransaction
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $agreement = FactoringAgreement::where('company_id', $companyId)
                ->where('agreement_type', 'direct')
                ->where('recourse_type', 'with_recourse')
                ->findOrFail($data['factoring_agreement_id']);

            $invoice = CustomerInvoice::where('company_id', $companyId)
                ->open()
                ->findOrFail($data['customer_invoice_id']);

            if ($agreement->eligible_partner_id && $agreement->eligible_partner_id != $invoice->partner_id) {
                throw new \DomainException('This agreement is restricted to a specific customer — the chosen invoice belongs to someone else.');
            }

            $bankAccount = BankAccount::where('company_id', $companyId)->findOrFail($data['bank_account_id']);

            $invoiceDueDate = $this->resolveInvoiceDueDate($invoice);
            $invoiceAmount  = (string) $invoice->invoice_value_functional;

            $amounts = FactoringTransaction::calculateAmounts(
                $invoiceAmount,
                FactoringTransaction::num($data['factoring_percentage']),
                (string) $agreement->discount_rate,
                FactoringTransaction::num($data['other_charges'] ?? 0),
                $data['factoring_date'],
                $invoiceDueDate->format('Y-m-d')
            );

            // H-20 — interest is recomputed from the agreement's rate; a typed
            // figure is accepted only within 1% of it. received_amount is ALWAYS
            // re-derived (bcmath) from what actually gets posted, never taken
            // from the client (see StoreFactoringTransactionRequest header).
            $interestAmount = FactoringTransaction::resolveInterest($amounts['factoring_interest_amount'], $data['factoring_interest_amount'] ?? null);
            $otherCharges   = FactoringTransaction::bcRound(FactoringTransaction::num($data['other_charges'] ?? 0));
            $receivedAmount = bcsub(bcsub($amounts['factoring_amount'], $interestAmount, 2), $otherCharges, 2);

            $transaction = FactoringTransaction::create([
                'company_id'                => $companyId,
                'factoring_agreement_id'    => $agreement->id,
                'customer_invoice_id'       => $invoice->id,
                'partner_id'                => $invoice->partner_id,
                'factoring_date'            => $data['factoring_date'],
                'invoice_amount'            => $invoiceAmount,
                'invoice_due_date'          => $invoiceDueDate,
                'factoring_percentage'      => $data['factoring_percentage'],
                'factoring_amount'          => $amounts['factoring_amount'],
                'contract_interest_rate'    => $agreement->discount_rate,
                'diff_in_days'              => $amounts['diff_in_days'],
                'factoring_interest_amount' => $interestAmount,
                'other_charges'             => $otherCharges,
                'received_amount'           => $receivedAmount,
                'bank_account_id'           => $bankAccount->id,
                'status'                    => FactoringTransaction::STATUS_PENDING,
                'notes'                     => $data['notes'] ?? null,
                'created_by'                => $actor->id,
            ]);

            $feesAccount = ChartOfAccount::forCompany($companyId)->postable()
                ->where('code', self::FACTORING_FEES_ACCOUNT_CODE)->firstOrFail();

            $lines = [
                ['chart_of_account_id' => $bankAccount->coa_account_id, 'debit' => $receivedAmount, 'credit' => '0.00', 'description' => "Factoring disbursement — invoice {$invoice->invoice_code}"],
            ];

            // Two separate lines under the same 557 account (confirmed
            // with Mahmoud) — interest and other charges kept visible
            // on their own rows in the GL for a clearer audit trail,
            // even though both post to the same Factoring Fees &
            // Discount account per COA doc rule 15.
            if (bccomp($interestAmount, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $feesAccount->id, 'debit' => $interestAmount, 'credit' => '0.00', 'description' => "Factoring interest — invoice {$invoice->invoice_code}"];
            }
            if (bccomp($otherCharges, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $feesAccount->id, 'debit' => $otherCharges, 'credit' => '0.00', 'description' => "Factoring other charges — invoice {$invoice->invoice_code}"];
            }

            $lines[] = ['chart_of_account_id' => $agreement->coa_account_id, 'debit' => '0.00', 'credit' => $amounts['factoring_amount'], 'description' => "Factoring Liabilities — With Recourse — invoice {$invoice->invoice_code}"];

            $journalEntry = $this->journalEntryService->post(
                companyId: $companyId,
                entryDate: $data['factoring_date'],
                lines: $lines,
                sourceType: 'factoring_transaction',
                sourceId: $transaction->id,
                narration: "Factoring With Recourse — invoice {$invoice->invoice_code}",
                actor: $actor,
            );

            $transaction->update(['journal_entry_id' => $journalEntry->id]);

            AuditLog::record($actor, 'factoring_txn.created', $transaction, null, $transaction->fresh()->toArray());

            return $transaction->fresh(['factoringAgreement', 'customerInvoice', 'partner', 'bankAccount', 'journalEntry']);
        });
    }

    /**
     * Direct Factoring — Without Recourse. Risk fully transfers, so
     * unlike createWithRecourse() this clears the customer's AR
     * immediately instead of booking a liability — COA doc Section
     * 8.1. Confirmed posting rule (partial-percentage extension over
     * the doc's 100%-factoring example, same shape as the With
     * Recourse extension):
     *   Dr  Bank Account (its own coa_account_id)   — Received Amount
     *   Dr  557 Factoring Fees & Discount            — Interest (own line)
     *   Dr  557 Factoring Fees & Discount            — Other Charges (own line)
     *   Dr  140001 Due From Factoring Companies      — Invoice Amount − Factoring Amount, if any
     *   Cr  Partner's AR account                     — Invoice Amount (full, gross)
     * The invoice is tagged paid immediately (amount_paid/
     * amount_outstanding updated the same way CustomerPaymentService
     * does it) — it stops showing up as open everywhere else in the
     * system from this point on.
     */
    public function createWithoutRecourse(int $companyId, array $data, User $actor): FactoringTransaction
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $agreement = FactoringAgreement::where('company_id', $companyId)
                ->where('agreement_type', 'direct')
                ->where('recourse_type', 'without_recourse')
                ->findOrFail($data['factoring_agreement_id']);

            $invoice = CustomerInvoice::where('company_id', $companyId)
                ->open()
                ->findOrFail($data['customer_invoice_id']);

            if ($agreement->eligible_partner_id && $agreement->eligible_partner_id != $invoice->partner_id) {
                throw new \DomainException('This agreement is restricted to a specific customer — the chosen invoice belongs to someone else.');
            }

            $partner = Partner::where('company_id', $companyId)->findOrFail($invoice->partner_id);
            $arAccount = $this->resolveArAccountFor($partner, $companyId, (int) $invoice->currency_id);

            $bankAccount = BankAccount::where('company_id', $companyId)->findOrFail($data['bank_account_id']);

            $invoiceDueDate = $this->resolveInvoiceDueDate($invoice);
            $invoiceAmount  = (string) $invoice->invoice_value_functional;

            $amounts = FactoringTransaction::calculateAmounts(
                $invoiceAmount,
                FactoringTransaction::num($data['factoring_percentage']),
                (string) $agreement->discount_rate,
                FactoringTransaction::num($data['other_charges'] ?? 0),
                $data['factoring_date'],
                $invoiceDueDate->format('Y-m-d')
            );

            $interestAmount = FactoringTransaction::resolveInterest($amounts['factoring_interest_amount'], $data['factoring_interest_amount'] ?? null);
            $otherCharges   = FactoringTransaction::bcRound(FactoringTransaction::num($data['other_charges'] ?? 0));
            $receivedAmount = bcsub(bcsub($amounts['factoring_amount'], $interestAmount, 2), $otherCharges, 2);
            $dueFromAmount  = bcsub($invoiceAmount, $amounts['factoring_amount'], 2);

            $transaction = FactoringTransaction::create([
                'company_id'                    => $companyId,
                'factoring_agreement_id'        => $agreement->id,
                'customer_invoice_id'           => $invoice->id,
                'partner_id'                    => $invoice->partner_id,
                'factoring_date'                => $data['factoring_date'],
                'invoice_amount'                => $invoiceAmount,
                'invoice_due_date'               => $invoiceDueDate,
                'factoring_percentage'           => $data['factoring_percentage'],
                'factoring_amount'               => $amounts['factoring_amount'],
                'contract_interest_rate'         => $agreement->discount_rate,
                'diff_in_days'                    => $amounts['diff_in_days'],
                'factoring_interest_amount'      => $interestAmount,
                'other_charges'                   => $otherCharges,
                'received_amount'                => $receivedAmount,
                'bank_account_id'                => $bankAccount->id,
                'status'                          => FactoringTransaction::STATUS_PENDING, // pending = the "Due From" difference is still to be collected
                'collection_difference_amount'   => $dueFromAmount, // known deterministically at creation, unlike With Recourse
                'notes'                           => $data['notes'] ?? null,
                'created_by'                      => $actor->id,
            ]);

            $feesAccount = ChartOfAccount::forCompany($companyId)->postable()
                ->where('code', self::FACTORING_FEES_ACCOUNT_CODE)->firstOrFail();

            $lines = [
                ['chart_of_account_id' => $bankAccount->coa_account_id, 'debit' => $receivedAmount, 'credit' => '0.00', 'description' => "Factoring disbursement — invoice {$invoice->invoice_code}"],
            ];

            if (bccomp($interestAmount, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $feesAccount->id, 'debit' => $interestAmount, 'credit' => '0.00', 'description' => "Factoring interest — invoice {$invoice->invoice_code}"];
            }
            if (bccomp($otherCharges, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $feesAccount->id, 'debit' => $otherCharges, 'credit' => '0.00', 'description' => "Factoring other charges — invoice {$invoice->invoice_code}"];
            }
            if (bccomp($dueFromAmount, '0', 2) > 0) {
                $dueFromAccount = ChartOfAccount::forCompany($companyId)->postable()
                    ->where('code', self::DUE_FROM_FACTORING_ACCOUNT_CODE)->firstOrFail();
                $lines[] = ['chart_of_account_id' => $dueFromAccount->id, 'debit' => $dueFromAmount, 'credit' => '0.00', 'description' => "Due from factoring company — invoice {$invoice->invoice_code}"];
            }

            $lines[] = ['chart_of_account_id' => $arAccount->id, 'debit' => '0.00', 'credit' => $invoiceAmount, 'description' => "Invoice sold via factoring (without recourse) — invoice {$invoice->invoice_code}"];

            $journalEntry = $this->journalEntryService->post(
                companyId: $companyId,
                entryDate: $data['factoring_date'],
                lines: $lines,
                sourceType: 'factoring_transaction',
                sourceId: $transaction->id,
                narration: "Factoring Without Recourse — invoice {$invoice->invoice_code}",
                actor: $actor,
            );

            $transaction->update(['journal_entry_id' => $journalEntry->id]);

            // Tag the invoice paid — risk has fully transferred, so it
            // stops being "open" from here on, same fields
            // CustomerPaymentService updates for a normal payment.
            $invoice->update([
                'amount_paid'        => bcadd((string) $invoice->amount_paid, (string) $invoiceAmount, 2),
                'amount_outstanding' => bcsub((string) $invoice->amount_outstanding, (string) $invoiceAmount, 2),
            ]);

            AuditLog::record($actor, 'factoring_txn.created', $transaction, null, $transaction->fresh()->toArray());

            return $transaction->fresh(['factoringAgreement', 'customerInvoice', 'partner', 'bankAccount', 'journalEntry']);
        });
    }

    // ── Set to Draft / Delete ────────────────────────────────────
    // Same pattern as CustomerPaymentService::setToDraft() and
    // LgIssuanceService::setToDraft() — reverse everything the
    // original disbursement posted, put the transaction into a
    // harmless empty-shell state, then allow deleting that shell
    // outright. Only ever operates on a Pending transaction — a
    // Collected or Rejected one must be undone via its own action
    // first (revertCollected()/revertRejected()), never bypassed by
    // reopening the disbursement underneath it.

    /**
     * Reverses the original disbursement journal entry and, for
     * Without Recourse, un-tags the invoice as paid (reverses the
     * amount_paid/amount_outstanding change made at creation).
     * Blocked if the transaction isn't Pending, or its accounting
     * period is closed.
     */
    public function setToDraft(int $companyId, FactoringTransaction $transaction, User $actor): FactoringTransaction
    {
        if (! $transaction->isPending()) {
            throw new \DomainException('Only a pending factoring transaction can be set back to draft — Collected/Rejected transactions must be undone via their own action first.');
        }
        if ($this->fiscalYearService->isPeriodClosed($companyId, (string) $transaction->factoring_date)) {
            throw new \DomainException('This transaction\'s accounting period is closed — it cannot be reopened.');
        }

        return DB::transaction(function () use ($transaction, $actor) {
            $before = $transaction->toArray();

            if ($transaction->journalEntry && $transaction->journalEntry->status === \App\Models\JournalEntry::STATUS_POSTED) {
                $this->journalEntryService->reverse($transaction->journalEntry, $actor, 'Reopened to draft — factoring transaction #'.$transaction->id);
            }

            if ($transaction->factoringAgreement->recourse_type === 'without_recourse') {
                $invoice = $transaction->customerInvoice()->firstOrFail();
                $invoice->update([
                    'amount_paid'        => bcsub((string) $invoice->amount_paid, (string) $transaction->invoice_amount, 2),
                    'amount_outstanding' => bcadd((string) $invoice->amount_outstanding, (string) $transaction->invoice_amount, 2),
                ]);
            }

            $transaction->update([
                'status'             => FactoringTransaction::STATUS_DRAFT,
                'journal_entry_id'   => null,
            ]);

            AuditLog::record($actor, 'factoring_txn.set_to_draft', $transaction, $before, $transaction->fresh()->toArray());

            return $transaction->fresh(['factoringAgreement', 'customerInvoice', 'partner', 'bankAccount']);
        });
    }

    /**
     * Deletes a factoring transaction outright — the ONLY way this is
     * allowed is on a draft (never Pending/Collected/Rejected), and
     * the only way one becomes a draft is via setToDraft(), which has
     * already fully reversed everything real it did. By this point
     * it's a harmless empty shell with zero financial residue — same
     * philosophy as LgIssuanceService::delete().
     */
    public function delete(FactoringTransaction $transaction, User $actor): void
    {
        if (! $transaction->isDraft()) {
            throw new \DomainException('Only a draft factoring transaction can be deleted — use "Set to Draft" first.');
        }

        AuditLog::record($actor, 'factoring_txn.deleted', $transaction, $transaction->toArray(), null);

        $transaction->delete();
    }

    // ── Collect ──────────────────────────────────────────────────

    /**
     * With Recourse: customer paid as normal — the invoice is now
     * considered settled through the factoring arrangement.
     *   Dr  224001 Factoring Liabilities   — Factoring Amount (clears it)
     *   Dr  Bank Account (difference)      — Invoice Amount − Factoring Amount, if any
     *   Cr  Partner's AR account           — Invoice Amount (invoice fully cleared)
     *
     * Without Recourse: AR was already cleared at disbursement — this
     * just records the factoring company remitting the retained
     * percentage. Nothing to do with whether the customer ever paid.
     *   Dr  Bank Account (difference)          — Invoice Amount − Factoring Amount
     *   Cr  140001 Due From Factoring Companies — same amount
     * If the difference is 0 (100% was factored), there's nothing to
     * post — this just marks the transaction Collected for the record.
     *
     * Either way, the "difference" bank account defaults to the SAME
     * account used for the original disbursement — Mahmoud's
     * confirmed design — unless $data['bank_account_id'] overrides it.
     */
    public function markCollected(int $companyId, FactoringTransaction $transaction, array $data, User $actor): FactoringTransaction
    {
        return DB::transaction(function () use ($companyId, $transaction, $data, $actor) {
            if (! $transaction->isPending()) {
                throw new \DomainException('Only a pending factoring transaction can be marked Collected.');
            }

            $invoice   = $transaction->customerInvoice()->firstOrFail();
            $agreement = $transaction->factoringAgreement()->firstOrFail();

            $bankAccountId = $data['bank_account_id'] ?? $transaction->bank_account_id; // defaults to disbursement account
            $bankAccount   = BankAccount::where('company_id', $companyId)->findOrFail($bankAccountId);

            $differenceAmount = bcsub((string) $transaction->invoice_amount, (string) $transaction->factoring_amount, 2);
            $journalEntry     = null;

            if ($agreement->recourse_type === 'with_recourse') {
                $partner = $transaction->partner()->firstOrFail();
                $arAccount = $this->resolveArAccountFor($partner, $companyId, (int) $invoice->currency_id);

                $lines = [
                    ['chart_of_account_id' => $agreement->coa_account_id, 'debit' => (string) $transaction->factoring_amount, 'credit' => '0.00', 'description' => "Factoring collected — invoice {$invoice->invoice_code}"],
                ];
                if (bccomp($differenceAmount, '0', 2) > 0) {
                    $lines[] = ['chart_of_account_id' => $bankAccount->coa_account_id, 'debit' => (string) $differenceAmount, 'credit' => '0.00', 'description' => "Factoring collection difference — invoice {$invoice->invoice_code}"];
                }
                $lines[] = ['chart_of_account_id' => $arAccount->id, 'debit' => '0.00', 'credit' => (string) $transaction->invoice_amount, 'description' => "Invoice cleared via factoring — invoice {$invoice->invoice_code}"];

                $journalEntry = $this->journalEntryService->post(
                    companyId: $companyId, entryDate: $data['collection_date'], lines: $lines,
                    sourceType: 'factoring_collection', sourceId: $transaction->id,
                    narration: "Factoring collection — invoice {$invoice->invoice_code}", actor: $actor,
                );

                // RPT-001 fix (8 Sep 2026, confirmed with Mahmoud) — the
                // journal entry above already credits the customer's AR
                // control account, treating the invoice as paid. This
                // step makes the invoice's own subledger agree with
                // that: Collect means the customer genuinely paid (via
                // the factor), so the invoice closes now — same moment
                // createWithoutRecourse() already closes an invoice for
                // the without-recourse case, and the mirror image of
                // markRejected(), which correctly leaves AR untouched
                // because in that case the customer did NOT pay.
                $invoice->update([
                    'amount_paid'        => bcadd((string) $invoice->amount_paid, (string) $transaction->invoice_amount, 2),
                    'amount_outstanding' => bcsub((string) $invoice->amount_outstanding, (string) $transaction->invoice_amount, 2),
                ]);
            } elseif (bccomp($differenceAmount, '0', 2) > 0) { // without_recourse — only post if there's actually a difference to release
                $dueFromAccount = ChartOfAccount::forCompany($companyId)->postable()
                    ->where('code', self::DUE_FROM_FACTORING_ACCOUNT_CODE)->firstOrFail();

                $lines = [
                    ['chart_of_account_id' => $bankAccount->coa_account_id, 'debit' => (string) $differenceAmount, 'credit' => '0.00', 'description' => "Factoring company settlement — invoice {$invoice->invoice_code}"],
                    ['chart_of_account_id' => $dueFromAccount->id, 'debit' => '0.00', 'credit' => (string) $differenceAmount, 'description' => "Due from factoring company cleared — invoice {$invoice->invoice_code}"],
                ];

                $journalEntry = $this->journalEntryService->post(
                    companyId: $companyId, entryDate: $data['collection_date'], lines: $lines,
                    sourceType: 'factoring_collection', sourceId: $transaction->id,
                    narration: "Factoring company settlement — invoice {$invoice->invoice_code}", actor: $actor,
                );
            }

            $before = $transaction->toArray();

            $transaction->update([
                'status'                       => FactoringTransaction::STATUS_COLLECTED,
                'collection_date'              => $data['collection_date'],
                'collection_difference_amount' => $differenceAmount,
                'collection_bank_account_id'   => $bankAccount->id,
                'collection_journal_entry_id'  => $journalEntry?->id,
            ]);

            AuditLog::record($actor, 'factoring_txn.collected', $transaction, $before, $transaction->fresh()->toArray());

            return $transaction->fresh(['factoringAgreement', 'customerInvoice', 'partner', 'bankAccount', 'collectionBankAccount', 'journalEntry', 'collectionJournalEntry']);
        });
    }

    /** Undo a Collect — reverses the collection journal entry and returns the transaction to Pending. */
    public function revertCollected(int $companyId, FactoringTransaction $transaction, User $actor): FactoringTransaction
    {
        return DB::transaction(function () use ($transaction, $actor) {
            if (! $transaction->isCollected()) {
                throw new \DomainException('Only a Collected factoring transaction can have its collection reverted.');
            }

            $before = $transaction->toArray();

            if ($transaction->collectionJournalEntry) {
                $this->journalEntryService->reverse($transaction->collectionJournalEntry, $actor, 'Reversal of factoring collection');
            }

            // RPT-001 fix (8 Sep 2026) — undoes the invoice closure
            // markCollected() now performs for with_recourse. Guarded
            // the same way setToDraft() already guards its own
            // without_recourse invoice reversal — only the recourse
            // type that actually touched the invoice needs it undone.
            if ($transaction->factoringAgreement->recourse_type === 'with_recourse') {
                $invoice = $transaction->customerInvoice()->firstOrFail();
                $invoice->update([
                    'amount_paid'        => bcsub((string) $invoice->amount_paid, (string) $transaction->invoice_amount, 2),
                    'amount_outstanding' => bcadd((string) $invoice->amount_outstanding, (string) $transaction->invoice_amount, 2),
                ]);
            }

            $transaction->update([
                'status'                       => FactoringTransaction::STATUS_PENDING,
                'collection_date'              => null,
                'collection_difference_amount' => null,
                'collection_bank_account_id'   => null,
                'collection_journal_entry_id'  => null,
            ]);

            AuditLog::record($actor, 'factoring_txn.collect_undo', $transaction, $before, $transaction->fresh()->toArray());

            return $transaction->fresh(['factoringAgreement', 'customerInvoice', 'partner', 'bankAccount', 'journalEntry']);
        });
    }

    // ── Reject ───────────────────────────────────────────────────

    /**
     * Customer defaulted — "with recourse" means the company must
     * repay the factoring company. Confirmed posting rule:
     *   Dr  224001 Factoring Liabilities   — Factoring Amount (clears it)
     *   Dr  557 Factoring Fees & Discount  — Uncollected Invoice Charges, if any
     *   Cr  Bank Account (repayment)       — Factoring Amount + Uncollected Charges
     * AR is left untouched — it stays outstanding, the company must
     * now pursue collection directly.
     *
     * The repayment bank account defaults to the SAME account used
     * for the original disbursement — same confirmed design as
     * Collect — unless $data['bank_account_id'] overrides it.
     */
    public function markRejected(int $companyId, FactoringTransaction $transaction, array $data, User $actor): FactoringTransaction
    {
        return DB::transaction(function () use ($companyId, $transaction, $data, $actor) {
            if (! $transaction->isPending()) {
                throw new \DomainException('Only a pending factoring transaction can be marked Rejected.');
            }

            if ($transaction->factoringAgreement->recourse_type === 'without_recourse') {
                throw new \DomainException('Without Recourse factoring has no Reject action — risk has already transferred to the factoring company, so a customer default doesn\'t create any repayment obligation.');
            }

            $invoice = $transaction->customerInvoice()->firstOrFail();

            $bankAccountId = $data['bank_account_id'] ?? $transaction->bank_account_id; // defaults to disbursement account
            $bankAccount   = BankAccount::where('company_id', $companyId)->findOrFail($bankAccountId);

            $uncollectedCharges = FactoringTransaction::bcRound(FactoringTransaction::num($data['uncollected_invoice_charges'] ?? 0));
            $repaymentTotal     = bcadd((string) $transaction->factoring_amount, $uncollectedCharges, 2);

            $lines = [
                ['chart_of_account_id' => $transaction->factoringAgreement->coa_account_id, 'debit' => (string) $transaction->factoring_amount, 'credit' => '0.00', 'description' => "Factoring rejected — invoice {$invoice->invoice_code}"],
            ];
            if (bccomp($uncollectedCharges, '0', 2) > 0) {
                $feesAccount = ChartOfAccount::forCompany($companyId)->postable()
                    ->where('code', self::FACTORING_FEES_ACCOUNT_CODE)->firstOrFail();
                $lines[] = ['chart_of_account_id' => $feesAccount->id, 'debit' => (string) $uncollectedCharges, 'credit' => '0.00', 'description' => "Uncollected invoice charges — invoice {$invoice->invoice_code}"];
            }
            $lines[] = ['chart_of_account_id' => $bankAccount->coa_account_id, 'debit' => '0.00', 'credit' => (string) $repaymentTotal, 'description' => "Repayment to factoring company — invoice {$invoice->invoice_code}"];

            $journalEntry = $this->journalEntryService->post(
                companyId: $companyId,
                entryDate: $data['rejection_date'],
                lines: $lines,
                sourceType: 'factoring_rejection',
                sourceId: $transaction->id,
                narration: "Factoring rejection — invoice {$invoice->invoice_code}",
                actor: $actor,
            );

            $before = $transaction->toArray();

            $transaction->update([
                'status'                      => FactoringTransaction::STATUS_REJECTED,
                'rejection_date'              => $data['rejection_date'],
                'uncollected_invoice_charges' => $uncollectedCharges,
                'rejection_bank_account_id'   => $bankAccount->id,
                'rejection_journal_entry_id'  => $journalEntry->id,
            ]);

            AuditLog::record($actor, 'factoring_txn.rejected', $transaction, $before, $transaction->fresh()->toArray());

            return $transaction->fresh(['factoringAgreement', 'customerInvoice', 'partner', 'bankAccount', 'rejectionBankAccount', 'journalEntry', 'rejectionJournalEntry']);
        });
    }

    /** Undo a Reject — reverses the rejection journal entry and returns the transaction to Pending. */
    public function revertRejected(int $companyId, FactoringTransaction $transaction, User $actor): FactoringTransaction
    {
        return DB::transaction(function () use ($transaction, $actor) {
            if (! $transaction->isRejected()) {
                throw new \DomainException('Only a Rejected factoring transaction can have its rejection reverted.');
            }

            $before = $transaction->toArray();

            if ($transaction->rejectionJournalEntry) {
                $this->journalEntryService->reverse($transaction->rejectionJournalEntry, $actor, 'Reversal of factoring rejection');
            }

            $transaction->update([
                'status'                      => FactoringTransaction::STATUS_PENDING,
                'rejection_date'              => null,
                'uncollected_invoice_charges' => null,
                'rejection_bank_account_id'   => null,
                'rejection_journal_entry_id'  => null,
            ]);

            AuditLog::record($actor, 'factoring_txn.reject_undo', $transaction, $before, $transaction->fresh()->toArray());

            return $transaction->fresh(['factoringAgreement', 'customerInvoice', 'partner', 'bankAccount', 'journalEntry']);
        });
    }

    // ── List (one dedicated Index page per recourse type) ────────────
    // Confirmed with Mahmoud: With Recourse and Without Recourse are
    // different products with different lifecycles (Without Recourse
    // has no Reject) and must never share one list — a shared list
    // that showed both was confusing. $recourseType is always
    // required now, not optional.

    public function paginate(int $companyId, string $recourseType, array $filters = [])
    {
        return FactoringTransaction::where('company_id', $companyId)
            ->whereHas('factoringAgreement', fn ($q) => $q->where('recourse_type', $recourseType))
            ->with(['factoringAgreement:id,agreement_reference,recourse_type', 'customerInvoice:id,invoice_code', 'partner:id,name_en', 'bankAccount:id,account_name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('factoring_date')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * Vero CFO doesn't store a due_date column on customer_invoices —
     * only a payment_term_id (installments can split across several
     * due dates). For factoring purposes we need ONE due date, so
     * this resolves it as invoice_date + the LATEST installment's
     * due_days (the date the invoice is fully expected to be settled
     * by). Falls back to the invoice date itself (0 days) if there's
     * no payment term at all.
     */
    private function resolveInvoiceDueDate(CustomerInvoice $invoice): Carbon
    {
        $maxDueDays = 0;

        if ($invoice->payment_term_id) {
            $maxDueDays = (int) ($invoice->paymentTerm?->lines()
                ->where('reference_point', 'invoice_date')
                ->max('due_days') ?? 0);
        }

        return $invoice->invoice_date->copy()->addDays($maxDueDays);
    }

    /**
     * TRS-005 fix (8 Sep 2026, confirmed with Mahmoud) — a customer's
     * ar_account_id is an optional PER-CUSTOMER override, almost never
     * set (there's no field for it anywhere in the Partner screen).
     * Every other path that clears a customer's AR — CustomerInvoiceService,
     * CustomerPaymentService — already ignores this column entirely and
     * always posts to the company's one standard AR account. Factoring
     * used to be the only place that treated an empty override as a
     * hard block instead of falling back like everything else. This is
     * the exact same resolution CustomerInvoiceService::resolveArAccount()
     * uses, kept here rather than shared, since the two services don't
     * otherwise depend on each other.
     */
    private function resolveArAccountFor(Partner $partner, int $companyId, int $currencyId): ChartOfAccount
    {
        if ($partner->ar_account_id) {
            return ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)->findOrFail($partner->ar_account_id);
        }

        $functionalCurrencyId = CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');

        $code = ($functionalCurrencyId && (int) $currencyId === (int) $functionalCurrencyId) ? '133001' : '133002';

        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Accounts Receivable account ({$code}) is not seeded for this company — cannot post.");
    }
}
