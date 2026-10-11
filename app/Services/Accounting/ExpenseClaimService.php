<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  ExpenseClaimService
//
//  The Expense Claim lifecycle, and the only place in this module
//  that reaches the general ledger.
//
//      draft ──submit──▶ submitted ──approve──▶ approved ──pay──▶ paid
//        │                   │                     │
//        │                   └───reject───▶ rejected
//        └──cancel──▶ cancelled          setToDraft ◀┘
//
//  ══ POSTING ══
//  Every journal entry here goes through JournalEntryService::post(),
//  which is the only class allowed to write to journal_entries. This
//  service assembles lines and hands them over; it does not compute
//  balances, resolve postability, or check periods itself. All of
//  that is inherited, deliberately.
//
//  On approve() — the claim becomes an obligation:
//      DR  each line's own expense account      that line's amount
//      CR  232 Other Creditors                  the claim total
//
//  On markPaid() — the money leaves:
//      DR  232 Other Creditors                  the claim total
//      CR  the bank account's COA account, or the chosen cash safe
//
//  Two entries because approval and payment are different events that
//  routinely fall in different months — the same reasoning that puts
//  GRNI (233) between a Goods Receipt Note and its Supplier Bill.
//
//  ══ THE ROUNDING RULE THAT KEEPS THE ENTRY BALANCED ══
//  The credit is the SUM OF THE PER-LINE functional amounts, never
//  the claim total converted once. Converting each line and summing
//  gives a different figure from converting the sum whenever the
//  exchange rate has more precision than the currency — and if the
//  debits are per-line while the credit is converted-once, the entry
//  does not balance and JournalEntryService rightly refuses it. So
//  the credit is derived from the same numbers as the debits. The
//  claim's stored amount_functional is set to that same sum for the
//  same reason.
//
//  ══ INHERITED DEFECTS, RECORDED NOT HIDDEN ══
//  setToDraft() reverses the accrual through
//  JournalEntryService::reverse(), and therefore inherits audit
//  findings ACC-002 (a reversed entry is dropped from every report
//  while its mirror is kept, so the reversal double-applies) and
//  ACC-003 (the reversal ignores period closure and is dated today).
//  Those are defects in shared accounting code that this work is not
//  authorised to change. Reopening an approved claim is affected by
//  them exactly as reopening a Goods Receipt Note is. Flagged here so
//  it is not discovered by surprise.
//
//  ══ NOT HANDLED, BY DESIGN ══
//  No VAT and no withholding tax — see migration 000281's header.
//  The tax treatment of an employee's own receipt is a real question
//  nothing in this repository answers, and guessing it would be
//  inventing a business rule.
//
//  AUDIT H3 FIX (Sept 2026): approve() refuses the claim's own creator
//  or submitter (maker-checker); approving needs the APPROVE right.
//
//  AUDIT M2 FIX (Sept 2026): paying from a cash safe now verifies the
//  account is an active, postable safe directly under 131 Cash —
//  see resolveCashSafe(). Any other GL account is refused.
//
//  @layer    Service
//  @depends  ExpenseClaim, ExpenseClaimLine, ChartOfAccount, Partner,
//            FinancialInstitutions\BankAccount, JournalEntryService,
//            User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimLine;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ExpenseClaimService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
    ) {
    }

    // ── Read ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return ExpenseClaim::with(['partner:id,name_en,name_ar', 'currency:id,code'])
            ->where('company_id', $companyId)
            ->when($filters['search'] ?? null, fn ($q, $s) => $q
                ->where(fn ($q) => $q
                    ->where('claim_code', 'like', "%{$s}%")
                    ->orWhere('title', 'like', "%{$s}%")
                    ->orWhereHas('partner', fn ($p) => $p->where('name_en', 'like', "%{$s}%"))
                )
            )
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['partner_id'] ?? null, fn ($q, $v) => $q->where('partner_id', $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->where('claim_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->where('claim_date', '<=', $v))
            ->orderByDesc('claim_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Create / update / delete — draft only ─────────────────────

    public function create(int $companyId, array $data, User $actor): ExpenseClaim
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $partner = $this->assertEmployee($companyId, (int) $data['partner_id']);

            $claim = ExpenseClaim::create([
                'company_id'    => $companyId,
                'claim_code'    => $this->generateClaimCode($companyId, $data['claim_date'] ?? null),
                'partner_id'    => $partner->id,
                'claim_date'    => $data['claim_date'],
                'title'         => $data['title'] ?? null,
                'description'   => $data['description'] ?? null,
                'currency_id'   => $data['currency_id'],
                'exchange_rate' => $data['exchange_rate'] ?? 1,
                'status'        => ExpenseClaim::STATUS_DRAFT,
                'created_by'    => $actor->id,
            ]);

            $this->syncLines($claim, $data['lines'] ?? []);
            $this->recalculateTotals($claim);

            AuditLog::record($actor, 'expense_claim.created', $claim, null, $claim->fresh('lines')->toArray());

            return $claim->fresh(['lines.expenseAccount', 'partner', 'currency']);
        });
    }

    public function update(ExpenseClaim $claim, array $data, User $actor): ExpenseClaim
    {
        $this->assertEditable($claim);

        return DB::transaction(function () use ($claim, $data, $actor) {
            $before = $claim->fresh('lines')->toArray();

            $partner = $this->assertEmployee($claim->company_id, (int) $data['partner_id']);

            $claim->update([
                'partner_id'    => $partner->id,
                'claim_date'    => $data['claim_date'],
                'title'         => $data['title'] ?? null,
                'description'   => $data['description'] ?? null,
                'currency_id'   => $data['currency_id'],
                'exchange_rate' => $data['exchange_rate'] ?? 1,
            ]);

            $this->syncLines($claim, $data['lines'] ?? []);
            $this->recalculateTotals($claim);

            AuditLog::record($actor, 'expense_claim.updated', $claim, $before, $claim->fresh('lines')->toArray());

            return $claim->fresh(['lines.expenseAccount', 'partner', 'currency']);
        });
    }

    public function delete(ExpenseClaim $claim, User $actor): void
    {
        $this->assertEditable($claim);

        AuditLog::record($actor, 'expense_claim.deleted', $claim, $claim->fresh('lines')->toArray(), null);

        $claim->delete();
    }

    // ── Transitions ───────────────────────────────────────────────

    public function submit(ExpenseClaim $claim, User $actor): ExpenseClaim
    {
        $this->assertStatus($claim, [ExpenseClaim::STATUS_DRAFT], 'submitted');

        if ($claim->lines()->count() === 0) {
            throw new \DomainException("Expense claim {$claim->claim_code} has no lines — add at least one receipt before submitting.");
        }

        if (bccomp((string) $claim->total_amount, '0', 2) <= 0) {
            throw new \DomainException("Expense claim {$claim->claim_code} totals zero — there is nothing to reimburse.");
        }

        return DB::transaction(function () use ($claim, $actor) {
            $before = $claim->toArray();

            $claim->update([
                'status'       => ExpenseClaim::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'submitted_by' => $actor->id,
            ]);

            AuditLog::record($actor, 'expense_claim.submitted', $claim, $before, $claim->fresh()->toArray());

            return $claim->fresh(['lines', 'partner']);
        });
    }

    /**
     * Approval is where the claim first reaches the ledger. Until now
     * it has been a request; from here it is money the company owes.
     */
    public function approve(ExpenseClaim $claim, User $actor, ?string $approvalDate = null): ExpenseClaim
    {
        $this->assertStatus($claim, [ExpenseClaim::STATUS_SUBMITTED], 'approved');

        // Audit H3 (Sept 2026) — maker-checker: the person who created
        // or submitted a claim can never approve it. (The platform Super
        // Admin is exempt: support / test operations only.)
        if (! $actor->isSuperAdmin()
            && in_array((int) $actor->id, array_filter([(int) $claim->created_by, (int) $claim->submitted_by]), true)) {
            throw new \DomainException('You created or submitted this claim — it must be approved by someone else.');
        }

        return DB::transaction(function () use ($claim, $actor, $approvalDate) {
            $before = $claim->fresh()->toArray();

            $claim->load('lines');

            if ($claim->lines->isEmpty()) {
                throw new \DomainException("Expense claim {$claim->claim_code} has no lines to post.");
            }

            $entryDate = $approvalDate ?? now()->toDateString();
            $liability = $this->resolveLiabilityAccount($claim->company_id);

            // Per-line debits, and a credit built from the SAME
            // per-line figures — see the class header on rounding.
            $lines       = [];
            $creditTotal = '0.00';

            foreach ($claim->lines as $line) {
                $this->assertAccountBelongsToCompany($claim->company_id, (int) $line->expense_account_id, 'expense');

                $functional = bcmul((string) $line->amount, (string) $claim->exchange_rate, 2);

                $lines[] = [
                    'chart_of_account_id' => $line->expense_account_id,
                    'cost_center_id'      => $line->cost_center_id,
                    'debit'               => $functional,
                    'credit'              => '0',
                    'description'         => $line->description,
                ];

                $creditTotal = bcadd($creditTotal, $functional, 2);
            }

            $lines[] = [
                'chart_of_account_id' => $liability->id,
                'debit'               => '0',
                'credit'              => $creditTotal,
                'description'         => 'Expense claim '.$claim->claim_code.' — payable to '.$claim->partner->name_en,
            ];

            $entry = $this->journalEntryService->post(
                companyId: $claim->company_id,
                entryDate: $entryDate,
                lines: $lines,
                sourceType: 'expense_claim',
                sourceId: $claim->id,
                narration: "Expense claim {$claim->claim_code} approved — {$claim->partner->name_en}",
                actor: $actor,
            );

            $claim->update([
                'status'            => ExpenseClaim::STATUS_APPROVED,
                'approved_at'       => now(),
                'approved_by'       => $actor->id,
                'journal_entry_id'  => $entry->id,
                'amount_functional' => $creditTotal,
            ]);

            AuditLog::record($actor, 'expense_claim.approved', $claim, $before, $claim->fresh()->toArray());

            return $claim->fresh(['lines.expenseAccount', 'partner', 'journalEntry.lines']);
        });
    }

    public function reject(ExpenseClaim $claim, User $actor, ?string $reason = null): ExpenseClaim
    {
        $this->assertStatus($claim, [ExpenseClaim::STATUS_SUBMITTED], 'rejected');

        return DB::transaction(function () use ($claim, $actor, $reason) {
            $before = $claim->toArray();

            $claim->update([
                'status'           => ExpenseClaim::STATUS_REJECTED,
                'rejected_at'      => now(),
                'rejected_by'      => $actor->id,
                'rejection_reason' => $reason,
            ]);

            AuditLog::record($actor, 'expense_claim.rejected', $claim, $before, $claim->fresh()->toArray());

            return $claim->fresh(['lines', 'partner']);
        });
    }

    /** Only ever available before anything has been posted. */
    public function cancel(ExpenseClaim $claim, User $actor): ExpenseClaim
    {
        $this->assertStatus($claim, [
            ExpenseClaim::STATUS_DRAFT,
            ExpenseClaim::STATUS_SUBMITTED,
        ], 'cancelled');

        return DB::transaction(function () use ($claim, $actor) {
            $before = $claim->toArray();

            $claim->update([
                'status'       => ExpenseClaim::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ]);

            AuditLog::record($actor, 'expense_claim.cancelled', $claim, $before, $claim->fresh()->toArray());

            return $claim->fresh(['lines', 'partner']);
        });
    }

    /**
     * Reopens an APPROVED claim, reversing its accrual. The same
     * reopen-to-draft shape Goods Receipt Note and Supplier Bill use.
     *
     * A PAID claim cannot be reopened here: the money has already left
     * the company, and unwinding that is a treasury correction rather
     * than an HR one.
     *
     * Inherits audit findings ACC-002 and ACC-003 through
     * JournalEntryService::reverse() — see the class header.
     */
    public function setToDraft(ExpenseClaim $claim, User $actor): ExpenseClaim
    {
        if ($claim->isPaid()) {
            throw new \DomainException(
                "Expense claim {$claim->claim_code} has already been paid and cannot be reopened. "
                .'Reverse the payment first.'
            );
        }

        $this->assertStatus($claim, [ExpenseClaim::STATUS_APPROVED], 'reopened');

        return DB::transaction(function () use ($claim, $actor) {
            $before = $claim->toArray();

            if ($claim->journal_entry_id) {
                $this->journalEntryService->reverse(
                    $claim->journalEntry,
                    $actor,
                    "Reopened to draft — expense claim {$claim->claim_code}"
                );
            }

            $claim->update([
                'status'           => ExpenseClaim::STATUS_DRAFT,
                'journal_entry_id' => null,
                'approved_at'      => null,
                'approved_by'      => null,
                'submitted_at'     => null,
                'submitted_by'     => null,
            ]);

            AuditLog::record($actor, 'expense_claim.reopened', $claim, $before, $claim->fresh()->toArray());

            return $claim->fresh(['lines', 'partner']);
        });
    }

    /**
     * The money leaves. Clears the liability raised at approval
     * against whichever bank account or cash safe actually paid it.
     */
    public function markPaid(ExpenseClaim $claim, array $data, User $actor): ExpenseClaim
    {
        $this->assertStatus($claim, [ExpenseClaim::STATUS_APPROVED], 'paid');

        return DB::transaction(function () use ($claim, $data, $actor) {
            $before = $claim->fresh()->toArray();

            $paymentDate = $data['payment_date'] ?? now()->toDateString();
            $sourceType  = $data['payment_source_type'];

            $sourceAccount = $this->resolvePaymentSourceAccount($claim->company_id, $sourceType, $data);
            $liability     = $this->resolveLiabilityAccount($claim->company_id);

            // Settles exactly what was accrued — never recomputed from
            // the lines, so a claim always clears to zero even if the
            // exchange rate has since changed.
            $amount = (string) $claim->amount_functional;

            if (bccomp($amount, '0', 2) <= 0) {
                throw new \DomainException("Expense claim {$claim->claim_code} has no approved amount to pay.");
            }

            $entry = $this->journalEntryService->post(
                companyId: $claim->company_id,
                entryDate: $paymentDate,
                lines: [
                    [
                        'chart_of_account_id' => $liability->id,
                        'debit'               => $amount,
                        'credit'              => '0',
                        'description'         => 'Expense claim '.$claim->claim_code.' — settled',
                    ],
                    [
                        'chart_of_account_id' => $sourceAccount->id,
                        'debit'               => '0',
                        'credit'              => $amount,
                        'description'         => 'Expense claim '.$claim->claim_code.' — paid to '.$claim->partner->name_en,
                    ],
                ],
                sourceType: 'expense_claim_payment',
                sourceId: $claim->id,
                narration: "Expense claim {$claim->claim_code} paid — {$claim->partner->name_en}",
                actor: $actor,
            );

            $claim->update([
                'status'                   => ExpenseClaim::STATUS_PAID,
                'payment_date'             => $paymentDate,
                'payment_source_type'      => $sourceType,
                'payment_bank_account_id'  => $sourceType === ExpenseClaim::SOURCE_BANK_ACCOUNT ? $data['payment_bank_account_id'] : null,
                'payment_safe_account_id'  => $sourceType === ExpenseClaim::SOURCE_CASH_SAFE ? $data['payment_safe_account_id'] : null,
                'payment_reference'        => $data['payment_reference'] ?? null,
                'payment_journal_entry_id' => $entry->id,
                'paid_at'                  => now(),
                'paid_by'                  => $actor->id,
            ]);

            AuditLog::record($actor, 'expense_claim.paid', $claim, $before, $claim->fresh()->toArray());

            return $claim->fresh(['lines', 'partner', 'paymentJournalEntry.lines']);
        });
    }

    // ── Internals ─────────────────────────────────────────────────

    /**
     * Replace-all rather than diff-and-patch: a claim is only ever
     * edited as a whole form, and lines carry no downstream references
     * while the claim is still a draft.
     */
    private function syncLines(ExpenseClaim $claim, array $lines): void
    {
        $claim->lines()->delete();

        foreach (array_values($lines) as $index => $line) {
            $this->assertAccountBelongsToCompany($claim->company_id, (int) $line['expense_account_id'], 'expense');

            ExpenseClaimLine::create([
                'company_id'         => $claim->company_id,
                'expense_claim_id'   => $claim->id,
                'expense_account_id' => $line['expense_account_id'],
                'cost_center_id'     => $line['cost_center_id'] ?? null,
                'expense_date'       => $line['expense_date'],
                'description'        => $line['description'],
                'amount'             => $line['amount'],
                'sort_order'         => $index + 1,
            ]);
        }
    }

    /**
     * total_amount is the claim's own currency; amount_functional is
     * the sum of the per-line converted figures, which is the number
     * the journal entry will use. Building it the same way here means
     * the two can never disagree.
     */
    private function recalculateTotals(ExpenseClaim $claim): void
    {
        $total      = '0.00';
        $functional = '0.00';

        foreach ($claim->lines()->get() as $line) {
            $total      = bcadd($total, (string) $line->amount, 2);
            $functional = bcadd($functional, bcmul((string) $line->amount, (string) $claim->exchange_rate, 2), 2);
        }

        $claim->update([
            'total_amount'      => $total,
            'amount_functional' => $functional,
        ]);
    }

    private function resolveLiabilityAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, ExpenseClaim::LIABILITY_ACCOUNT_CODE)
            ?? throw new \DomainException(
                'Chart of Accounts is missing system account '.ExpenseClaim::LIABILITY_ACCOUNT_CODE
                .' (Other Creditors) — check the seeder.'
            );
    }

    private function resolvePaymentSourceAccount(int $companyId, string $sourceType, array $data): ChartOfAccount
    {
        if ($sourceType === ExpenseClaim::SOURCE_BANK_ACCOUNT) {
            $bankAccount = BankAccount::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->where('id', $data['payment_bank_account_id'] ?? 0)
                ->first();

            if (! $bankAccount) {
                throw new \DomainException('The selected bank account does not belong to this company.');
            }

            if (! $bankAccount->coa_account_id) {
                throw new \DomainException('The selected bank account is missing its Chart of Accounts link.');
            }

            return ChartOfAccount::withoutGlobalScopes()->findOrFail($bankAccount->coa_account_id);
        }

        $safeId = (int) ($data['payment_safe_account_id'] ?? 0);

        $this->assertAccountBelongsToCompany($companyId, $safeId, 'cash safe');

        return $this->resolveCashSafe($companyId, $safeId);
    }

    /**
     * AUDIT M2 FIX (Sept 2026): the "safe" must really be a cash safe.
     *
     * Before, ANY account in the company's chart could be sent as the
     * "safe" — e.g. 411 Sales or an expense account — and the payment
     * would credit it, silently corrupting the books. Now the account
     * must be exactly what the Pay screen offers: an active, postable
     * account sitting directly under 131 Cash.
     */
    private function resolveCashSafe(int $companyId, int $safeId): ChartOfAccount
    {
        $cashParent = ChartOfAccount::findByCode($companyId, '131');

        $safe = $cashParent
            ? ChartOfAccount::withoutGlobalScopes()
                ->forCompany($companyId)
                ->postable()
                ->active()
                ->where('parent_id', $cashParent->id)
                ->where('id', $safeId)
                ->first()
            : null;

        if (! $safe) {
            throw new \DomainException('The selected account is not a cash safe. Choose an active safe under 131 Cash.');
        }

        return $safe;
    }

    /**
     * Chart of Accounts rows are shared platform-wide (company_id
     * NULL) or owned by one company. Either is fine; another
     * company's is not — the same cross-company guard
     * StockLedgerService applies to locations and projects.
     */
    private function assertAccountBelongsToCompany(int $companyId, int $accountId, string $label): void
    {
        $ok = ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->where('id', $accountId)
            ->exists();

        if (! $ok) {
            throw new \DomainException("The selected {$label} account does not belong to this company.");
        }
    }

    private function assertEmployee(int $companyId, int $partnerId): Partner
    {
        $partner = Partner::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('id', $partnerId)
            ->first();

        if (! $partner) {
            throw new \DomainException('The selected employee does not belong to this company.');
        }

        $types = $partner->types ?? [];
        if (! in_array(Partner::TYPE_EMPLOYEE, is_array($types) ? $types : [], true)) {
            throw new \DomainException(
                "\"{$partner->name_en}\" is not registered as an employee — expense claims can only be raised for employees."
            );
        }

        return $partner;
    }

    private function assertEditable(ExpenseClaim $claim): void
    {
        if (! $claim->isEditable()) {
            throw new \DomainException(
                "Expense claim {$claim->claim_code} can no longer be edited — it is {$claim->status}. "
                .'Reopen it to draft first.'
            );
        }
    }

    private function assertStatus(ExpenseClaim $claim, array $allowed, string $target): void
    {
        if (! in_array($claim->status, $allowed, true)) {
            throw new \DomainException(
                "Expense claim {$claim->claim_code} cannot be {$target} from status '{$claim->status}'. "
                .'Allowed from: '.implode(', ', $allowed).'.'
            );
        }
    }

    private function generateClaimCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\ExpenseClaim::class, 'claim_code', 'EC', $date);
    }
}
