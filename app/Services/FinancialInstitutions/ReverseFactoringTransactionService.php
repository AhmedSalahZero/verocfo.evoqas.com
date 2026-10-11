<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  ReverseFactoringTransactionService
//
//  Reverse Factoring — a Supplier Bill paid early by a factoring
//  company on the company's behalf (COA doc Section 8.3). AP-side
//  sibling of FactoringTransactionService — see
//  ReverseFactoringTransaction's own header for the full posting
//  rule and CashVero-independent design (this one has no CashVero
//  precedent; built directly from Mahmoud's confirmed numbers).
//
//  Two settlement paths, chosen at creation time:
//    'due_date'  — liability stays open, settled later via settle()
//                  on (or after) reverse_due_date:
//                    Dr 224002 Reverse Factoring Liabilities / Cr Bank
//    'cheques'   — one or more payable cheques issued immediately,
//                  settling the liability right away:
//                    Dr 224002 Reverse Factoring Liabilities /
//                    Cr 222001 Notes Payable — Suppliers (total)
//                  Each cheque becomes its own ChequePayable row,
//                  eventually marked paid through the EXISTING
//                  Cheques Payable screen (ChequePayableService) —
//                  no parallel cheque lifecycle built here.
//
//  reverse_due_date defaults to the Supplier Bill's own bill_date +
//  the agreement's extended_payment_days ("Reverse Due Days"), but
//  is always taken from the request — Mahmoud's explicit ask that the
//  user can override it per transaction.
//
//  @layer    Service
//  @depends  FactoringAgreement, ReverseFactoringTransaction,
//            FactoringCompany, SupplierBill, BankAccount, ChartOfAccount,
//            Partner, ChequePayable, JournalEntryService, FiscalYearService,
//            AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\ChequePayable;
use App\Models\CompanyActiveCurrency;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\FactoringAgreement;
use App\Models\FinancialInstitutions\FactoringCompany;
use App\Models\FinancialInstitutions\FactoringTransaction;
use App\Models\FinancialInstitutions\ReverseFactoringTransaction;
use App\Models\Partner;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\FiscalYearService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReverseFactoringTransactionService
{
    private const FACTORING_FEES_ACCOUNT_CODE = '557'; // Factoring Fees & Discount — same account as the AR side
    private const NOTES_PAYABLE_ACCOUNT_CODE  = '222001'; // Notes Payable — Suppliers — same account Supplier Payment uses for its own cheques

    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
    ) {}

    // ── Create-form data ─────────────────────────────────────────

    public function factoringCompaniesForCompany(int $companyId)
    {
        return FactoringCompany::where('company_id', $companyId)->active()->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar']);
    }

    public function eligibleAgreements(int $companyId, int $factoringCompanyId)
    {
        return FactoringAgreement::where('company_id', $companyId)
            ->where('factoring_company_id', $factoringCompanyId)
            ->where('agreement_type', 'reverse')
            ->active()
            ->get(['id', 'agreement_reference', 'discount_rate', 'eligible_partner_id', 'coa_account_id', 'extended_payment_days']);
    }

    /** Locked to the agreement's own eligible_partner_id if set, otherwise every active supplier-type partner. */
    public function eligibleSuppliers(int $companyId, FactoringAgreement $agreement)
    {
        if ($agreement->eligible_partner_id) {
            return Partner::where('company_id', $companyId)
                ->where('id', $agreement->eligible_partner_id)
                ->get(['id', 'name_en', 'ap_account_id']);
        }

        return Partner::where('company_id', $companyId)->active()->ofType('supplier')
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'ap_account_id']);
    }

    /**
     * Open bills for the chosen supplier, fully unpaid, not already
     * tied to a pending/draft reverse factoring transaction under any
     * agreement.
     */
    public function eligibleBills(int $companyId, int $partnerId)
    {
        $blockedBillIds = ReverseFactoringTransaction::where('company_id', $companyId)
            ->whereIn('status', [ReverseFactoringTransaction::STATUS_PENDING, ReverseFactoringTransaction::STATUS_DRAFT])
            ->pluck('supplier_bill_id');

        return SupplierBill::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->open()
            ->whereColumn('amount_outstanding', 'bill_value_functional')
            ->whereNotIn('id', $blockedBillIds)
            ->orderBy('bill_date')
            ->get(['id', 'bill_code', 'bill_date', 'bill_value_functional']);
    }

    public function activeBankAccounts(int $companyId)
    {
        return BankAccount::where('company_id', $companyId)->active()
            ->with('bank:id,name_en')
            ->orderBy('account_name')
            ->get(['id', 'bank_id', 'account_name', 'currency_id']);
    }

    /** Live preview for the form. Suggested reverse_due_date is bill_date + the agreement's extended_payment_days — the user can override it either way, before or after seeing this. */
    public function calculate(int $companyId, int $supplierBillId, int $factoringAgreementId, string $percentage, string $charges, string $reverseFactoringDate, ?string $reverseDueDate = null): array
    {
        $bill      = SupplierBill::where('company_id', $companyId)->findOrFail($supplierBillId);
        $agreement = FactoringAgreement::where('company_id', $companyId)->findOrFail($factoringAgreementId);

        $dueDate = $reverseDueDate
            ? Carbon::parse($reverseDueDate)
            : $bill->bill_date->copy()->addDays((int) ($agreement->extended_payment_days ?? 0));

        $amounts = ReverseFactoringTransaction::calculateAmounts(
            (string) $bill->bill_value_functional,
            FactoringTransaction::num($percentage),
            (string) $agreement->discount_rate,
            FactoringTransaction::num($charges),
            $reverseFactoringDate,
            $dueDate->format('Y-m-d')
        );

        // H-20: the company repays advance + interest + charges.
        $totalRepayable = bcadd(bcadd($amounts['factoring_amount'], $amounts['factoring_interest_amount'], 2), FactoringTransaction::bcRound(FactoringTransaction::num($charges)), 2);

        // Display-only numbers for the form's JSON — never used for posting.
        return [
            'bill_amount'                => Money::round($bill->bill_value_functional), // Audit L5: exact strings
            'suggested_reverse_due_date' => $dueDate->format('Y-m-d'),
            'contract_interest_rate'     => Money::round($agreement->discount_rate, 6),
            'reverse_factoring_amount'          => Money::round($amounts['factoring_amount']),
            'diff_in_days'                       => $amounts['diff_in_days'],
            'reverse_factoring_interest_amount' => Money::round($amounts['factoring_interest_amount']),
            'ap_clear_amount'                   => Money::round($amounts['factoring_amount']),
            'total_repayable'                   => Money::round($totalRepayable),
        ];
    }

    // ── Create (disbursement) ────────────────────────────────────

    public function createReverseFactoring(int $companyId, array $data, User $actor): ReverseFactoringTransaction
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $agreement = FactoringAgreement::where('company_id', $companyId)
                ->where('agreement_type', 'reverse')
                ->findOrFail($data['factoring_agreement_id']);

            $bill = SupplierBill::where('company_id', $companyId)
                ->open()
                ->findOrFail($data['supplier_bill_id']);

            if ($agreement->eligible_partner_id && $agreement->eligible_partner_id != $bill->partner_id) {
                throw new \DomainException('This agreement is restricted to a specific supplier — the chosen bill belongs to someone else.');
            }

            $partner = Partner::where('company_id', $companyId)->findOrFail($bill->partner_id);
            $apAccount = $this->resolveApAccountFor($partner, $companyId, (int) $bill->currency_id);

            $sourceBankAccount = BankAccount::where('company_id', $companyId)->findOrFail($data['source_bank_account_id']);

            $billAmount = (string) $bill->bill_value_functional;
            $charges    = FactoringTransaction::bcRound(FactoringTransaction::num($data['reverse_factoring_charges'] ?? 0));

            // H-20 — every figure is calculated here with bcmath from the
            // agreement's own rate. A typed interest figure is only
            // accepted when it is within 1% of the calculated one.
            $amounts = ReverseFactoringTransaction::calculateAmounts(
                $billAmount,
                FactoringTransaction::num($data['reverse_factoring_percentage']),
                (string) $agreement->discount_rate,
                $charges,
                $data['reverse_factoring_date'],
                $data['reverse_due_date']
            );

            $interestAmount = FactoringTransaction::resolveInterest(
                $amounts['factoring_interest_amount'],
                $data['reverse_factoring_interest_amount'] ?? null
            );

            // The supplier is paid the whole advance by the factoring
            // company, so the supplier's AP is cleared by the whole advance.
            // Interest and charges are the company's own finance cost and
            // are added to what it owes the factoring company.
            $advance        = $amounts['factoring_amount'];
            $apClearAmount  = $advance;
            $totalRepayable = bcadd(bcadd($advance, $interestAmount, 2), $charges, 2);

            $settlementMethod = $data['settlement_method'];

            if ($settlementMethod === ReverseFactoringTransaction::SETTLEMENT_CHEQUES) {
                $chequesTotal = '0.00';
                foreach ($data['cheques'] as $c) {
                    $chequesTotal = bcadd($chequesTotal, FactoringTransaction::bcRound(FactoringTransaction::num($c['cheque_amount'])), 2);
                }
                if (bccomp($chequesTotal, $totalRepayable, 2) !== 0) {
                    throw new \DomainException("The cheques must add up to exactly the amount owed to the factoring company ({$totalRepayable} = advance + interest + charges) — they currently total {$chequesTotal}.");
                }
            }

            $transaction = ReverseFactoringTransaction::create([
                'company_id'                         => $companyId,
                'factoring_agreement_id'             => $agreement->id,
                'supplier_bill_id'                   => $bill->id,
                'partner_id'                          => $bill->partner_id,
                'reverse_factoring_date'              => $data['reverse_factoring_date'],
                'bill_amount'                         => $billAmount,
                'reverse_due_date'                    => $data['reverse_due_date'],
                'reverse_factoring_percentage'        => $data['reverse_factoring_percentage'],
                'reverse_factoring_amount'            => $advance,
                'contract_interest_rate'              => $agreement->discount_rate,
                'diff_in_days'                        => $amounts['diff_in_days'],
                'reverse_factoring_interest_amount'   => $interestAmount,
                'reverse_factoring_charges'           => $charges,
                'ap_clear_amount'                     => $apClearAmount,
                'settlement_method'                   => $settlementMethod,
                'source_bank_account_id'              => $sourceBankAccount->id,
                'status'                              => ReverseFactoringTransaction::STATUS_PENDING,
                'notes'                               => $data['notes'] ?? null,
                'created_by'                          => $actor->id,
            ]);

            // ── Disbursement JE — Dr AP (full advance) / Dr 557 (interest) / Dr 557 (charges) / Cr 224002 (advance + interest + charges) ──
            $feesAccount = ChartOfAccount::findByCode($companyId, self::FACTORING_FEES_ACCOUNT_CODE)
                ?? throw new \DomainException('Chart of Accounts is missing system account 557 (Factoring Fees & Discount).');

            $lines = [
                ['chart_of_account_id' => $apAccount->id, 'debit' => $apClearAmount, 'credit' => '0.00', 'description' => "Reverse factoring — bill {$bill->bill_code}"],
            ];
            if (bccomp($interestAmount, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $feesAccount->id, 'debit' => $interestAmount, 'credit' => '0.00', 'description' => "Reverse factoring interest — bill {$bill->bill_code}"];
            }
            if (bccomp($charges, '0', 2) > 0) {
                $lines[] = ['chart_of_account_id' => $feesAccount->id, 'debit' => $charges, 'credit' => '0.00', 'description' => "Reverse factoring other charges — bill {$bill->bill_code}"];
            }
            $lines[] = ['chart_of_account_id' => $agreement->coa_account_id, 'debit' => '0.00', 'credit' => $totalRepayable, 'description' => "Reverse Factoring Liabilities — bill {$bill->bill_code}"];

            $journalEntry = $this->journalEntryService->post(
                companyId: $companyId,
                entryDate: $data['reverse_factoring_date'],
                lines: $lines,
                sourceType: 'reverse_factoring_txn',
                sourceId: $transaction->id,
                narration: "Reverse Factoring — bill {$bill->bill_code}",
                actor: $actor,
            );

            $transaction->update(['journal_entry_id' => $journalEntry->id]);

            // The bill's own subledger is reduced by exactly the amount of
            // AP the journal entry cleared (the advance), so the ledger and
            // the bill can never disagree (H-20). At 100% advance this is the
            // whole bill; at a lower % the remainder stays open and is paid
            // through the normal Supplier Payment.
            $bill->update([
                'amount_paid'        => bcadd((string) $bill->amount_paid, $apClearAmount, 2),
                'amount_outstanding' => bcsub((string) $bill->amount_outstanding, $apClearAmount, 2),
            ]);

            // ── Cheque settlement path — settles the liability immediately ──
            if ($settlementMethod === ReverseFactoringTransaction::SETTLEMENT_CHEQUES) {
                $this->settleViaCheques($companyId, $transaction, $data['cheques'], $actor);
            }

            AuditLog::record($actor, 'rev_fact_txn.created', $transaction, null, $transaction->fresh()->toArray());

            return $transaction->fresh(['factoringAgreement', 'supplierBill', 'partner', 'sourceBankAccount', 'journalEntry', 'cheques']);
        });
    }

    /**
     * Cheque settlement — settles the liability right away:
     *   Dr 224002 Reverse Factoring Liabilities / Cr 222001 Notes
     *   Payable — Suppliers (total across all cheques)
     * then creates one ChequePayable row per cheque, each 'issued'
     * and ready to be marked paid later through the existing Cheques
     * Payable screen — same posting shape SupplierPaymentService uses
     * for its own single cheque, just summed across several here.
     */
    private function settleViaCheques(int $companyId, ReverseFactoringTransaction $transaction, array $cheques, User $actor): void
    {
        $notesPayableAccount = ChartOfAccount::findByCode($companyId, self::NOTES_PAYABLE_ACCOUNT_CODE)
            ?? throw new \DomainException('Chart of Accounts is missing system account 222001 (Notes Payable — Suppliers).');

        $total = $transaction->totalRepayable();

        $journalEntry = $this->journalEntryService->post(
            companyId: $companyId,
            entryDate: $transaction->reverse_factoring_date->format('Y-m-d'),
            lines: [
                ['chart_of_account_id' => $transaction->factoringAgreement->coa_account_id, 'debit' => $total, 'credit' => '0.00', 'description' => "Reverse factoring settled by cheques — bill {$transaction->supplierBill->bill_code}"],
                ['chart_of_account_id' => $notesPayableAccount->id, 'debit' => '0.00', 'credit' => $total, 'description' => "Cheques issued to factoring company — bill {$transaction->supplierBill->bill_code}"],
            ],
            sourceType: 'reverse_factoring_settle',
            sourceId: $transaction->id,
            narration: "Reverse factoring settled by cheques — bill {$transaction->supplierBill->bill_code}",
            actor: $actor,
        );

        foreach ($cheques as $cheque) {
            ChequePayable::create([
                'company_id'                        => $companyId,
                'reverse_factoring_transaction_id'  => $transaction->id,
                'factoring_company_id'              => $transaction->factoringAgreement->factoring_company_id,
                'partner_id'                         => null, // payee is the factoring company, not a Partner
                'cheque_amount'                      => $cheque['cheque_amount'],
                'cheque_number'                      => $cheque['cheque_number'],
                'due_date'                            => $cheque['due_date'],
                'status'                              => ChequePayable::STATUS_ISSUED,
                'created_by'                          => $actor->id,
            ]);
        }

        $transaction->update([
            'status'                       => ReverseFactoringTransaction::STATUS_SETTLED,
            'settlement_date'              => $transaction->reverse_factoring_date,
            'settlement_journal_entry_id'  => $journalEntry->id,
        ]);
    }

    // ── Settle (due-date path only) ──────────────────────────────

    /**
     * Dr 224002 Reverse Factoring Liabilities / Cr Bank — the
     * due-date settlement path (COA doc Section 8.3, row 2). Only
     * ever called on a 'due_date' transaction — a 'cheques' one is
     * already 'settled' the moment it's created.
     */
    public function settle(int $companyId, ReverseFactoringTransaction $transaction, array $data, User $actor): ReverseFactoringTransaction
    {
        return DB::transaction(function () use ($companyId, $transaction, $data, $actor) {
            if (! $transaction->isPending()) {
                throw new \DomainException('Only a pending reverse factoring transaction can be settled.');
            }
            if (! $transaction->isDueDateSettlement()) {
                throw new \DomainException('This transaction was already settled by cheques at disbursement.');
            }

            $bankAccountId = $data['bank_account_id'] ?? $transaction->source_bank_account_id;
            $bankAccount   = BankAccount::where('company_id', $companyId)->findOrFail($bankAccountId);

            $journalEntry = $this->journalEntryService->post(
                companyId: $companyId,
                entryDate: $data['settlement_date'],
                lines: [
                    ['chart_of_account_id' => $transaction->factoringAgreement->coa_account_id, 'debit' => $transaction->totalRepayable(), 'credit' => '0.00', 'description' => "Reverse factoring settled — bill {$transaction->supplierBill->bill_code}"],
                    ['chart_of_account_id' => $bankAccount->coa_account_id, 'debit' => '0.00', 'credit' => $transaction->totalRepayable(), 'description' => "Repayment to factoring company — bill {$transaction->supplierBill->bill_code}"],
                ],
                sourceType: 'reverse_factoring_settle',
                sourceId: $transaction->id,
                narration: "Reverse factoring settlement — bill {$transaction->supplierBill->bill_code}",
                actor: $actor,
            );

            $before = $transaction->toArray();

            $transaction->update([
                'status'                       => ReverseFactoringTransaction::STATUS_SETTLED,
                'settlement_date'              => $data['settlement_date'],
                'settlement_journal_entry_id'  => $journalEntry->id,
            ]);

            AuditLog::record($actor, 'rev_fact_txn.settled', $transaction, $before, $transaction->fresh()->toArray());

            return $transaction->fresh(['factoringAgreement', 'supplierBill', 'partner', 'sourceBankAccount', 'journalEntry', 'settlementJournalEntry']);
        });
    }

    /** Undo a Settle (due-date path) — reverses the settlement journal entry and returns the transaction to Pending. */
    public function revertSettle(int $companyId, ReverseFactoringTransaction $transaction, User $actor): ReverseFactoringTransaction
    {
        return DB::transaction(function () use ($transaction, $actor) {
            if (! $transaction->isSettled() || ! $transaction->isDueDateSettlement()) {
                throw new \DomainException('Only a due-date-settled transaction can have its settlement reverted — a cheque settlement is undone by resetting its individual cheques from the Cheques Payable screen instead.');
            }

            $before = $transaction->toArray();

            if ($transaction->settlementJournalEntry) {
                $this->journalEntryService->reverse($transaction->settlementJournalEntry, $actor, 'Reversal of reverse factoring settlement');
            }

            $transaction->update([
                'status'                       => ReverseFactoringTransaction::STATUS_PENDING,
                'settlement_date'              => null,
                'settlement_journal_entry_id'  => null,
            ]);

            AuditLog::record($actor, 'rev_fact_txn.settle_undo', $transaction, $before, $transaction->fresh()->toArray());

            return $transaction->fresh(['factoringAgreement', 'supplierBill', 'partner', 'sourceBankAccount', 'journalEntry']);
        });
    }

    // ── Set to Draft / Delete ────────────────────────────────────
    // Same pattern as FactoringTransactionService::setToDraft() —
    // reverses the disbursement, puts the transaction into a harmless
    // empty shell, then allows deleting that shell. Only ever works
    // from Pending (due-date path, unsettled) — a cheque-settled
    // transaction must have each cheque reset first, from the
    // Cheques Payable screen, same as revertSettle() explains.

    public function setToDraft(int $companyId, ReverseFactoringTransaction $transaction, User $actor): ReverseFactoringTransaction
    {
        if (! $transaction->isPending()) {
            throw new \DomainException('Only a pending reverse factoring transaction can be set back to draft.');
        }
        if ($this->fiscalYearService->isPeriodClosed($companyId, (string) $transaction->reverse_factoring_date)) {
            throw new \DomainException('This transaction\'s accounting period is closed — it cannot be reopened.');
        }

        return DB::transaction(function () use ($transaction, $actor) {
            $before = $transaction->toArray();

            if ($transaction->journalEntry && $transaction->journalEntry->status === \App\Models\JournalEntry::STATUS_POSTED) {
                $this->journalEntryService->reverse($transaction->journalEntry, $actor, 'Reopened to draft — reverse factoring transaction #'.$transaction->id);
            }

            // RPT-002 fix (8 Sep 2026) — undoes the bill closure
            // createReverseFactoring() now performs at disbursement,
            // same pattern as FactoringTransactionService::setToDraft()
            // already uses for its own without_recourse invoice reversal.
            $bill = $transaction->supplierBill()->firstOrFail();

            // New (H-20) transactions closed the bill by the advance; older ones
            // (ap_clear = advance − interest − charges) closed it by the full bill.
            $legacy  = bccomp(
                bcadd((string) $transaction->ap_clear_amount, bcadd((string) $transaction->reverse_factoring_interest_amount, (string) $transaction->reverse_factoring_charges, 2), 2),
                (string) $transaction->reverse_factoring_amount, 2
            ) === 0 && bccomp((string) $transaction->ap_clear_amount, (string) $transaction->reverse_factoring_amount, 2) !== 0;
            $restore = $legacy ? (string) $transaction->bill_amount : (string) $transaction->ap_clear_amount;

            $bill->update([
                'amount_paid'        => bcsub((string) $bill->amount_paid, $restore, 2),
                'amount_outstanding' => bcadd((string) $bill->amount_outstanding, $restore, 2),
            ]);

            $transaction->update([
                'status'             => ReverseFactoringTransaction::STATUS_DRAFT,
                'journal_entry_id'   => null,
            ]);

            AuditLog::record($actor, 'rev_fact_txn.set_to_draft', $transaction, $before, $transaction->fresh()->toArray());

            return $transaction->fresh(['factoringAgreement', 'supplierBill', 'partner', 'sourceBankAccount']);
        });
    }

    public function delete(ReverseFactoringTransaction $transaction, User $actor): void
    {
        if (! $transaction->isDraft()) {
            throw new \DomainException('Only a draft reverse factoring transaction can be deleted — use "Set to Draft" first.');
        }

        AuditLog::record($actor, 'rev_fact_txn.deleted', $transaction, $transaction->toArray(), null);

        $transaction->delete();
    }

    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = [])
    {
        return ReverseFactoringTransaction::where('company_id', $companyId)
            ->with(['factoringAgreement:id,agreement_reference', 'supplierBill:id,bill_code', 'partner:id,name_en', 'sourceBankAccount:id,account_name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('reverse_factoring_date')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * TRS-005 fix (8 Sep 2026, confirmed with Mahmoud) — mirror of
     * FactoringTransactionService::resolveArAccountFor() on the
     * payable side. A supplier's ap_account_id is an optional
     * PER-SUPPLIER override, almost never set (no field for it
     * anywhere in the Partner screen). Every other path that clears a
     * supplier's AP — SupplierBillService, SupplierPaymentService —
     * already ignores this column and always posts to the company's
     * one standard AP account. Reverse Factoring used to be the only
     * place that treated an empty override as a hard block.
     */
    private function resolveApAccountFor(Partner $partner, int $companyId, int $currencyId): ChartOfAccount
    {
        if ($partner->ap_account_id) {
            return ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)->findOrFail($partner->ap_account_id);
        }

        $functionalCurrencyId = CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');

        $code = ($functionalCurrencyId && (int) $currencyId === (int) $functionalCurrencyId) ? '221001' : '221002';

        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Accounts Payable account ({$code}) is not seeded for this company — cannot post.");
    }
}
