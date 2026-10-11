<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LetterOfCreditIssuanceService
//
//  Business logic for individual Letter of Credit issuances — the
//  layer beneath the LC Facility (CreditFacility, facility_type =
//  'import_lc') and its rate card (FacilityLcType). Ported from
//  CashVero's LetterOfCreditIssuanceController /
//  LcSettlementInternalMoneyTransferController (see CashVero → Vero
//  CFO Treasury Gap Analysis §1.4), rebuilt against VeroCFO's real
//  Accounting Engine — no Odoo, no statement ledgers. Structurally a
//  close sibling of LgIssuanceService; read that file's header first
//  for the shared conventions (real GL posting, Set to Draft / Undo
//  correction philosophy) — only what's genuinely different for LC
//  is re-explained below.
//
//  ══ WHAT'S DIFFERENT FROM LG (confirmed with Mahmoud) ════════════
//
//  1. Only 2 funding sources — 'facility' and 'cash_cover'. CashVero's
//     "Against CD"/"Against TD" turned out to be facility-level
//     collateral (CreditFacility::isLetterOfCredit() +
//     lc_security_type = 'fully_secured'), already fully built —
//     nothing issuance-level needed for it here.
//
//  2. Commission is ONE-TIME at issuance, not periodic — no accrual
//     job, no next_commission_accrual_date.
//
//  3. financed_by ('bank' | 'self') — genuinely new. markAsPaid()
//     pays the supplier (bank draws its facility, or the company pays
//     directly) and, per Mahmoud's explicit confirmation, the
//     Supplier Bill is allocated/settled EITHER WAY at that moment —
//     the supplier is paid regardless of who ultimately fronts the
//     cash. For a bank-financed LC, the company's remaining
//     obligation shifts to the BANK (bank_debt_outstanding), tracked
//     independently of the (now-settled) Bill.
//
//  4. Settlement (settle()) only applies to a bank-financed, paid LC
//     — repayment installments (principal + interest) against
//     bank_debt_outstanding. Interest is entered PER SETTLEMENT, not
//     at issuance (confirmed: not knowable until then) — ported from
//     CashVero's own comment reverting an earlier attempt to charge
//     it upfront. undoSettlement() replaces CashVero's reset() (which
//     wiped every settlement at once) with the same per-row,
//     most-recent-first Undo used everywhere else in VeroCFO.
//
//  5. Full Purchase-cycle linkage (confirmed in scope now, not
//     deferred) — purchase_order_id at issuance, supplier_bill_id +
//     a real allocation against SupplierBill.amount_paid/
//     amount_outstanding at markAsPaid(), using the exact same AP
//     account resolution as SupplierPaymentService::resolveApAccount()
//     (221001 functional-currency / 221002 foreign-currency).
//
//  6. Misc expenses (LcIssuanceExpense) — independent, addable/
//     editable/deletable at any time, each with its own reversible
//     posting. Not part of the Set-to-Draft/Undo chain.
//
//  ══ SET TO DRAFT / DELETE / UNDO (as requested) ══════════════════
//  Same rule as LG: setToDraft() only while isPristine() (no
//  mark_paid/settlement history active) — reverses the issuance's own
//  journal entry(ies), unlocks the form. delete() only on a draft.
//  undoMarkAsPaid()/undoSettlement() correct one specific event
//  without touching the issuance underneath — most-recent-first only.
//
//  @layer    Service
//  @depends  LetterOfCreditIssuance, LcIssuanceHistory,
//            LcIssuanceMovement, LcIssuanceExpense, CreditFacility,
//            FacilityLcType, Partner, PurchaseOrder, SupplierBill,
//            BankAccount, ChartOfAccount, JournalEntry,
//            JournalEntryService, FiscalYearService,
//            CompanyActiveCurrency, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityLcType;
use App\Models\FinancialInstitutions\LcIssuanceExpense;
use App\Models\FinancialInstitutions\LcIssuanceHistory;
use App\Models\FinancialInstitutions\LcIssuanceMovement;
use App\Models\FinancialInstitutions\LetterOfCreditIssuance;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\FiscalYearService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LetterOfCreditIssuanceService
{
    use \App\Services\FinancialInstitutions\Concerns\ChecksInterestVariance;
    use \App\Services\FinancialInstitutions\Concerns\ComputesFacilityExposure;

    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
    ) {}

    // ── List (index page — one tab per LC Type) ─────────────────────

    public function paginate(int $companyId, string $lcType, array $filters = []): LengthAwarePaginator
    {
        return LetterOfCreditIssuance::with([
                'bank' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name_en'),
                'supplier:id,name_en,name_ar',
                'currency' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code'),
            ])
            ->where('letter_of_credit_issuances.company_id', $companyId)
            ->where('lc_type', $lcType)
            ->when($filters['start_date'] ?? null, fn ($q, $d) => $q->where('issuance_date', '>=', $d))
            ->when($filters['end_date']   ?? null, fn ($q, $d) => $q->where('due_date', '<=', $d))
            ->orderByDesc('issuance_date')
            ->paginate(20)
            ->withQueryString();
    }

    public function stats(int $companyId, string $lcType, array $filters = []): array
    {
        $base = fn () => LetterOfCreditIssuance::where('letter_of_credit_issuances.company_id', $companyId)
            ->where('lc_type', $lcType)
            ->when($filters['start_date'] ?? null, fn ($q, $d) => $q->where('issuance_date', '>=', $d))
            ->when($filters['end_date']   ?? null, fn ($q, $d) => $q->where('due_date', '<=', $d));

        $totalCount   = $base()->count();
        $runningCount = $base()->where('status', 'running')->count();

        $byCurrency = $base()
            ->where('status', 'running')
            ->join('currencies', 'currencies.id', '=', 'letter_of_credit_issuances.currency_id')
            ->groupBy('currencies.id', 'currencies.code')
            ->selectRaw('currencies.code as currency_code, SUM(letter_of_credit_issuances.lc_amount) as total_outstanding')
            ->orderBy('currencies.code')
            ->get();

        $bankDebtOutstanding = $base()->where('financed_by', 'bank')->sum('bank_debt_outstanding');

        $nearDueCount = $base()
            ->where('status', 'running')
            ->whereBetween('due_date', [now(), now()->addDays(30)])
            ->count();

        return [
            'total_count'           => $totalCount,
            'running_count'         => $runningCount,
            'by_currency'           => $byCurrency,
            'bank_debt_outstanding' => $bankDebtOutstanding,
            'near_due_count'        => $nearDueCount,
        ];
    }

    // ── Eligible lookups ─────────────────────────────────────────────

    public function eligibleSuppliers(int $companyId)
    {
        return Partner::where('company_id', $companyId)->active()
            ->whereJsonContains('types', Partner::TYPE_SUPPLIER)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar']);
    }

    public function eligiblePurchaseOrders(int $companyId, int $partnerId)
    {
        return PurchaseOrder::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->get(['id', 'po_code', 'subject']);
    }

    /**
     * Bills eligible to be paid off by this LC at Mark as Paid time —
     * posted, still owing money, tied to the LC's own supplier (and
     * PO, if one was picked at issuance).
     */
    public function eligibleSupplierBills(int $companyId, int $partnerId, ?int $purchaseOrderId = null)
    {
        return SupplierBill::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('status', SupplierBill::STATUS_POSTED)
            ->where('amount_outstanding', '>', 0)
            ->when($purchaseOrderId, fn ($q) => $q->where('purchase_order_id', $purchaseOrderId))
            ->orderByDesc('bill_date')
            ->get(['id', 'bill_code', 'bill_value', 'amount_outstanding', 'currency_id']);
    }

    public function facilityRoomSummary(int $companyId, int $creditFacilityId): array
    {
        $facility = CreditFacility::where('company_id', $companyId)->findOrFail($creditFacilityId);

        // M-30: convert to the facility currency and net off unreleased cash cover.
        $running = LetterOfCreditIssuance::where('company_id', $companyId)
            ->where('credit_facility_id', $creditFacilityId)
            ->where('status', 'running')
            ->get();
        $exp = $this->facilityExposure($companyId, $facility, $running, 'lc_amount', 'facility_lc_type_id');

        return [
            'facility_limit'    => $facility->facility_limit,
            'outstanding_total' => $exp['net'],
            'gross_outstanding' => $exp['gross'],
            'cash_cover_held'   => $exp['cash_cover'],
            'available_room'    => bcsub((string) $facility->facility_limit, $exp['net'], 2),
            'outstanding_by_lc_type_id' => $exp['by_type'],
            'lc_security_type'  => $facility->lc_security_type,
        ];
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): LetterOfCreditIssuance
    {
        if ($this->fiscalYearService->isPeriodClosed($companyId, (string) $data['issuance_date'])) {
            throw new \DomainException('This accounting period is closed — the LC cannot be issued.');
        }

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $this->assertAccountsMatchBank($data);

            $data['company_id']      = $companyId;
            $data['created_by']      = $actor->id;
            $data['status']          = LetterOfCreditIssuance::STATUS_RUNNING;
            $data['posting_status']  = LetterOfCreditIssuance::POSTING_STATUS_POSTED;
            $data['duration_days']   = (int) $data['duration_days'];
            $data['due_date']        = Carbon::parse($data['issuance_date'])->addDays($data['duration_days'])->toDateString();

            $data = $this->computeDerivedAmounts($data, $companyId);

            $issuance = LetterOfCreditIssuance::create($data);

            if ($issuance->posting_mode === 'new_issuance') {
                $movements = $this->buildIssuanceMovements($issuance, $actor);
                $this->postMovementsAsJournalEntry(
                    $issuance,
                    $movements,
                    "LC Issuance — {$issuance->lc_code}",
                    $actor,
                    (string) $issuance->issuance_date
                );
            }

            AuditLog::record($actor, 'lc_issuance.created', $issuance, null, $issuance->toArray());

            return $issuance->fresh(['bank', 'currency', 'supplier']);
        });
    }

    /**
     * Re-saves a reopened draft — same shape as LgIssuanceService::
     * update(). Only reachable while isPristine() still holds.
     */
    public function update(LetterOfCreditIssuance $issuance, array $data, User $actor): LetterOfCreditIssuance
    {
        if (! $issuance->isDraft()) {
            throw new \DomainException('Only a draft LC issuance can be edited — use "Set to Draft" first.');
        }
        if (! $issuance->isPristine()) {
            throw new \DomainException('This issuance has lifecycle history attached — it should not be possible to reach draft in this state.');
        }
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $data['issuance_date'])) {
            throw new \DomainException('This accounting period is closed — the LC cannot be re-posted.');
        }

        return DB::transaction(function () use ($issuance, $data, $actor) {
            $this->assertAccountsMatchBank($data);

            $data['status']          = LetterOfCreditIssuance::STATUS_RUNNING;
            $data['posting_status']  = LetterOfCreditIssuance::POSTING_STATUS_POSTED;
            $data['duration_days']   = (int) $data['duration_days'];
            $data['due_date']        = Carbon::parse($data['issuance_date'])->addDays($data['duration_days'])->toDateString();

            $data = $this->computeDerivedAmounts($data, $issuance->company_id);

            $issuance->movements()->delete();
            $issuance->update($data);

            if ($issuance->posting_mode === 'new_issuance') {
                $movements = $this->buildIssuanceMovements($issuance->fresh(), $actor);
                $this->postMovementsAsJournalEntry(
                    $issuance,
                    $movements,
                    "LC Issuance (re-posted) — {$issuance->lc_code}",
                    $actor,
                    (string) $issuance->issuance_date
                );
            }

            AuditLog::record($actor, 'lc_issuance.updated', $issuance, null, $issuance->fresh()->toArray());

            return $issuance->fresh(['bank', 'currency', 'supplier']);
        });
    }

    // ── Set to Draft / Delete ────────────────────────────────────────

    public function setToDraft(LetterOfCreditIssuance $issuance, User $actor): LetterOfCreditIssuance
    {
        if (! $issuance->isPosted()) {
            throw new \DomainException('Only a posted LC issuance can be set back to draft.');
        }
        if (! $issuance->isPristine()) {
            throw new \DomainException('This LC has lifecycle history (Mark as Paid or a Settlement) attached — undo that action first, from the History table, before reopening the original issuance.');
        }
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $issuance->issuance_date)) {
            throw new \DomainException('This LC\'s accounting period is closed — it cannot be reopened.');
        }

        return DB::transaction(function () use ($issuance, $actor) {
            $before = $issuance->toArray();

            $this->reversePostedMovements($issuance, $actor, "Reopened to draft — LC {$issuance->lc_code}");

            $issuance->update(['posting_status' => LetterOfCreditIssuance::POSTING_STATUS_DRAFT]);

            AuditLog::record($actor, 'lc_issuance.set_to_draft', $issuance, $before, $issuance->fresh()->toArray());

            return $issuance->fresh();
        });
    }

    public function delete(LetterOfCreditIssuance $issuance, User $actor): void
    {
        if (! $issuance->isDraft()) {
            throw new \DomainException('Only a draft LC issuance can be deleted — use "Set to Draft" first.');
        }
        if ($issuance->movements()->where('status', LcIssuanceMovement::STATUS_POSTED)->exists()) {
            throw new \DomainException('This LC issuance still has posted movements attached — it cannot be deleted.');
        }
        if (! $issuance->isPristine()) {
            throw new \DomainException('This LC issuance still has lifecycle history attached — it cannot be deleted.');
        }
        if ($issuance->expenses()->where('status', LcIssuanceExpense::STATUS_POSTED)->exists()) {
            throw new \DomainException('This LC issuance still has posted expenses attached — reverse or delete them first.');
        }

        DB::transaction(function () use ($issuance, $actor) {
            $before = $issuance->toArray();

            $issuance->movements()->delete();
            $issuance->history()->delete();
            $issuance->expenses()->delete();
            $issuance->delete();

            AuditLog::record($actor, 'lc_issuance.deleted', $issuance, $before, null);
        });
    }

    // ── Mark as Paid ──────────────────────────────────────────────────

    /**
     * Pays the supplier — bank-financed (facility drawdown, company
     * now owes the BANK) or self-financed (company pays directly, no
     * ongoing debt). Either way, per Mahmoud's explicit confirmation,
     * the linked Supplier Bill is allocated/settled immediately —
     * the supplier is paid regardless of who fronted the cash.
     */
    public function markAsPaid(LetterOfCreditIssuance $issuance, array $data, User $actor): LetterOfCreditIssuance
    {
        if ($issuance->status !== LetterOfCreditIssuance::STATUS_RUNNING) {
            throw new \DomainException('Only a running LC can be marked as paid.');
        }
        if (! $issuance->isPosted()) {
            throw new \DomainException('This LC is a draft — save it before marking it as paid.');
        }

        $paidAt = $data['paid_at'] ?? now()->toDateString();
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $paidAt)) {
            throw new \DomainException('This accounting period is closed — Mark as Paid cannot be posted.');
        }

        $bill = SupplierBill::where('company_id', $issuance->company_id)->findOrFail($data['supplier_bill_id']);
        $amountToSupplier = (string) ($data['amount_paid_to_supplier'] ?? $bill->amount_outstanding);

        if (bccomp($amountToSupplier, (string) $bill->amount_outstanding, 2) > 0) {
            throw new \DomainException("Amount exceeds what's still outstanding on Bill {$bill->bill_code} ({$bill->amount_outstanding}).");
        }

        return DB::transaction(function () use ($issuance, $data, $actor, $paidAt, $bill, $amountToSupplier) {

            // H-21 — work out how the LC's cash cover is cleared by this
            // payment BEFORE the debt is opened: a bank-financed LC that was
            // covered in the same currency has the cover APPLIED against what
            // the bank financed, so the debt opened is only the uncovered part.
            $cover = $this->planCashCoverClearance($issuance, $amountToSupplier, (int) $bill->currency_id);

            $bankDebtOpened = $issuance->isFinancedByBank() ? bcsub($amountToSupplier, $cover['applied'], 2) : '0.00';

            $history = LcIssuanceHistory::create([
                'company_id'               => $issuance->company_id,
                'lc_issuance_id'           => $issuance->id,
                'event_type'               => 'mark_paid',
                'event_date'               => $paidAt,
                'supplier_bill_id'         => $bill->id,
                'amount_paid_to_supplier'  => $amountToSupplier,
                'bank_debt_opened'         => $bankDebtOpened,
                'cash_cover_released_amount' => $cover['total'],
                'notes'                    => $data['notes'] ?? null,
                'created_by'               => $actor->id,
            ]);

            $issuance->update([
                'status'                 => LetterOfCreditIssuance::STATUS_PAID,
                'paid_at'                => $paidAt,
                'supplier_bill_id'       => $bill->id,
                'payment_account_id'     => $issuance->isFinancedBySelf() ? ($data['payment_account_id'] ?? null) : null,
                'bank_debt_outstanding'  => bcadd((string) $issuance->bank_debt_outstanding, $bankDebtOpened, 2),
            ]);

            // Real Bill allocation — reduces amount_outstanding for
            // real, same fields SupplierPaymentService::allocate()
            // writes, regardless of financed_by (confirmed with
            // Mahmoud: the supplier is paid either way).
            $bill->update([
                'amount_paid'        => bcadd((string) $bill->amount_paid, $amountToSupplier, 2),
                'amount_outstanding' => bcsub((string) $bill->amount_outstanding, $amountToSupplier, 2),
            ]);

            $apAccount = $this->resolveApAccount($issuance->company_id, $bill->currency_id);

            $movement = LcIssuanceMovement::create(array_merge($this->tagMovementData($issuance), [
                'company_id'     => $issuance->company_id,
                'lc_issuance_id' => $issuance->id,
                'movement_type'  => 'supplier_settlement',
                'movement_date'  => $paidAt,
                'debit_coa_account_id'   => $apAccount->id,
                'credit_bank_account_id' => $issuance->isFinancedBySelf() ? ($data['payment_account_id'] ?? null) : null,
                'credit_coa_account_id'  => $issuance->isFinancedByBank() ? $this->resolveFacilityLiabilityAccount($issuance)->id : null,
                'amount'         => $amountToSupplier,
                'currency_id'    => $bill->currency_id,
                'description'    => "Supplier payment via LC {$issuance->lc_code} — Bill {$bill->bill_code}",
                'created_by'     => $actor->id,
            ]));

            // H-21 — the cash cover leaves 138002 in the SAME journal entry as
            // the payment, so undoing the payment reverses it automatically.
            $movements = new Collection([$movement]);
            foreach ($this->buildCashCoverClearanceMovements($issuance, $cover, $paidAt, $actor) as $coverMovement) {
                $movements->push($coverMovement);
            }
            if (bccomp($cover['total'], '0', 2) > 0) {
                $issuance->update([
                    'cash_cover_released_amount' => $cover['total'],
                    'cash_cover_released_at'     => $paidAt,
                    'cash_cover_release_mode'    => bccomp($cover['applied'], '0', 2) > 0 ? 'applied' : 'released',
                ]);
            }

            $entry = $this->postMovementsAsJournalEntry(
                $issuance,
                $movements,
                "LC Mark as Paid — {$issuance->lc_code}",
                $actor,
                $paidAt
            );
            if ($entry) {
                $history->update(['journal_entry_id' => $entry->id]);
            }

            AuditLog::record($actor, 'lc_issuance.marked_paid', $issuance, ['status' => 'running'], ['status' => 'paid']);

            return $issuance->fresh();
        });
    }

    public function undoMarkAsPaid(LetterOfCreditIssuance $issuance, User $actor): LetterOfCreditIssuance
    {
        $entry = $this->assertUndoable($issuance, 'mark_paid');

        return DB::transaction(function () use ($issuance, $entry, $actor) {
            if ($entry->journal_entry_id) {
                $je = JournalEntry::findOrFail($entry->journal_entry_id);
                $this->journalEntryService->reverse($je, $actor, "Undo Mark as Paid — LC {$issuance->lc_code}");
                LcIssuanceMovement::where('posted_journal_entry_id', $je->id)->update(['status' => LcIssuanceMovement::STATUS_REVERSED]);
            }

            if ($entry->supplier_bill_id) {
                $bill = SupplierBill::find($entry->supplier_bill_id);
                if ($bill) {
                    $bill->update([
                        'amount_paid'        => bcsub((string) $bill->amount_paid, (string) $entry->amount_paid_to_supplier, 2),
                        'amount_outstanding' => bcadd((string) $bill->amount_outstanding, (string) $entry->amount_paid_to_supplier, 2),
                    ]);
                }
            }

            $issuance->update([
                'status'                => LetterOfCreditIssuance::STATUS_RUNNING,
                'paid_at'               => null,
                'supplier_bill_id'      => null,
                'payment_account_id'    => null,
                'bank_debt_outstanding' => bcsub((string) $issuance->bank_debt_outstanding, (string) $entry->bank_debt_opened, 2),
                // H-21 — the journal entry reversal above also puts the cash cover back on 138002.
                'cash_cover_released_amount' => null,
                'cash_cover_released_at'     => null,
                'cash_cover_release_mode'    => null,
            ]);

            $entry->update(['undone_at' => now(), 'undone_by' => $actor->id]);

            AuditLog::record($actor, 'lc_issuance.mark_paid_undone', $issuance, ['status' => 'paid'], ['status' => 'running']);

            return $issuance->fresh();
        });
    }

    // ── Settlement (bank-financed repayment) ──────────────────────────

    public function settle(LetterOfCreditIssuance $issuance, array $data, User $actor): LetterOfCreditIssuance
    {
        if (! $issuance->isFinancedByBank()) {
            throw new \DomainException('Only a bank-financed LC has a bank balance to settle.');
        }
        if ($issuance->status !== LetterOfCreditIssuance::STATUS_PAID) {
            throw new \DomainException('This LC has not been marked as paid yet — nothing to settle.');
        }

        $principal = (string) ($data['principal_amount'] ?? 0);
        $interest  = (string) ($data['interest_amount'] ?? 0);

        if (bccomp($principal, (string) $issuance->bank_debt_outstanding, 2) > 0) {
            throw new \DomainException("Principal exceeds what's still owed to the bank ({$issuance->bank_debt_outstanding}).");
        }

        $eventDate = $data['settlement_date'] ?? now()->toDateString();
        $this->interestWarnings = [];
        $rate = $issuance->creditFacility->interest_rate ?? null;
        if ($rate && $issuance->paid_at && bccomp($interest, '0', 2) > 0) {
            $days = (int) abs(Carbon::parse($issuance->paid_at)->diffInDays(Carbon::parse($eventDate)));
            $this->checkInterestVariance($interest, (string) $issuance->bank_debt_outstanding, (string) $rate, $days, 'LC '.$issuance->lc_code, true);
        }
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $eventDate)) {
            throw new \DomainException('This accounting period is closed — the settlement cannot be posted.');
        }

        return DB::transaction(function () use ($issuance, $data, $actor, $principal, $interest, $eventDate) {

            $oldDebt = (string) $issuance->bank_debt_outstanding;
            $newDebt = bcsub($oldDebt, $principal, 2);

            $history = LcIssuanceHistory::create([
                'company_id'                   => $issuance->company_id,
                'lc_issuance_id'               => $issuance->id,
                'event_type'                   => 'settlement',
                'event_date'                   => $eventDate,
                'principal_amount'             => $principal,
                'interest_amount'              => $interest,
                'interest_expense_account_id'  => bccomp($interest, '0', 2) > 0 ? ($data['interest_expense_account_id'] ?? null) : null,
                'settlement_from_account_id'   => $data['settlement_from_account_id'],
                'bank_debt_after_event'        => $newDebt,
                'old_bank_debt_outstanding'    => $oldDebt,
                'notes'                        => $data['notes'] ?? null,
                'created_by'                   => $actor->id,
            ]);

            $issuance->update(['bank_debt_outstanding' => $newDebt]);

            $movements = new Collection();

            if (bccomp($principal, '0', 2) > 0) {
                $movements->push(LcIssuanceMovement::create(array_merge($this->tagMovementData($issuance), [
                    'company_id'             => $issuance->company_id,
                    'lc_issuance_id'         => $issuance->id,
                    'movement_type'          => 'settlement_principal',
                    'movement_date'          => $eventDate,
                    'debit_coa_account_id'   => $this->resolveFacilityLiabilityAccount($issuance)->id,
                    'credit_bank_account_id' => $data['settlement_from_account_id'],
                    'amount'                 => $principal,
                    'currency_id'            => $issuance->currency_id,
                    'description'            => "Settlement principal — LC {$issuance->lc_code}",
                    'created_by'             => $actor->id,
                ])));
            }

            if (bccomp($interest, '0', 2) > 0) {
                $movements->push(LcIssuanceMovement::create(array_merge($this->tagMovementData($issuance), [
                    'company_id'             => $issuance->company_id,
                    'lc_issuance_id'         => $issuance->id,
                    'movement_type'          => 'settlement_interest',
                    'movement_date'          => $eventDate,
                    'debit_coa_account_id'   => $data['interest_expense_account_id'],
                    'credit_bank_account_id' => $data['settlement_from_account_id'],
                    'amount'                 => $interest,
                    'currency_id'            => $issuance->currency_id,
                    'description'            => "Settlement interest — LC {$issuance->lc_code}",
                    'created_by'             => $actor->id,
                ])));
            }

            if ($movements->isNotEmpty()) {
                $entry = $this->postMovementsAsJournalEntry(
                    $issuance,
                    $movements,
                    "LC Settlement — {$issuance->lc_code}",
                    $actor,
                    $eventDate
                );
                if ($entry) {
                    $history->update(['journal_entry_id' => $entry->id]);
                }
            }

            AuditLog::record($actor, 'lc_issuance.settled', $issuance, ['bank_debt_outstanding' => $oldDebt], ['bank_debt_outstanding' => $newDebt]);

            return $issuance->fresh();
        });
    }

    public function undoSettlement(LetterOfCreditIssuance $issuance, User $actor): LetterOfCreditIssuance
    {
        $entry = $this->assertUndoable($issuance, 'settlement');

        return DB::transaction(function () use ($issuance, $entry, $actor) {
            if ($entry->journal_entry_id) {
                $je = JournalEntry::findOrFail($entry->journal_entry_id);
                $this->journalEntryService->reverse($je, $actor, "Undo settlement — LC {$issuance->lc_code}");
                LcIssuanceMovement::where('posted_journal_entry_id', $je->id)->update(['status' => LcIssuanceMovement::STATUS_REVERSED]);
            }

            $issuance->update(['bank_debt_outstanding' => $entry->old_bank_debt_outstanding]);

            $entry->update(['undone_at' => now(), 'undone_by' => $actor->id]);

            AuditLog::record($actor, 'lc_issuance.settlement_undone', $issuance, ['bank_debt_outstanding' => $entry->bank_debt_after_event], ['bank_debt_outstanding' => $entry->old_bank_debt_outstanding]);

            return $issuance->fresh();
        });
    }

    // ── Cash cover release on expiry / cancellation (H-21) ───────────

    /**
     * An LC that is never drawn (expired, cancelled, or simply not
     * needed any more) must hand its cash cover back, otherwise 138002
     * and the bank stay misstated forever.
     *   Dr Bank (the account the cover came from) / Cr 138002
     * The LC is then closed as 'expired' (it can no longer be paid).
     * Fully undoable (most recent action first, like every other LC action).
     */
    public function releaseCashCoverOnExpiry(LetterOfCreditIssuance $issuance, array $data, User $actor): LetterOfCreditIssuance
    {
        if (! $issuance->isPosted()) {
            throw new \DomainException('This LC is a draft — save it before closing it.');
        }
        if ($issuance->status !== LetterOfCreditIssuance::STATUS_RUNNING) {
            throw new \DomainException('Only a running LC can be closed as expired — a paid LC has already cleared its cash cover.');
        }
        if ($issuance->cash_cover_released_at) {
            throw new \DomainException('This LC\'s cash cover has already been released.');
        }

        $eventDate = $data['release_date'] ?? now()->toDateString();
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $eventDate)) {
            throw new \DomainException('This accounting period is closed — the release cannot be posted.');
        }

        $cover = (string) ($issuance->cash_cover_amount ?? '0.00');

        return DB::transaction(function () use ($issuance, $data, $actor, $eventDate, $cover) {
            $history = LcIssuanceHistory::create([
                'company_id'                 => $issuance->company_id,
                'lc_issuance_id'             => $issuance->id,
                'event_type'                 => 'cash_cover_release',
                'event_date'                 => $eventDate,
                'cash_cover_released_amount' => $cover,
                'notes'                      => $data['notes'] ?? null,
                'created_by'                 => $actor->id,
            ]);

            if (bccomp($cover, '0', 2) > 0) {
                $movement = $this->makeCashCoverReleaseMovement($issuance, $cover, $eventDate, $actor, "Cash cover released (LC closed unused) — LC {$issuance->lc_code}");

                $entry = $this->postMovementsAsJournalEntry(
                    $issuance, new Collection([$movement]), "LC cash cover released — {$issuance->lc_code}", $actor, $eventDate
                );
                if ($entry) {
                    $history->update(['journal_entry_id' => $entry->id]);
                }
            }

            $issuance->update([
                'status'                     => LetterOfCreditIssuance::STATUS_EXPIRED,
                'cash_cover_released_amount' => $cover,
                'cash_cover_released_at'     => $eventDate,
                'cash_cover_release_mode'    => 'released',
            ]);

            AuditLog::record($actor, 'lc_issuance.cash_cover_released', $issuance, ['status' => 'running'], ['status' => 'expired']);

            return $issuance->fresh();
        });
    }

    public function undoCashCoverRelease(LetterOfCreditIssuance $issuance, User $actor): LetterOfCreditIssuance
    {
        $entry = $this->assertUndoable($issuance, 'cash_cover_release');

        return DB::transaction(function () use ($issuance, $entry, $actor) {
            if ($entry->journal_entry_id) {
                $je = JournalEntry::findOrFail($entry->journal_entry_id);
                $this->journalEntryService->reverse($je, $actor, "Undo cash cover release — LC {$issuance->lc_code}");
                LcIssuanceMovement::where('posted_journal_entry_id', $je->id)->update(['status' => LcIssuanceMovement::STATUS_REVERSED]);
            }

            $issuance->update([
                'status'                     => LetterOfCreditIssuance::STATUS_RUNNING,
                'cash_cover_released_amount' => null,
                'cash_cover_released_at'     => null,
                'cash_cover_release_mode'    => null,
            ]);

            $entry->update(['undone_at' => now(), 'undone_by' => $actor->id]);

            AuditLog::record($actor, 'lc_issuance.cash_cover_release_undone', $issuance, ['status' => 'expired'], ['status' => 'running']);

            return $issuance->fresh();
        });
    }

    // ── Expenses (independent — not part of the undo chain) ───────────

    public function addExpense(LetterOfCreditIssuance $issuance, array $data, User $actor): LcIssuanceExpense
    {
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $data['expense_date'])) {
            throw new \DomainException('This accounting period is closed — the expense cannot be posted.');
        }

        return DB::transaction(function () use ($issuance, $data, $actor) {
            $expense = LcIssuanceExpense::create([
                'company_id'            => $issuance->company_id,
                'lc_issuance_id'        => $issuance->id,
                'expense_name'          => $data['expense_name'],
                'expense_date'          => $data['expense_date'],
                'amount'                => $data['amount'],
                'currency_id'           => $data['currency_id'],
                'exchange_rate'         => $data['exchange_rate'] ?? 1,
                'expense_account_id'    => $data['expense_account_id'],
                'paid_from_account_id'  => $data['paid_from_account_id'],
                'status'                => LcIssuanceExpense::STATUS_POSTED,
                'created_by'            => $actor->id,
            ]);

            $this->postExpenseJournalEntry($expense, $actor);

            AuditLog::record($actor, 'lc_issuance_expense.created', $expense, null, $expense->fresh()->toArray());

            return $expense->fresh();
        });
    }

    public function updateExpense(LcIssuanceExpense $expense, array $data, User $actor): LcIssuanceExpense
    {
        return DB::transaction(function () use ($expense, $data, $actor) {
            $this->reverseExpenseJournalEntry($expense, $actor);

            $expense->update([
                'expense_name'          => $data['expense_name'],
                'expense_date'          => $data['expense_date'],
                'amount'                => $data['amount'],
                'currency_id'           => $data['currency_id'],
                'exchange_rate'         => $data['exchange_rate'] ?? 1,
                'expense_account_id'    => $data['expense_account_id'],
                'paid_from_account_id'  => $data['paid_from_account_id'],
                'status'                => LcIssuanceExpense::STATUS_POSTED,
                'journal_entry_id'      => null,
            ]);

            $this->postExpenseJournalEntry($expense->fresh(), $actor);

            AuditLog::record($actor, 'lc_issuance_expense.updated', $expense, null, $expense->fresh()->toArray());

            return $expense->fresh();
        });
    }

    public function deleteExpense(LcIssuanceExpense $expense, User $actor): void
    {
        DB::transaction(function () use ($expense, $actor) {
            $before = $expense->toArray();

            $this->reverseExpenseJournalEntry($expense, $actor);
            $expense->delete();

            AuditLog::record($actor, 'lc_issuance_expense.deleted', $expense, $before, null);
        });
    }

    private function postExpenseJournalEntry(LcIssuanceExpense $expense, User $actor): void
    {
        // Audit C8: the expense is in its own currency — convert it (the
        // rate typed on the expense wins; otherwise the rate table).
        $fx = app(\App\Services\Accounting\FxService::class);
        $date = substr((string) $expense->expense_date, 0, 10);
        $expenseRate = $fx->resolveRate($expense->company_id, $expense->currency_id ? (int) $expense->currency_id : null, $date, $expense->exchange_rate);
        $amount = $fx->toFunctional($expense->amount, $expenseRate);

        $entry = $this->journalEntryService->post(
            companyId: $expense->company_id,
            entryDate: $date,
            // Audit H12/H15: stamp currency + rate so the paying bank keeps its exact foreign balance.
            lines: $fx->tagLines([
                ['chart_of_account_id' => $expense->expense_account_id, 'debit' => $amount, 'credit' => '0.00', 'description' => $expense->expense_name],
                ['chart_of_account_id' => $this->resolveBankAccountCoaId($expense->paid_from_account_id), 'debit' => '0.00', 'credit' => $amount, 'description' => $expense->expense_name],
            ], $expense->currency_id ? (int) $expense->currency_id : null, $expenseRate),
            sourceType: 'lc_issuance_expense',
            sourceId: $expense->id,
            narration: "LC Expense — {$expense->expense_name}",
            actor: $actor,
        );

        $expense->update(['journal_entry_id' => $entry->id]);
    }

    private function reverseExpenseJournalEntry(LcIssuanceExpense $expense, User $actor): void
    {
        if (! $expense->journal_entry_id) {
            return;
        }

        $je = JournalEntry::find($expense->journal_entry_id);
        if ($je && $je->status === JournalEntry::STATUS_POSTED) {
            $this->journalEntryService->reverse($je, $actor, "Reversed — LC Expense {$expense->expense_name}");
        }

        $expense->update(['status' => LcIssuanceExpense::STATUS_REVERSED]);
    }

    // ── Settlements list (standalone, cross-LC) ───────────────────────

    /**
     * Every settlement across every LC, newest first — the standalone
     * treasury view Mahmoud asked for, separate from the per-LC
     * History table. Deliberately still reads from lc_issuance_history
     * (event_type = 'settlement') rather than a second table — a
     * settlement's data lives in exactly one place either way; this
     * is just a different lens onto the same rows, same principle as
     * an Account Statement being a filtered view of journal_entry_lines
     * rather than its own ledger.
     */
    public function paginateSettlements(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return LcIssuanceHistory::with([
                'lcIssuance:id,lc_code,transaction_name,lc_type,currency_id,financed_by,bank_debt_outstanding',
                'lcIssuance.currency' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code'),
                'lcIssuance.bank' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name_en'),
                'settlementFromAccount:id,account_number,bank_id',
                'settlementFromAccount.bank' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name_en'),
            ])
            ->where('lc_issuance_history.company_id', $companyId)
            ->where('event_type', 'settlement')
            ->when($filters['start_date'] ?? null, fn ($q, $d) => $q->where('event_date', '>=', $d))
            ->when($filters['end_date']   ?? null, fn ($q, $d) => $q->where('event_date', '<=', $d))
            ->when($filters['bank_id'] ?? null, fn ($q, $bankId) => $q->whereHas('lcIssuance', fn ($lc) => $lc->where('bank_id', $bankId)))
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
    }

    public function settlementsStats(int $companyId, array $filters = []): array
    {
        $base = fn () => LcIssuanceHistory::where('lc_issuance_history.company_id', $companyId)
            ->where('event_type', 'settlement')
            ->when($filters['start_date'] ?? null, fn ($q, $d) => $q->where('event_date', '>=', $d))
            ->when($filters['end_date']   ?? null, fn ($q, $d) => $q->where('event_date', '<=', $d));

        return [
            'total_count'            => $base()->count(),
            'total_principal'        => $base()->sum('principal_amount'),
            'total_interest'         => $base()->sum('interest_amount'),
            'still_owed_to_bank'     => LetterOfCreditIssuance::where('company_id', $companyId)
                ->where('financed_by', 'bank')->sum('bank_debt_outstanding'),
        ];
    }

    // ── Private: undo guard ──────────────────────────────────────────

    /**
     * The bank accounts chosen on the form must belong to the LC's own bank and, where the
     * form states a currency for them, be in that currency. The form only offers matching
     * accounts, but nothing else checked it — a stale or hand-made request could post the
     * LC's cash cover or fees to another bank's (or another currency's) account.
     */
    private function assertAccountsMatchBank(array $data): void
    {
        $checks = [
            'cash_cover_account_id'      => ['Cash cover account', null], // currency is settled server-side (computeDerivedAmounts), so only the bank is checked here
            'fees_commission_account_id' => ['Commission account', $data['currency_id'] ?? null],
        ];

        foreach ($checks as $field => [$label, $currencyId]) {
            if (empty($data[$field])) {
                continue;
            }
            $account = BankAccount::find($data[$field]);
            if (! $account) {
                throw new \DomainException("{$label} not found.");
            }
            if (! empty($data['bank_id']) && (int) $account->bank_id !== (int) $data['bank_id']) {
                throw new \DomainException("{$label} does not belong to the selected bank.");
            }
            if ($currencyId && (int) $account->currency_id !== (int) $currencyId) {
                throw new \DomainException("{$label} is not in the currency chosen for it.");
            }
        }
    }

    private function assertUndoable(LetterOfCreditIssuance $issuance, string $expectedEventType): LcIssuanceHistory
    {
        $entry = $issuance->undoableHistoryEntry();

        if (! $entry) {
            throw new \DomainException('This LC has no lifecycle action to undo.');
        }
        if ($entry->event_type !== $expectedEventType) {
            throw new \DomainException("The most recent action on this LC was a {$entry->event_type}, not a {$expectedEventType} — undo actions must be reversed in order, most recent first.");
        }
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $entry->event_date)) {
            throw new \DomainException('This action\'s accounting period is closed — it cannot be undone.');
        }

        return $entry;
    }

    // ── Private: computed values ─────────────────────────────────────

    /**
     * Shared derivation logic between create() and update() — exchange
     * rate, cash cover %/amount, commission, and the facility rate-card
     * snapshot. Kept in exactly one place so the two can never drift
     * apart on how an amount is computed.
     *
     * CORRECTED (re-verified against CashVero's actual LC forms,
     * confirmed with Mahmoud from a screenshot of the real app —
     * both LcFacilityForm.vue and HundredPercentageCashCoverForm.vue
     * use these exact formulas):
     *
     *   amount_in_payment_currency = lc_amount × exchange_rate
     *   cash_cover_amount          = amount_in_payment_currency × cash_cover_pct / 100
     *   commission_amount          = lc_amount × commission_rate_pct / 100   (LC's OWN currency — unchanged from the first pass, this one was already right)
     *
     * The first pass wrongly computed cash_cover_amount off the raw
     * lc_amount instead of its payment-currency equivalent, and had a
     * separate user-pickable "commission & fees currency" field that
     * doesn't exist in CashVero at all — Fees & Commission always post
     * in the LC's own currency (currency_id), full stop.
     */
    private function computeDerivedAmounts(array $data, int $companyId): array
    {
        $functionalCurrencyId = $this->resolveFunctionalCurrencyId($companyId);

        // Exchange rate: 1:1 when the LC currency IS the functional
        // currency (nothing to convert); otherwise use whatever the
        // caller supplied (the Vue form auto-fetches this from
        // ExchangeRate::rateFor(), same as CashVero's own
        // fetchExchangeRateForLcCurrency(), but the user can always
        // override it — so we trust the submitted value here rather
        // than silently overwriting it server-side).
        if ((int) $data['currency_id'] === (int) $functionalCurrencyId) {
            $data['exchange_rate'] = '1';
        } else {
            $data['exchange_rate'] = (string) ($data['exchange_rate'] ?? '1');
        }

        $data['amount_in_payment_currency'] = bcmul((string) $data['lc_amount'], $data['exchange_rate'], 2);

        // Cash Cover Currency: a real, separate, user-picked field —
        // but restricted to [functional currency, LC currency] only,
        // matching CashVero's cashCoverCurrencyOptions exactly.
        $allowedCashCoverCurrencyIds = array_unique(array_filter([$functionalCurrencyId, (int) $data['currency_id']]));
        if (empty($data['cash_cover_currency_id']) || !in_array((int) $data['cash_cover_currency_id'], $allowedCashCoverCurrencyIds, true)) {
            $data['cash_cover_currency_id'] = $functionalCurrencyId;
        }

        if ($data['issuance_type'] === 'cash_cover') {
            $data['cash_cover_pct'] = 100;
            $data['fees_commission_account_id'] = $data['cash_cover_account_id'] ?? null;
        }

        if ($data['issuance_type'] === 'facility' && !empty($data['facility_lc_type_id'])) {
            $rateCard = FacilityLcType::findOrFail($data['facility_lc_type_id']);
            $data['lc_type']                   = $rateCard->lc_type;
            $data['cash_cover_pct']             = $data['cash_cover_pct'] ?? $rateCard->cash_cover_pct;
            $data['commission_rate_pct']        = $data['commission_rate_pct'] ?? $rateCard->commission_rate_pct;
            $data['min_commission_fee_amount']  = $data['min_commission_fee_amount'] ?? $rateCard->min_commission_fee_amount;
            $data['issuance_fee_amount']        = $data['issuance_fee_amount'] ?? $rateCard->issuance_fee_amount;
        }

        $data['cash_cover_amount'] = isset($data['cash_cover_pct'])
            ? bcdiv(bcmul($data['amount_in_payment_currency'], (string) $data['cash_cover_pct'], 4), '100', 2)
            : null;

        // Commission — LC's OWN currency (lc_amount, not the payment-
        // currency equivalent). Confirmed by Mahmoud: "if LC is for
        // USD 10,000 and commission is 1%, it is 100 USD" — matches
        // CashVero's own formula exactly.
        $rawCommission = bcdiv(bcmul((string) $data['lc_amount'], (string) $data['commission_rate_pct'], 4), '100', 2);
        $data['commission_amount'] = bccomp($rawCommission, (string) ($data['min_commission_fee_amount'] ?? 0), 2) < 0
            ? $data['min_commission_fee_amount']
            : $rawCommission;

        return $data;
    }

    private function resolveFunctionalCurrencyId(int $companyId): int
    {
        return (int) CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');
    }

    // ── Private: cash cover clearance (H-21) ─────────────────────────

    /**
     * Decides how the LC's cash cover is cleared when the LC is paid.
     *  - bank-financed, cover in the same currency as the bill →
     *    the cover is APPLIED against what the bank financed (the
     *    uncovered part is all that becomes bank debt); any cover
     *    above the payment is RELEASED back to the bank account.
     *  - everything else (self-financed, or different currencies) →
     *    the whole cover is RELEASED back to its bank account.
     * Returns ['total', 'applied', 'released'] as 2-decimal strings.
     */
    private function planCashCoverClearance(LetterOfCreditIssuance $issuance, string $amountToSupplier, int $billCurrencyId): array
    {
        $cover = (string) ($issuance->cash_cover_amount ?? '0.00');

        if (bccomp($cover, '0', 2) <= 0 || $issuance->cash_cover_released_at) {
            return ['total' => '0.00', 'applied' => '0.00', 'released' => '0.00'];
        }

        $coverCurrencyId = (int) ($issuance->cash_cover_currency_id ?? $issuance->currency_id);
        $canApply = $issuance->isFinancedByBank() && $coverCurrencyId === $billCurrencyId;

        $applied  = $canApply ? (bccomp($cover, $amountToSupplier, 2) > 0 ? $amountToSupplier : $cover) : '0.00';
        $released = bcsub($cover, $applied, 2);

        return ['total' => $cover, 'applied' => $applied, 'released' => $released];
    }

    /** @return LcIssuanceMovement[] */
    private function buildCashCoverClearanceMovements(LetterOfCreditIssuance $issuance, array $cover, string $date, User $actor): array
    {
        if (bccomp($cover['total'], '0', 2) <= 0) {
            return [];
        }

        $coa138002 = $this->resolveCoaAccount($issuance->company_id, '138002')
            ?? throw new \DomainException('Chart of Accounts is missing system account 138002 (LC Cash Cover) — cannot clear the cash cover.');

        $currencyId = (int) ($issuance->cash_cover_currency_id ?? $issuance->currency_id);
        $movements  = [];

        if (bccomp($cover['applied'], '0', 2) > 0) {
            $movements[] = LcIssuanceMovement::create(array_merge($this->tagMovementData($issuance), [
                'company_id'            => $issuance->company_id,
                'lc_issuance_id'        => $issuance->id,
                'movement_type'         => 'cash_cover_release',
                'movement_date'         => $date,
                'debit_coa_account_id'  => $this->resolveFacilityLiabilityAccount($issuance)->id,
                'credit_coa_account_id' => $coa138002->id,
                'amount'                => $cover['applied'],
                'currency_id'           => $currencyId,
                'description'           => "Cash cover applied against bank-financed payment — LC {$issuance->lc_code}",
                'created_by'            => $actor->id,
            ]));
        }

        if (bccomp($cover['released'], '0', 2) > 0) {
            $movements[] = $this->makeCashCoverReleaseMovement($issuance, $cover['released'], $date, $actor, "Cash cover released on payment — LC {$issuance->lc_code}");
        }

        return $movements;
    }

    /** Dr Bank account the cover came from / Cr 138002. */
    private function makeCashCoverReleaseMovement(LetterOfCreditIssuance $issuance, string $amount, string $date, User $actor, string $description): LcIssuanceMovement
    {
        if (! $issuance->cash_cover_account_id) {
            throw new \DomainException('This LC has no cash cover bank account recorded — the cover cannot be released to a bank account.');
        }

        $coa138002 = $this->resolveCoaAccount($issuance->company_id, '138002')
            ?? throw new \DomainException('Chart of Accounts is missing system account 138002 (LC Cash Cover) — cannot release the cash cover.');

        return LcIssuanceMovement::create(array_merge($this->tagMovementData($issuance), [
            'company_id'            => $issuance->company_id,
            'lc_issuance_id'        => $issuance->id,
            'movement_type'         => 'cash_cover_release',
            'movement_date'         => $date,
            'debit_bank_account_id' => $issuance->cash_cover_account_id,
            'credit_coa_account_id' => $coa138002->id,
            'amount'                => $amount,
            'currency_id'           => $issuance->cash_cover_currency_id ?? $issuance->currency_id,
            'description'           => $description,
            'created_by'            => $actor->id,
        ]));
    }

    // ── Private: cost allocation tagging ─────────────────────────────

    private function tagMovementData(LetterOfCreditIssuance $issuance): array
    {
        return ['purchase_order_id' => $issuance->purchase_order_id];
    }

    // ── Private: accounting postings ─────────────────────────────────

    private function buildIssuanceMovements(LetterOfCreditIssuance $issuance, User $actor): Collection
    {
        $coa138002 = $this->resolveCoaAccount($issuance->company_id, '138002');
        $coa554    = $this->resolveCoaAccount($issuance->company_id, '554');
        $tag = $this->tagMovementData($issuance);

        $movements = new Collection();

        if (bccomp((string) $issuance->cash_cover_amount, '0', 2) > 0) {
            $movements->push(LcIssuanceMovement::create(array_merge($tag, [
                'company_id'             => $issuance->company_id,
                'lc_issuance_id'         => $issuance->id,
                'movement_type'          => 'cash_cover_placement',
                'movement_date'          => $issuance->issuance_date,
                'debit_coa_account_id'   => $coa138002?->id,
                'credit_bank_account_id' => $issuance->cash_cover_account_id,
                'amount'                 => $issuance->cash_cover_amount,
                'currency_id'            => $issuance->cash_cover_currency_id ?? $issuance->currency_id,
                'description'            => "Cash cover placed — LC {$issuance->lc_code}",
                'created_by'             => $actor->id,
            ])));
        }

        if (bccomp((string) $issuance->issuance_fee_amount, '0', 2) > 0) {
            $movements->push(LcIssuanceMovement::create(array_merge($tag, [
                'company_id'             => $issuance->company_id,
                'lc_issuance_id'         => $issuance->id,
                'movement_type'          => 'issuance_fee',
                'movement_date'          => $issuance->issuance_date,
                'debit_coa_account_id'   => $coa554?->id,
                'credit_bank_account_id' => $issuance->fees_commission_account_id,
                'amount'                 => $issuance->issuance_fee_amount,
                'currency_id'            => $issuance->currency_id, // Fees & Commission always post in the LC's own currency (confirmed against CashVero — no separate field exists there)
                'description'            => "Issuance fee — LC {$issuance->lc_code}",
                'created_by'             => $actor->id,
            ])));
        }

        // One-time commission — confirmed different from LG's periodic accrual.
        if (bccomp((string) $issuance->commission_amount, '0', 2) > 0) {
            $movements->push(LcIssuanceMovement::create(array_merge($tag, [
                'company_id'             => $issuance->company_id,
                'lc_issuance_id'         => $issuance->id,
                'movement_type'          => 'commission',
                'movement_date'          => $issuance->issuance_date,
                'debit_coa_account_id'   => $coa554?->id,
                'credit_bank_account_id' => $issuance->fees_commission_account_id,
                'amount'                 => $issuance->commission_amount,
                'currency_id'            => $issuance->currency_id, // Fees & Commission always post in the LC's own currency
                'description'            => "LC commission (one-time) — {$issuance->lc_code}",
                'created_by'             => $actor->id,
            ])));
        }

        return $movements;
    }

    private function postMovementsAsJournalEntry(LetterOfCreditIssuance $issuance, Collection $movements, string $narration, User $actor, string $entryDate): ?JournalEntry
    {
        $postable = $movements->filter(fn (LcIssuanceMovement $m) => $m->hasPostableLegs());
        $memoOnly = $movements->reject(fn (LcIssuanceMovement $m) => $m->hasPostableLegs());

        foreach ($memoOnly as $memo) {
            if ($memo->status !== LcIssuanceMovement::STATUS_MEMO) {
                $memo->update(['status' => LcIssuanceMovement::STATUS_MEMO]);
            }
        }

        if ($postable->isEmpty()) {
            return null;
        }

        // Audit C8 FIX (Sept 2026): movement amounts are in their OWN
        // currency (LC or cash-cover currency) and used to go into the
        // EGP ledger 1:1. Now converted at the rate for the entry date;
        // on the issuance date itself the rate typed on the LC wins for
        // LC-currency amounts (it is what the user confirmed).
        $fx = app(\App\Services\Accounting\FxService::class);
        $issuanceDate = $issuance->issuance_date ? $issuance->issuance_date->format('Y-m-d') : null;
        $lines = [];
        foreach ($postable as $movement) {
            $debitAccountId  = $movement->debit_coa_account_id  ?? $this->resolveBankAccountCoaId($movement->debit_bank_account_id);
            $creditAccountId = $movement->credit_coa_account_id ?? $this->resolveBankAccountCoaId($movement->credit_bank_account_id);

            $currencyId = $movement->currency_id ? (int) $movement->currency_id : null;
            $typedRate  = ($currencyId === (int) $issuance->currency_id && substr($entryDate, 0, 10) === $issuanceDate)
                ? $issuance->exchange_rate
                : null;
            $movementRate = $fx->resolveRate($issuance->company_id, $currencyId, $entryDate, $typedRate);
            $amount = $fx->toFunctional($movement->amount, $movementRate);

            // Audit H12/H15: each line keeps the movement's exact foreign amount.
            $fxTag = $currencyId ? ['currency_id' => $currencyId, 'amount_currency' => (string) $movement->amount, 'exchange_rate' => $movementRate] : [];
            $lines[] = ['chart_of_account_id' => $debitAccountId,  'debit' => $amount, 'credit' => '0.00', 'description' => $movement->description] + $fxTag;
            $lines[] = ['chart_of_account_id' => $creditAccountId, 'debit' => '0.00', 'credit' => $amount, 'description' => $movement->description] + $fxTag;
        }

        $entry = $this->journalEntryService->post(
            companyId: $issuance->company_id,
            entryDate: $entryDate,
            lines: $lines,
            sourceType: 'lc_issuance',
            sourceId: $issuance->id,
            narration: $narration,
            actor: $actor,
        );

        LcIssuanceMovement::whereIn('id', $postable->pluck('id'))->update([
            'status'                  => LcIssuanceMovement::STATUS_POSTED,
            'posted_at'               => now(),
            'posted_journal_entry_id' => $entry->id,
        ]);

        return $entry;
    }

    private function reversePostedMovements(LetterOfCreditIssuance $issuance, User $actor, string $narration): void
    {
        $journalEntryIds = LcIssuanceMovement::where('lc_issuance_id', $issuance->id)
            ->where('status', LcIssuanceMovement::STATUS_POSTED)
            ->whereNotNull('posted_journal_entry_id')
            ->pluck('posted_journal_entry_id')
            ->unique();

        foreach ($journalEntryIds as $journalEntryId) {
            $entry = JournalEntry::find($journalEntryId);
            if ($entry && $entry->status === JournalEntry::STATUS_POSTED) {
                $this->journalEntryService->reverse($entry, $actor, $narration);
            }
        }

        LcIssuanceMovement::where('lc_issuance_id', $issuance->id)
            ->where('status', LcIssuanceMovement::STATUS_POSTED)
            ->update(['status' => LcIssuanceMovement::STATUS_REVERSED]);
    }

    /**
     * The LC Facility's own liability/asset GL account
     * (CreditFacility.coa_account_id) — used as the credit leg when
     * the bank drawsdown to pay a supplier, and the debit leg when
     * the company later repays principal. Same account referenced on
     * both sides, deliberately: it's the running balance of what's
     * owed to the bank under this facility.
     */
    private function resolveFacilityLiabilityAccount(LetterOfCreditIssuance $issuance): ChartOfAccount
    {
        $facility = $issuance->creditFacility;

        if (! $facility || ! $facility->coa_account_id) {
            throw new \DomainException('This LC\'s facility has no Chart of Accounts liability account assigned — set one on the LC Facility before financing through it.');
        }

        return ChartOfAccount::withoutGlobalScopes()->forCompany($issuance->company_id)->findOrFail($facility->coa_account_id);
    }

    /** Same resolution SupplierPaymentService::resolveApAccount() uses — kept identical so both modules can never silently disagree. */
    private function resolveApAccount(int $companyId, int $currencyId): ChartOfAccount
    {
        $functionalCurrencyId = CompanyActiveCurrency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');

        $code = ($functionalCurrencyId && (int) $currencyId === (int) $functionalCurrencyId) ? '221001' : '221002';

        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Accounts Payable account ({$code}) is not seeded for this company — cannot post.");
    }

    private function resolveBankAccountCoaId(?int $bankAccountId): int
    {
        if (! $bankAccountId) {
            throw new \DomainException('An LC movement is missing a bank account leg — cannot post to the GL.');
        }

        $bankAccount = BankAccount::find($bankAccountId);

        if (! $bankAccount || ! $bankAccount->coa_account_id) {
            throw new \DomainException('The bank account on this LC movement is missing its Chart of Accounts link.');
        }

        return $bankAccount->coa_account_id;
    }

    private function resolveCoaAccount(int $companyId, string $code): ?ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, $code);
    }
}
