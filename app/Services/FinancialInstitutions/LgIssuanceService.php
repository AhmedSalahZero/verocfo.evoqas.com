<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LgIssuanceService
//
//  Business logic for individual Letter of Guarantee issuances —
//  the layer beneath the LG Agreement (CreditFacility + rate card).
//  Covers all 3 issuance types (facility / cash_cover / time_deposit),
//  all 4 lifecycle actions (renew / decrease / cancel / liquidate),
//  and — as of the August 2026 correction-everywhere fix — a full
//  Set to Draft / Delete / Undo workflow so nothing here is ever a
//  permanent dead end except a genuinely empty draft shell.
//
//  See migration 000066 header for the full schema rationale before
//  reading this file — it explains issuance_type vs posting_mode,
//  current_amount vs lg_amount, and computed status.
//
//  ══ REAL GL POSTING (August 2026 — was staged-only before) ══════
//  Every 'new_issuance' event (issuance / renewal fee / cancel /
//  liquidate) now posts a REAL, balanced journal entry through
//  JournalEntryService::post() — not just a staged LgIssuanceMovement
//  row. LgIssuanceMovement still exists and is still created first
//  (it's the per-line record: which account, how much, why), but
//  postMovementsAsJournalEntry() now turns a batch of movements from
//  the same event into one real JournalEntry, and reverse actions
//  (Set to Draft, or an Undo on a specific lifecycle event) call
//  JournalEntryService::reverse() on it — never a second copy of
//  that logic.
//
//  A movement only becomes a real GL line if BOTH its legs resolve
//  to an account (LgIssuanceMovement::hasPostableLegs()) — a memo
//  row (Time-Deposit-collateral cash cover, an Advanced Payment LG
//  decrease note) has nothing to post and is marked 'memo_only'
//  instead of 'posted'.
//
//  ══ RECURRING COMMISSION ACCRUAL (August 2026 — new) ═════════════
//  The FIRST commission accrual happens for free at issuance
//  (postIssuanceMovements()). Every accrual after that is driven by
//  next_commission_accrual_date (migration 000142) and posted by
//  postDueCommissionAccruals(), called by the scheduled command
//  `lg:accrue-commission` (see PostDueLgCommissionAccruals). Each
//  accrual posts its own real journal entry (Dr 553 / Cr the fees
//  account) for the LG's CURRENT commission_amount (already kept
//  correct by decrease()'s recalculation), then advances
//  next_commission_accrual_date by one commission_interval.
//
//  ══ CORRECTION — Set to Draft vs. per-action Undo (August 2026) ══
//  Confirmed with Mahmoud: an ERP must never freeze a mistake in
//  permanently. Two distinct, deliberately separate mechanisms:
//
//    - setToDraft() — only while the issuance is PRISTINE (no
//      Renewal/Decrease/Cancel/Liquidate has happened, or every one
//      that did has since been undone — LgIssuance::isPristine()).
//      Reverses every real journal entry the issuance itself posted,
//      unlocks the form, and lets it be edited (update()) or, once
//      empty again, deleted (delete()) — exact same "reversible
//      draft, harmless empty shell" philosophy as
//      CustomerPaymentService.
//
//    - undoRenewal()/undoDecrease()/undoCancel()/undoLiquidate() —
//      for correcting a SPECIFIC lifecycle action without touching
//      the original issuance underneath it (which may still have a
//      perfectly valid cash cover placement sitting on the books).
//      Only the MOST RECENT non-undone action can be undone
//      (LgIssuance::undoableHistoryEntry()) — correction peels back
//      one layer at a time, in reverse order, never out of sequence.
//
//  ══ PROJECT / SO COST ALLOCATION (August 2026 — new) ═════════════
//  Confirmed with Mahmoud: this is NOT an accounting posting — Cash
//  Cover, Issuance Fee, and Commission (including renewal fees) are
//  tagged with the same Project/Sales Order the LG itself is linked
//  to (contract_reference_project_id / purchase_order_so_id), purely
//  so a future Project P&L / Project Cash Flow report can sum LG
//  costs per contract, the same way Materials/Services costs are
//  already tagged on their own lines. Tagging happens once, at the
//  moment each LgIssuanceMovement is created (tagMovementData()) —
//  a report never has to join back through lg_issuances to find the
//  contract. Bid Bond has neither field set, so its movements are
//  simply untagged — nothing to allocate to.
//
//  COA CODES USED BELOW ('138001', '553') match the documented chart
//  of accounts (LG Cash Cover / LG Fees).
//
//  @layer    Service
//  @depends  LgIssuance, LgIssuanceHistory, LgIssuanceMovement,
//            CreditFacility, FacilityLgType, Partner, Project,
//            SalesOrder, BankAccount, ChartOfAccount, JournalEntry,
//            JournalEntryService, FiscalYearService, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\FacilityLgType;
use App\Models\FinancialInstitutions\LgIssuance;
use App\Models\FinancialInstitutions\LgIssuanceHistory;
use App\Models\FinancialInstitutions\LgIssuanceMovement;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\FiscalYearService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class LgIssuanceService
{
    use \App\Services\FinancialInstitutions\Concerns\ComputesFacilityExposure;

    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
    ) {}

    // ── List (index page — one tab per LG Type) ─────────────────────

    public function paginate(int $companyId, string $lgType, array $filters = []): LengthAwarePaginator
    {
        return LgIssuance::with([
                'bank' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name_en'),
                'beneficiary:id,name_en,name_ar',
                'currency' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code'),
            ])
            ->where('lg_issuances.company_id', $companyId)
            ->where('lg_type', $lgType)
            ->when($filters['start_date'] ?? null, fn ($q, $d) => $q->where('issuance_date', '>=', $d))
            ->when($filters['end_date']   ?? null, fn ($q, $d) => $q->where('end_date', '<=', $d))
            ->orderByDesc('issuance_date')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * KPI cards for the tabbed index page — scoped to the ACTIVE tab's
     * LG Type only, since that's the working context (matches the
     * reference image's per-tab table). Same currency-safety rule as
     * every other facility index: current_amount is grouped by
     * currency, never summed across currencies.
     */
    public function stats(int $companyId, string $lgType, array $filters = []): array
    {
        $base = fn () => LgIssuance::where('lg_issuances.company_id', $companyId)
            ->where('lg_type', $lgType)
            ->when($filters['start_date'] ?? null, fn ($q, $d) => $q->where('issuance_date', '>=', $d))
            ->when($filters['end_date']   ?? null, fn ($q, $d) => $q->where('end_date', '<=', $d));

        $totalCount   = $base()->count();
        $runningCount = $base()->where('status', 'running')->count();

        $byCurrency = $base()
            ->where('status', 'running')
            ->join('currencies', 'currencies.id', '=', 'lg_issuances.currency_id')
            ->groupBy('currencies.id', 'currencies.code')
            ->selectRaw('currencies.code as currency_code, SUM(lg_issuances.current_amount) as total_outstanding')
            ->orderBy('currencies.code')
            ->get();

        $nearExpiryCount = $base()
            ->where('status', 'running')
            ->whereBetween('end_date', [now(), now()->addDays(30)])
            ->count();

        return [
            'total_count'       => $totalCount,
            'running_count'     => $runningCount,
            'by_currency'       => $byCurrency,
            'near_expiry_count' => $nearExpiryCount,
        ];
    }

    // ── Eligible lookups (for the Beneficiary / Contract / PO dropdowns) ──

    /**
     * Bid Bond → customer or other. Everything else → customer only.
     * Rule 2.5 note: Partner::ofType() already scopes to company via
     * BelongsToCompany, no withoutGlobalScopes() needed here (unlike
     * currency/COA, partners aren't a shared system record).
     */
    public function eligibleBeneficiaries(int $companyId, string $lgType)
    {
        $query = Partner::where('company_id', $companyId)->active();

        if ($lgType === 'Bid Bond') {
            $query->where(function ($q) {
                $q->whereJsonContains('types', Partner::TYPE_CUSTOMER)
                  ->orWhereJsonContains('types', Partner::TYPE_OTHER);
            });
        } else {
            $query->whereJsonContains('types', Partner::TYPE_CUSTOMER);
        }

        return $query->orderBy('name_en')->get(['id', 'name_en', 'name_ar', 'types']);
    }

    /**
     * For Final / Advanced Payment / Performance LG — the beneficiary
     * must have at least one active Project (contract) or standalone
     * Sales Order. Merged into ONE list (confirmed with Mahmoud —
     * "Contracts / Sales Orders" is a single field, not two):
     *   - Active Projects (contracts) for this partner
     *   - Active Sales Orders for this partner WITH NO parent Project
     *     (SalesOrder::scopeStandalone()) — SOs that already belong
     *     to a Project are represented by that Project instead, not
     *     listed again separately.
     *
     * BUG FIX: Project's customer column is `partner_id`, not
     * `customer_id` — this silently returned nothing before.
     */
    public function eligibleContracts(int $companyId, int $partnerId): array
    {
        $projects = \App\Models\Project::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->active()
            ->get(['id', 'name_en', 'contract_code'])
            ->map(fn ($p) => ['id' => $p->id, 'kind' => 'project', 'label' => "{$p->contract_code} — {$p->name_en}"]);

        $salesOrders = \App\Models\SalesOrder::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->standalone()
            ->active()
            ->get(['id', 'so_code', 'subject'])
            ->map(fn ($so) => ['id' => $so->id, 'kind' => 'sales_order', 'label' => $so->subject ? "{$so->so_code} — {$so->subject}" : $so->so_code]);

        return $projects->concat($salesOrders)->values()->all();
    }

    /**
     * Live room/outstanding figures for the "LG Facility" section of
     * the Facility-type form — confirmed these must be real numbers,
     * not placeholders. Room = facility limit minus every RUNNING
     * issuance's current_amount under that facility. Per-type
     * outstanding narrows the same sum to one facility_lg_type_id.
     */
    public function facilityRoomSummary(int $companyId, int $creditFacilityId): array
    {
        $facility = CreditFacility::where('company_id', $companyId)->findOrFail($creditFacilityId);

        // M-30: convert to the facility currency and net off unreleased cash cover.
        $running = LgIssuance::where('company_id', $companyId)
            ->where('credit_facility_id', $creditFacilityId)
            ->where('status', 'running')
            ->get();
        $exp = $this->facilityExposure($companyId, $facility, $running, 'current_amount', 'facility_lg_type_id');

        return [
            'facility_limit'    => $facility->facility_limit,
            'outstanding_total' => $exp['net'],
            'gross_outstanding' => $exp['gross'],
            'cash_cover_held'   => $exp['cash_cover'],
            'available_room'    => bcsub((string) $facility->facility_limit, $exp['net'], 2),
            'outstanding_by_lg_type_id' => $exp['by_type'],
        ];
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): LgIssuance
    {
        if ($this->fiscalYearService->isPeriodClosed($companyId, (string) $data['issuance_date'])) {
            throw new \DomainException('This accounting period is closed — the LG cannot be issued.');
        }

        return DB::transaction(function () use ($companyId, $data, $actor) {

            $this->validateBeneficiary($companyId, $data);
            $this->assertAccountsMatchBank($data);

            $data['company_id']      = $companyId;
            $data['created_by']      = $actor->id;
            $data['status']          = 'running';
            $data['posting_status']  = LgIssuance::POSTING_STATUS_POSTED;
            $data['current_amount']  = $data['lg_amount'];
            $data['duration_months'] = $this->monthsBetween($data['issuance_date'], $data['end_date']);

            $data = $this->computeDerivedAmounts($data);

            $issuance = LgIssuance::create($data);

            if ($issuance->posting_mode === 'new_issuance') {
                $movements = $this->buildIssuanceMovements($issuance, $actor);
                $this->postMovementsAsJournalEntry(
                    $issuance,
                    $movements,
                    "LG Issuance — {$issuance->lg_code}",
                    $actor,
                    (string) $issuance->issuance_date
                );

                $issuance->update([
                    'next_commission_accrual_date' => $this->nextIntervalDate(
                        Carbon::parse($issuance->issuance_date),
                        $issuance->commission_interval
                    ),
                ]);
            }

            AuditLog::record($actor, 'lg_issuance.created', $issuance, null, $issuance->toArray());

            return $issuance->fresh(['bank', 'currency', 'beneficiary']);
        });
    }

    /**
     * Re-saves a REOPENED draft (the only way an issuance reaches
     * posting_status = draft is setToDraft(), which has already
     * reversed everything real it posted). Recomputes every derived
     * amount from scratch — same logic as create() — then re-posts a
     * brand new journal entry and movement set. Only reachable while
     * isPristine() still holds (guarded again here, defensively).
     */
    public function update(LgIssuance $issuance, array $data, User $actor): LgIssuance
    {
        if (! $issuance->isDraft()) {
            throw new \DomainException('Only a draft LG issuance can be edited — use "Set to Draft" first.');
        }
        if (! $issuance->isPristine()) {
            throw new \DomainException('This issuance has lifecycle history attached — it should not be possible to reach draft in this state.');
        }
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $data['issuance_date'])) {
            throw new \DomainException('This accounting period is closed — the LG cannot be re-posted.');
        }

        return DB::transaction(function () use ($issuance, $data, $actor) {
            $this->validateBeneficiary($issuance->company_id, $data);
            $this->assertAccountsMatchBank($data);

            $data['status']          = 'running';
            $data['posting_status']  = LgIssuance::POSTING_STATUS_POSTED;
            $data['current_amount']  = $data['lg_amount'];
            $data['duration_months'] = $this->monthsBetween($data['issuance_date'], $data['end_date']);

            $data = $this->computeDerivedAmounts($data);

            // Clear out whatever the previous draft attempt left behind
            // (memo/reversed rows from before) — a clean slate for this
            // re-save, same principle as CustomerPaymentService::update()
            // re-posting a brand new journal entry rather than patching
            // the old one.
            $issuance->movements()->delete();

            $issuance->update($data);

            if ($issuance->posting_mode === 'new_issuance') {
                $movements = $this->buildIssuanceMovements($issuance->fresh(), $actor);
                $this->postMovementsAsJournalEntry(
                    $issuance,
                    $movements,
                    "LG Issuance (re-posted) — {$issuance->lg_code}",
                    $actor,
                    (string) $issuance->issuance_date
                );

                $issuance->update([
                    'next_commission_accrual_date' => $this->nextIntervalDate(
                        Carbon::parse($issuance->issuance_date),
                        $issuance->commission_interval
                    ),
                ]);
            }

            AuditLog::record($actor, 'lg_issuance.updated', $issuance, null, $issuance->fresh()->toArray());

            return $issuance->fresh(['bank', 'currency', 'beneficiary']);
        });
    }

    // ── Set to Draft / Delete ────────────────────────────────────────

    /**
     * Reopens a posted, PRISTINE LG issuance back to draft — the ONLY
     * way it becomes editable again. Reverses every real journal
     * entry it posted (issuance + any recurring commission accruals
     * that have fired since — those aren't "history" and don't block
     * this, they're just swept along with everything else), then
     * resets it to a clean draft for update() to re-post.
     *
     * Blocked if:
     *   - it's already draft
     *   - it isn't pristine (a Renewal/Decrease/Cancel/Liquidate is
     *     active — correct that specific action's own Undo instead,
     *     never by reopening the whole issuance underneath it)
     *   - its accounting period is closed
     */
    public function setToDraft(LgIssuance $issuance, User $actor): LgIssuance
    {
        if (! $issuance->isPosted()) {
            throw new \DomainException('Only a posted LG issuance can be set back to draft.');
        }
        if (! $issuance->isPristine()) {
            throw new \DomainException('This LG has lifecycle history (renewal, decrease, cancellation, or liquidation) attached — undo that action first, from the History table, before reopening the original issuance.');
        }
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $issuance->issuance_date)) {
            throw new \DomainException('This LG\'s accounting period is closed — it cannot be reopened.');
        }

        return DB::transaction(function () use ($issuance, $actor) {
            $before = $issuance->toArray();

            $this->reversePostedMovements($issuance, $actor, "Reopened to draft — LG {$issuance->lg_code}");

            $issuance->update([
                'posting_status'                => LgIssuance::POSTING_STATUS_DRAFT,
                'next_commission_accrual_date'  => null,
                'updated_at'                    => now(),
            ]);

            AuditLog::record($actor, 'lg_issuance.set_to_draft', $issuance, $before, $issuance->fresh()->toArray());

            return $issuance->fresh();
        });
    }

    /**
     * Deletes an LG issuance outright — the ONLY way this is allowed
     * is on a draft (never a posted one), and the only way an
     * issuance becomes a draft is via setToDraft(), which has already
     * fully reversed everything real it did. By the time an issuance
     * reaches this point it's a harmless empty shell with zero
     * financial residue — same "no hard deletes on financial data,
     * because this one no longer represents real money" philosophy
     * as CustomerPaymentService::delete().
     */
    public function delete(LgIssuance $issuance, User $actor): void
    {
        if (! $issuance->isDraft()) {
            throw new \DomainException('Only a draft LG issuance can be deleted — use "Set to Draft" first.');
        }
        if ($issuance->movements()->where('status', LgIssuanceMovement::STATUS_POSTED)->exists()) {
            throw new \DomainException('This LG issuance still has posted movements attached — it cannot be deleted.');
        }
        if (! $issuance->isPristine()) {
            throw new \DomainException('This LG issuance still has lifecycle history attached — it cannot be deleted.');
        }

        DB::transaction(function () use ($issuance, $actor) {
            $before = $issuance->toArray();
            $code   = $issuance->lg_code;

            $issuance->movements()->delete();
            $issuance->history()->delete();
            $issuance->delete();

            AuditLog::record($actor, 'lg_issuance.deleted', $issuance, $before, null);
        });
    }

    // ── Renew ─────────────────────────────────────────────────────

    public function renew(LgIssuance $issuance, array $data, User $actor): LgIssuance
    {
        // Fix: this used to fall back to now() because nothing on the
        // form ever populated 'renewal_date' — a renewal processed
        // today for an LG that expired weeks ago would silently post
        // as if it happened today. transaction_date is now a required
        // field the user sets explicitly (see RenewLgIssuanceRequest).
        $eventDate = $data['transaction_date'] ?? now()->toDateString();
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $eventDate)) {
            throw new \DomainException('This accounting period is closed — the renewal cannot be posted.');
        }

        return DB::transaction(function () use ($issuance, $data, $actor, $eventDate) {

            $oldEndDate = $issuance->end_date;

            // ── Cash Cover rate change (optional) ────────────────────
            // Only the INCREMENTAL delta between old and new cash cover
            // amount is ever journaled — the original placement already
            // sits in 138001. Base is current_amount (the live
            // outstanding exposure), same base Decrease already uses
            // for commission, so an Advanced Payment LG that has been
            // partially delivered still gets a correct recomputation.
            $oldCashCoverPct    = $issuance->cash_cover_pct;
            $oldCashCoverAmount = $issuance->cash_cover_amount;
            $newCashCoverPct    = null;
            $newCashCoverAmount = null;
            $cashCoverDelta     = '0.00';

            $requestedCashCoverPct = $data['cash_cover_rate_pct'] ?? null;
            if ($requestedCashCoverPct !== null && $oldCashCoverPct !== null
                && bccomp((string) $requestedCashCoverPct, (string) $oldCashCoverPct, 2) !== 0) {
                $newCashCoverPct    = (string) $requestedCashCoverPct;
                $newCashCoverAmount = bcdiv(bcmul((string) $issuance->current_amount, $newCashCoverPct, 4), '100', 2);
                $cashCoverDelta     = bcsub($newCashCoverAmount, (string) $oldCashCoverAmount, 2);
            }

            // ── Commission rate change (optional) ────────────────────
            // Takes effect from this renewal date FORWARD ONLY. Nothing
            // before the renewal date is recalculated — every future
            // accrual simply reads commission_amount fresh at the
            // moment it posts (see postDueAccrualsForIssuance()), so
            // updating it here is sufficient on its own.
            $oldCommissionRate   = $issuance->commission_rate_pct;
            $oldCommissionAmount = $issuance->commission_amount;
            $newCommissionRate   = null;
            $newCommissionAmount = null;

            $requestedCommissionRate = $data['commission_rate_pct'] ?? null;
            if ($requestedCommissionRate !== null
                && bccomp((string) $requestedCommissionRate, (string) $oldCommissionRate, 4) !== 0) {
                $newCommissionRate = (string) $requestedCommissionRate;
                $rawCommission     = bcdiv(bcmul((string) $issuance->current_amount, $newCommissionRate, 4), '100', 2);
                $newCommissionAmount = bccomp($rawCommission, (string) $issuance->min_commission_fee_amount, 2) < 0
                    ? $issuance->min_commission_fee_amount
                    : $rawCommission;
            }

            $history = LgIssuanceHistory::create([
                'company_id'              => $issuance->company_id,
                'lg_issuance_id'          => $issuance->id,
                'event_type'              => 'renewal',
                'event_date'              => $eventDate,
                'old_end_date'            => $oldEndDate,
                'new_end_date'            => $data['new_end_date'],
                'renewal_fee_amount'      => $data['renewal_fee_amount'] ?? 0,
                'old_cash_cover_pct'      => $oldCashCoverPct,
                'new_cash_cover_pct'      => $newCashCoverPct,
                'old_cash_cover_amount'   => $oldCashCoverAmount,
                'new_cash_cover_amount'   => $newCashCoverAmount,
                'cash_cover_delta_amount' => $cashCoverDelta,
                'old_commission_rate_pct' => $oldCommissionRate,
                'new_commission_rate_pct' => $newCommissionRate,
                'old_commission_amount'   => $oldCommissionAmount,
                'new_commission_amount'   => $newCommissionAmount,
                'notes'                   => $data['notes'] ?? null,
                'created_by'              => $actor->id,
            ]);

            $updateData = [
                'end_date'         => $data['new_end_date'],
                'renewed_at'       => $eventDate,
                'duration_months'  => $this->monthsBetween($issuance->issuance_date, $data['new_end_date']),
                'status'           => 'running',
            ];

            if ($newCashCoverPct !== null) {
                $updateData['cash_cover_pct']    = $newCashCoverPct;
                $updateData['cash_cover_amount'] = $newCashCoverAmount;
            }

            if ($newCommissionRate !== null) {
                $updateData['commission_rate_pct'] = $newCommissionRate;
                $updateData['commission_amount']   = $newCommissionAmount;
            }

            $issuance->update($updateData);

            $movements = new Collection();

            if ($issuance->posting_mode === 'new_issuance' && !empty($data['renewal_fee_amount'])) {
                $movements->push($this->postSimpleFeeMovement($issuance, 'renewal_fee', (string) $data['renewal_fee_amount'], $actor, $eventDate));
            }

            if ($issuance->posting_mode === 'new_issuance' && bccomp($cashCoverDelta, '0', 2) !== 0) {
                $movements->push($this->postCashCoverRenewalAdjustment($issuance, $cashCoverDelta, $actor, $eventDate));
            }

            if ($movements->isNotEmpty()) {
                $entry = $this->postMovementsAsJournalEntry(
                    $issuance,
                    $movements,
                    "LG Renewal — {$issuance->lg_code}",
                    $actor,
                    $eventDate
                );
                if ($entry) {
                    $history->update(['journal_entry_id' => $entry->id]);
                }
            }

            AuditLog::record($actor, 'lg_issuance.renewed', $issuance, ['end_date' => $oldEndDate], ['end_date' => $data['new_end_date']]);

            return $issuance->fresh();
        });
    }

    /**
     * Reverses the most recent Renewal — only reachable when it IS
     * the most recent non-undone lifecycle event (LgIssuance::
     * undoableHistoryEntry()). Restores the previous end date and
     * reverses the renewal fee's journal entry, if one was posted.
     */
    public function undoRenewal(LgIssuance $issuance, User $actor): LgIssuance
    {
        $entry = $this->assertUndoable($issuance, 'renewal');

        return DB::transaction(function () use ($issuance, $entry, $actor) {
            if ($entry->journal_entry_id) {
                $je = JournalEntry::findOrFail($entry->journal_entry_id);
                $this->journalEntryService->reverse($je, $actor, "Undo renewal — LG {$issuance->lg_code}");
                LgIssuanceMovement::where('posted_journal_entry_id', $je->id)->update(['status' => LgIssuanceMovement::STATUS_REVERSED]);
            }

            $stillRenewed = LgIssuanceHistory::where('lg_issuance_id', $issuance->id)
                ->where('event_type', 'renewal')
                ->whereNull('undone_at')
                ->where('id', '!=', $entry->id)
                ->exists();

            $restoreData = [
                'end_date'        => $entry->old_end_date,
                'duration_months' => $this->monthsBetween($issuance->issuance_date, $entry->old_end_date),
                'renewed_at'      => $stillRenewed ? $issuance->renewed_at : null,
                'status'          => 'running',
            ];

            // Only restore cash cover / commission if THIS renewal is
            // the one that actually changed them (new_* is null when a
            // given renewal left that rate untouched).
            if ($entry->new_cash_cover_pct !== null) {
                $restoreData['cash_cover_pct']    = $entry->old_cash_cover_pct;
                $restoreData['cash_cover_amount'] = $entry->old_cash_cover_amount;
            }

            if ($entry->new_commission_rate_pct !== null) {
                $restoreData['commission_rate_pct'] = $entry->old_commission_rate_pct;
                $restoreData['commission_amount']   = $entry->old_commission_amount;
            }

            $issuance->update($restoreData);

            $entry->update(['undone_at' => now(), 'undone_by' => $actor->id]);

            AuditLog::record($actor, 'lg_issuance.renewal_undone', $issuance, ['end_date' => $issuance->getOriginal('end_date')], ['end_date' => $entry->old_end_date]);

            return $issuance->fresh();
        });
    }

    // ── Decrease (Advanced Payment LG only) ─────────────────────────

    public function decrease(LgIssuance $issuance, array $data, User $actor): LgIssuance
    {
        if (!$issuance->isAdvancedPaymentLg()) {
            throw new \DomainException('Only Advanced Payment LG issuances can be decreased.');
        }

        $decreaseAmount = (string) $data['decrease_amount'];

        if (bccomp($decreaseAmount, (string) $issuance->current_amount, 2) > 0) {
            throw new \DomainException('Decrease amount cannot exceed the current outstanding LG amount.');
        }

        $eventDate = $data['event_date'] ?? now()->toDateString();
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $eventDate)) {
            throw new \DomainException('This accounting period is closed — the decrease cannot be posted.');
        }

        return DB::transaction(function () use ($issuance, $data, $actor, $decreaseAmount, $eventDate) {

            $oldBalance = (string) $issuance->current_amount;
            $newBalance = bcsub($oldBalance, $decreaseAmount, 2);

            // Commission going forward recalculates off the new,
            // lower balance — confirmed business rule.
            $rawCommission = bcdiv(bcmul($newBalance, (string) $issuance->commission_rate_pct, 4), '100', 2);
            $newCommission = bccomp($rawCommission, (string) $issuance->min_commission_fee_amount, 2) < 0
                ? $issuance->min_commission_fee_amount
                : $rawCommission;

            LgIssuanceHistory::create([
                'company_id'           => $issuance->company_id,
                'lg_issuance_id'       => $issuance->id,
                'event_type'           => 'decrease',
                'event_date'           => $eventDate,
                'decrease_amount'      => $decreaseAmount,
                'balance_after_event'  => $newBalance,
                'old_current_amount'   => $oldBalance,
                'notes'                => $data['notes'] ?? null,
                'created_by'           => $actor->id,
            ]);

            $issuance->update([
                'current_amount'    => $newBalance,
                'commission_amount' => $newCommission,
            ]);

            if ($issuance->posting_mode === 'new_issuance') {
                // Memo-only — no real cash movement for a decrease
                // (see lg_issuance_movements header), so this is
                // created directly as memo_only, never posted to the GL.
                LgIssuanceMovement::create(array_merge($this->tagMovementData($issuance), [
                    'company_id'     => $issuance->company_id,
                    'lg_issuance_id' => $issuance->id,
                    'movement_type'  => 'decrease_adjustment',
                    'movement_date'  => $eventDate,
                    'amount'         => $decreaseAmount,
                    'currency_id'    => $issuance->currency_id,
                    'description'    => "Advanced Payment LG decreased by {$decreaseAmount} — partial delivery",
                    'status'         => LgIssuanceMovement::STATUS_MEMO,
                    'created_by'     => $actor->id,
                ]));
            }

            AuditLog::record($actor, 'lg_issuance.decreased', $issuance, ['current_amount' => $oldBalance], ['current_amount' => $newBalance]);

            return $issuance->fresh();
        });
    }

    /**
     * Reverses the most recent Decrease. Memo-only in nature (no real
     * journal entry was ever posted for a decrease), so this just
     * restores current_amount/commission_amount to their exact
     * pre-decrease values.
     */
    public function undoDecrease(LgIssuance $issuance, User $actor): LgIssuance
    {
        $entry = $this->assertUndoable($issuance, 'decrease');

        return DB::transaction(function () use ($issuance, $entry, $actor) {
            $restoredBalance = (string) $entry->old_current_amount;

            $rawCommission = bcdiv(bcmul($restoredBalance, (string) $issuance->commission_rate_pct, 4), '100', 2);
            $restoredCommission = bccomp($rawCommission, (string) $issuance->min_commission_fee_amount, 2) < 0
                ? $issuance->min_commission_fee_amount
                : $rawCommission;

            $issuance->update([
                'current_amount'    => $restoredBalance,
                'commission_amount' => $restoredCommission,
            ]);

            $entry->update(['undone_at' => now(), 'undone_by' => $actor->id]);

            AuditLog::record($actor, 'lg_issuance.decrease_undone', $issuance, ['current_amount' => $entry->balance_after_event], ['current_amount' => $restoredBalance]);

            return $issuance->fresh();
        });
    }

    // ── Cancel ────────────────────────────────────────────────────

    /**
     * Company retrieved the LG from the beneficiary and returned it
     * to the bank. Cash cover (if any) is debited BACK to the same
     * current account it was originally deducted from — confirmed.
     * Time Deposit collateral is simply unblocked (no cash movement).
     */
    public function cancel(LgIssuance $issuance, array $data, User $actor): LgIssuance
    {
        $eventDate = $data['cancelled_at'] ?? now()->toDateString();
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $eventDate)) {
            throw new \DomainException('This accounting period is closed — the cancellation cannot be posted.');
        }

        return DB::transaction(function () use ($issuance, $data, $actor, $eventDate) {

            $history = LgIssuanceHistory::create([
                'company_id'     => $issuance->company_id,
                'lg_issuance_id' => $issuance->id,
                'event_type'     => 'cancel',
                'event_date'     => $eventDate,
                'notes'          => $data['notes'] ?? null,
                'created_by'     => $actor->id,
            ]);

            $issuance->update([
                'status'                        => 'cancelled',
                'cancelled_at'                  => $eventDate,
                'cancelled_reversal_account_id' => $data['cancelled_reversal_account_id'] ?? $issuance->cash_cover_account_id,
                'next_commission_accrual_date'  => null,
            ]);

            if ($issuance->posting_mode === 'new_issuance' && $issuance->cash_cover_amount > 0) {
                $movement = LgIssuanceMovement::create(array_merge($this->tagMovementData($issuance), [
                    'company_id'              => $issuance->company_id,
                    'lg_issuance_id'          => $issuance->id,
                    'movement_type'           => 'cash_cover_release_cancel',
                    'movement_date'           => $eventDate,
                    'debit_bank_account_id'   => $data['cancelled_reversal_account_id'] ?? $issuance->cash_cover_account_id,
                    'credit_coa_account_id'   => $this->resolveCoaAccount($issuance->company_id, '138001')?->id,
                    'amount'                  => $issuance->cash_cover_amount,
                    'currency_id'             => $issuance->cash_cover_currency_id ?? $issuance->currency_id,
                    'description'             => "Cash cover released — LG {$issuance->lg_code} cancelled",
                    'created_by'              => $actor->id,
                ]));

                $entry = $this->postMovementsAsJournalEntry(
                    $issuance,
                    new Collection([$movement]),
                    "LG Cancelled — Cash Cover Released — {$issuance->lg_code}",
                    $actor,
                    $eventDate
                );
                if ($entry) {
                    $history->update(['journal_entry_id' => $entry->id]);
                }
            }

            AuditLog::record($actor, 'lg_issuance.cancelled', $issuance, ['status' => 'running'], ['status' => 'cancelled']);

            return $issuance->fresh();
        });
    }

    /**
     * Reverses the most recent Cancel — reverses the cash cover
     * release's journal entry (if one was posted) and brings the LG
     * back to Running, with commission accrual resuming from here.
     */
    public function undoCancel(LgIssuance $issuance, User $actor): LgIssuance
    {
        $entry = $this->assertUndoable($issuance, 'cancel');

        return DB::transaction(function () use ($issuance, $entry, $actor) {
            if ($entry->journal_entry_id) {
                $je = JournalEntry::findOrFail($entry->journal_entry_id);
                $this->journalEntryService->reverse($je, $actor, "Undo cancellation — LG {$issuance->lg_code}");
                LgIssuanceMovement::where('posted_journal_entry_id', $je->id)->update(['status' => LgIssuanceMovement::STATUS_REVERSED]);
            }

            $issuance->update([
                'status'                        => 'running',
                'cancelled_at'                  => null,
                'cancelled_reversal_account_id' => null,
                'next_commission_accrual_date'  => $this->nextIntervalDate(
                    Carbon::parse($issuance->last_commission_accrual_date ?? $issuance->issuance_date),
                    $issuance->commission_interval
                ),
            ]);

            $entry->update(['undone_at' => now(), 'undone_by' => $actor->id]);

            AuditLog::record($actor, 'lg_issuance.cancel_undone', $issuance, ['status' => 'cancelled'], ['status' => 'running']);

            return $issuance->fresh();
        });
    }

    // ── Liquidate ─────────────────────────────────────────────────

    /**
     * Beneficiary made a claim. Bank consumes the cash cover held
     * against THIS LG first; any amount beyond that (shortfall) is
     * deducted from an account the user specifies at this moment.
     * Requires its own liquidation date — confirmed.
     */
    public function liquidate(LgIssuance $issuance, array $data, User $actor): LgIssuance
    {
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $data['liquidated_at'])) {
            throw new \DomainException('This accounting period is closed — the liquidation cannot be posted.');
        }

        return DB::transaction(function () use ($issuance, $data, $actor) {

            $cashCoverHeld = (string) ($issuance->cash_cover_amount ?? 0);
            $claimAmount   = (string) ($data['claim_amount'] ?? $issuance->current_amount);
            $shortfall     = bccomp($claimAmount, $cashCoverHeld, 2) > 0
                ? bcsub($claimAmount, $cashCoverHeld, 2)
                : '0.00';

            $history = LgIssuanceHistory::create([
                'company_id'            => $issuance->company_id,
                'lg_issuance_id'        => $issuance->id,
                'event_type'            => 'liquidate',
                'event_date'            => $data['liquidated_at'],
                'shortfall_amount'      => $shortfall,
                'shortfall_account_id'  => bccomp($shortfall, '0', 2) > 0 ? ($data['liquidation_shortfall_account_id'] ?? null) : null,
                'notes'                 => $data['notes'] ?? null,
                'created_by'            => $actor->id,
            ]);

            $issuance->update([
                'status'                            => 'cancelled', // liquidated LG is closed — reuses 'cancelled' rather than adding a 5th status; distinguishable via liquidated_at being set
                'liquidated_at'                      => $data['liquidated_at'],
                'liquidation_shortfall_amount'        => $shortfall,
                'liquidation_expense_account_id'      => $data['liquidation_expense_account_id'],
                'liquidation_shortfall_account_id'    => bccomp($shortfall, '0', 2) > 0 ? ($data['liquidation_shortfall_account_id'] ?? null) : null,
                'next_commission_accrual_date'        => null,
            ]);

            // Fix (July 2026 audit): the Dr side of a liquidation is a
            // real loss, not a reversal of a contingent liability that
            // was never journaled in the first place. Both legs below
            // debit the same expense/loss account the user picks on
            // the liquidation form — one claim, funded from two
            // sources (cash cover first, then the shortfall). Both
            // legs post together as ONE journal entry — it's one claim
            // event, not two separate postings.
            if ($issuance->posting_mode === 'new_issuance') {
                $movements = new Collection();

                if (bccomp($cashCoverHeld, '0', 2) > 0) {
                    $movements->push(LgIssuanceMovement::create(array_merge($this->tagMovementData($issuance), [
                        'company_id'            => $issuance->company_id,
                        'lg_issuance_id'        => $issuance->id,
                        'movement_type'         => 'cash_cover_release_liquidate',
                        'movement_date'         => $data['liquidated_at'],
                        'debit_coa_account_id'  => $data['liquidation_expense_account_id'],
                        'credit_coa_account_id' => $this->resolveCoaAccount($issuance->company_id, '138001')?->id,
                        'amount'                => $cashCoverHeld,
                        'currency_id'           => $issuance->cash_cover_currency_id ?? $issuance->currency_id,
                        'description'           => "Cash cover consumed on claim — LG {$issuance->lg_code} liquidated",
                        'created_by'            => $actor->id,
                    ])));
                }

                if (bccomp($shortfall, '0', 2) > 0) {
                    $movements->push(LgIssuanceMovement::create(array_merge($this->tagMovementData($issuance), [
                        'company_id'             => $issuance->company_id,
                        'lg_issuance_id'         => $issuance->id,
                        'movement_type'          => 'liquidation_shortfall',
                        'movement_date'          => $data['liquidated_at'],
                        'debit_coa_account_id'   => $data['liquidation_expense_account_id'],
                        'credit_bank_account_id' => $data['liquidation_shortfall_account_id'] ?? null,
                        'amount'                 => $shortfall,
                        'currency_id'            => $issuance->currency_id,
                        'description'            => "Claim exceeded cash cover — shortfall on LG {$issuance->lg_code}",
                        'created_by'             => $actor->id,
                    ])));
                }

                if ($movements->isNotEmpty()) {
                    $entry = $this->postMovementsAsJournalEntry(
                        $issuance,
                        $movements,
                        "LG Liquidated — {$issuance->lg_code}",
                        $actor,
                        (string) $data['liquidated_at']
                    );
                    if ($entry) {
                        $history->update(['journal_entry_id' => $entry->id]);
                    }
                }
            }

            AuditLog::record($actor, 'lg_issuance.liquidated', $issuance, ['status' => 'running'], ['status' => 'cancelled', 'shortfall' => $shortfall]);

            return $issuance->fresh();
        });
    }

    /**
     * Reverses the most recent Liquidation — reverses the claim's
     * journal entry (if one was posted) and brings the LG back to
     * Running, with its original current_amount untouched (liquidate
     * never changed it) and commission accrual resuming.
     */
    public function undoLiquidate(LgIssuance $issuance, User $actor): LgIssuance
    {
        $entry = $this->assertUndoable($issuance, 'liquidate');

        return DB::transaction(function () use ($issuance, $entry, $actor) {
            if ($entry->journal_entry_id) {
                $je = JournalEntry::findOrFail($entry->journal_entry_id);
                $this->journalEntryService->reverse($je, $actor, "Undo liquidation — LG {$issuance->lg_code}");
                LgIssuanceMovement::where('posted_journal_entry_id', $je->id)->update(['status' => LgIssuanceMovement::STATUS_REVERSED]);
            }

            $issuance->update([
                'status'                            => 'running',
                'liquidated_at'                      => null,
                'liquidation_shortfall_amount'        => null,
                'liquidation_expense_account_id'      => null,
                'liquidation_shortfall_account_id'    => null,
                'next_commission_accrual_date'        => $this->nextIntervalDate(
                    Carbon::parse($issuance->last_commission_accrual_date ?? $issuance->issuance_date),
                    $issuance->commission_interval
                ),
            ]);

            $entry->update(['undone_at' => now(), 'undone_by' => $actor->id]);

            AuditLog::record($actor, 'lg_issuance.liquidate_undone', $issuance, ['status' => 'cancelled'], ['status' => 'running']);

            return $issuance->fresh();
        });
    }

    // ── Recurring commission accrual (scheduled) ─────────────────────

    /**
     * Posts every commission accrual that's come due since it last
     * ran. Called daily by the `lg:accrue-commission` scheduled
     * command (see App\Console\Commands\PostDueLgCommissionAccruals).
     * $companyId = null means "every company" (the normal scheduled
     * run); a specific id is for manual/testing use.
     *
     * Catch-up safe: if the job didn't run for a while, each issuance
     * posts one accrual per elapsed interval (capped at 24 — two
     * years' worth even on a monthly interval — as a runaway guard,
     * not an expected case) rather than one lump sum, so the GL trail
     * stays one real entry per real period.
     */
    public function postDueCommissionAccruals(?int $companyId = null): int
    {
        $posted = 0;

        LgIssuance::withoutGlobalScopes()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where('posting_mode', 'new_issuance')
            ->where('posting_status', LgIssuance::POSTING_STATUS_POSTED)
            ->where('status', 'running')
            ->whereNotNull('next_commission_accrual_date')
            ->where('next_commission_accrual_date', '<=', now()->toDateString())
            ->chunkById(100, function ($issuances) use (&$posted) {
                foreach ($issuances as $issuance) {
                    $posted += $this->postDueAccrualsForIssuance($issuance);
                }
            });

        return $posted;
    }

    private function postDueAccrualsForIssuance(LgIssuance $issuance): int
    {
        // Audit H9: the fallback used to be User::first() — potentially a
        // user of ANOTHER company. Now: the LG's creator, else an active
        // admin of the SAME company; nothing is posted if neither exists.
        // Audit M26: the creator must still be ACTIVE — a user who has left
        // the company no longer stands behind postings made years later.
        $systemActor = User::withoutGlobalScopes()->where('id', $issuance->created_by)->where('company_id', $issuance->company_id)->where('is_active', true)->first()
            ?? User::withoutGlobalScopes()->where('company_id', $issuance->company_id)
                ->where('role', User::ROLE_COMPANY_ADMIN)->where('is_active', true)->orderBy('id')->first();
        if (! $systemActor) {
            return 0;
        }

        $posted = 0;
        $guard  = 0;

        while (
            $issuance->next_commission_accrual_date
            && Carbon::parse($issuance->next_commission_accrual_date)->lte(now())
            && $guard < 24
        ) {
            $guard++;

            // Audit M26 — commission is only earned while the guarantee is
            // alive. An accrual date after the LG's end date is never posted;
            // the schedule is switched off instead, so the job stops looking
            // at this LG.
            $nextDate = Carbon::parse($issuance->next_commission_accrual_date)->toDateString();
            if ($issuance->end_date && $nextDate > Carbon::parse($issuance->end_date)->toDateString()) {
                $issuance->update(['next_commission_accrual_date' => null]);
                $issuance->refresh();
                break;
            }

            DB::transaction(function () use ($issuance, $systemActor) {
                $accrualDate = Carbon::parse($issuance->next_commission_accrual_date)->toDateString();

                $movement = LgIssuanceMovement::create(array_merge($this->tagMovementData($issuance), [
                    'company_id'             => $issuance->company_id,
                    'lg_issuance_id'         => $issuance->id,
                    'movement_type'          => 'commission_accrual',
                    'movement_date'          => $accrualDate,
                    'debit_coa_account_id'   => $this->resolveCoaAccount($issuance->company_id, '553')?->id,
                    'credit_bank_account_id' => $issuance->fees_commission_account_id,
                    'amount'                 => $issuance->commission_amount,
                    'currency_id'            => $issuance->commission_fees_currency_id ?? $issuance->currency_id,
                    'description'            => "Recurring commission accrual — LG {$issuance->lg_code}",
                    'created_by'             => $systemActor->id,
                ]));

                $this->postMovementsAsJournalEntry(
                    $issuance,
                    new Collection([$movement]),
                    "LG Commission Accrual — {$issuance->lg_code}",
                    $systemActor,
                    $accrualDate
                );

                $issuance->update([
                    'last_commission_accrual_date' => $accrualDate,
                    'next_commission_accrual_date' => $this->nextIntervalDate(Carbon::parse($accrualDate), $issuance->commission_interval),
                ]);
            });

            $issuance->refresh();
            $posted++;
        }

        return $posted;
    }

    // ── Private: validation ──────────────────────────────────────

    /**
     * The bank accounts chosen on the form must belong to the LG's own bank and, where the
     * form states a currency for them, be in that currency. The form only offers matching
     * accounts, but nothing else checked it — a stale or hand-made request could post the
     * LG's cash cover or fees to another bank's (or another currency's) account.
     */
    private function assertAccountsMatchBank(array $data): void
    {
        $checks = [
            'cash_cover_account_id'      => ['Cash cover account', $data['cash_cover_currency_id'] ?? null],
            'fees_commission_account_id' => ['Commission account', $data['commission_fees_currency_id'] ?? null],
            'time_deposit_account_id'    => ['Time deposit account', $data['currency_id'] ?? null],
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

    private function validateBeneficiary(int $companyId, array $data): void
    {
        $partner = Partner::where('company_id', $companyId)->findOrFail($data['beneficiary_partner_id']);

        if ($data['lg_type'] === 'Bid Bond') {
            if (!$partner->isCustomer() && !$partner->isOther()) {
                throw new \DomainException('Bid Bond beneficiary must be a Customer or Other Partner.');
            }
            return;
        }

        if (!$partner->isCustomer()) {
            throw new \DomainException("{$data['lg_type']} beneficiary must be a Customer.");
        }

        if (empty($data['contract_reference_project_id']) && empty($data['purchase_order_so_id'])) {
            throw new \DomainException("{$data['lg_type']} requires an active Contract or Sales Order for this customer.");
        }

        // The chosen contract / Sales Order must be this beneficiary's own.
        if (! empty($data['contract_reference_project_id'])
            && ! \App\Models\Project::where('company_id', $companyId)->where('id', $data['contract_reference_project_id'])->where('partner_id', $partner->id)->exists()) {
            throw new \DomainException('The selected contract does not belong to this beneficiary.');
        }
        if (! empty($data['purchase_order_so_id'])
            && ! \App\Models\SalesOrder::where('company_id', $companyId)->where('id', $data['purchase_order_so_id'])->where('partner_id', $partner->id)->exists()) {
            throw new \DomainException('The selected Sales Order does not belong to this beneficiary.');
        }
    }

    /**
     * Only reachable while the LG is genuinely still in the
     * un-undone state Set to Draft requires.
     */
    private function assertUndoable(LgIssuance $issuance, string $expectedEventType): LgIssuanceHistory
    {
        $entry = $issuance->undoableHistoryEntry();

        if (! $entry) {
            throw new \DomainException('This LG has no lifecycle action to undo.');
        }
        if ($entry->event_type !== $expectedEventType) {
            throw new \DomainException("The most recent action on this LG was a {$entry->event_type}, not a {$expectedEventType} — undo actions must be reversed in order, most recent first.");
        }
        if ($this->fiscalYearService->isPeriodClosed($issuance->company_id, (string) $entry->event_date)) {
            throw new \DomainException('This action\'s accounting period is closed — it cannot be undone.');
        }

        return $entry;
    }

    // ── Private: computed values ─────────────────────────────────

    /**
     * BUG FIX (confirmed by Mahmoud testing): a plain calendar-month
     * subtraction reads Jan 1 → Dec 31 as 11 months, not 12, because
     * Dec 31 is one day short of a full 12th elapsed month. Mahmoud's
     * counting convention is inclusive — "how many calendar months
     * does this span" — so +1 is added. Applied identically here and
     * in the Vue form's live preview; keep both in sync if this ever
     * changes.
     */
    private function monthsBetween(string $start, string $end): int
    {
        return Carbon::parse($start)->diffInMonths(Carbon::parse($end)) + 1;
    }

    /**
     * Advances a date by one commission_interval — shared by initial
     * scheduling (create()/update()), resumption (undoCancel()/
     * undoLiquidate()), and the recurring accrual sweep.
     */
    private function nextIntervalDate(Carbon $date, string $interval): Carbon
    {
        return match ($interval) {
            'monthly'     => $date->copy()->addMonthNoOverflow(),
            'quarterly'   => $date->copy()->addMonthsNoOverflow(3),
            'semi_annual' => $date->copy()->addMonthsNoOverflow(6),
            'annual'      => $date->copy()->addYearNoOverflow(),
            default       => $date->copy()->addMonthsNoOverflow(3),
        };
    }

    /**
     * Shared derivation logic between create() and update() — cash
     * cover %/amount, commission, and the facility rate-card snapshot.
     * Kept in exactly one place so the two can never drift apart on
     * how an amount is computed.
     */
    private function computeDerivedAmounts(array $data): array
    {
        $data['commission_fees_currency_id'] = $data['commission_fees_currency_id'] ?? $data['currency_id'];
        $data['cash_cover_currency_id'] = $data['issuance_type'] === 'cash_cover'
            ? $data['currency_id']
            : ($data['cash_cover_currency_id'] ?? $data['currency_id']);

        if ($data['issuance_type'] === 'cash_cover') {
            $data['cash_cover_pct'] = 100;
            $data['fees_commission_account_id'] = $data['cash_cover_account_id'] ?? null;
        }

        if ($data['issuance_type'] === 'facility' && !empty($data['facility_lg_type_id'])) {
            $rateCard = FacilityLgType::findOrFail($data['facility_lg_type_id']);
            $data['lg_type']                   = $rateCard->lg_type;
            $data['cash_cover_pct']             = $data['cash_cover_pct'] ?? $rateCard->cash_cover_pct;
            $data['commission_rate_pct']        = $data['commission_rate_pct'] ?? $rateCard->commission_rate_pct;
            $data['commission_interval']        = $data['commission_interval'] ?? $rateCard->commission_interval;
            $data['min_commission_fee_amount']  = $data['min_commission_fee_amount'] ?? $rateCard->min_commission_fee_amount;
            $data['issuance_fee_amount']        = $data['issuance_fee_amount'] ?? $rateCard->issuance_fee_amount;
        }

        $data['cash_cover_amount'] = isset($data['cash_cover_pct'])
            ? bcdiv(bcmul((string) $data['lg_amount'], (string) $data['cash_cover_pct'], 4), '100', 2)
            : null;

        $rawCommission = bcdiv(bcmul((string) $data['lg_amount'], (string) $data['commission_rate_pct'], 4), '100', 2);
        $data['commission_amount'] = bccomp($rawCommission, (string) ($data['min_commission_fee_amount'] ?? 0), 2) < 0
            ? $data['min_commission_fee_amount']
            : $rawCommission;

        return $data;
    }

    // ── Private: cost allocation tagging ─────────────────────────

    /**
     * Project/SO cost-allocation tag for every LgIssuanceMovement —
     * NOT an accounting posting, purely groundwork for Project P&L /
     * Project Cash Flow (see class header). Copied straight from the
     * parent issuance; Bid Bond has neither field set, so its
     * movements are simply untagged.
     */
    private function tagMovementData(LgIssuance $issuance): array
    {
        return [
            'project_id'     => $issuance->contract_reference_project_id,
            'sales_order_id' => $issuance->purchase_order_so_id,
        ];
    }

    // ── Private: accounting postings ─────────────────────────────

    private function buildIssuanceMovements(LgIssuance $issuance, User $actor): Collection
    {
        $coa138001 = $this->resolveCoaAccount($issuance->company_id, '138001');
        $coa553   = $this->resolveCoaAccount($issuance->company_id, '553');
        $tag = $this->tagMovementData($issuance);

        $movements = new Collection();

        // Cash movement — only when the cash cover account picked is
        // an actual Current Account. Time Deposit collateral has no
        // cash leg — the TD is blocked, not moved.
        //
        // Confirmed with Mahmoud: Facility type is the ONLY type where
        // the person can choose EITHER a Current Account OR a Time
        // Deposit as the cash cover source — so the real question is
        // "which kind of account did they pick", not "which issuance
        // type is this".
        $cashCoverAccountIsCurrent = $issuance->cash_cover_account_id
            ? BankAccount::where('id', $issuance->cash_cover_account_id)->value('account_type') === 'current'
            : false;

        if ($cashCoverAccountIsCurrent && bccomp((string) $issuance->cash_cover_amount, '0', 2) > 0) {
            $movements->push(LgIssuanceMovement::create(array_merge($tag, [
                'company_id'             => $issuance->company_id,
                'lg_issuance_id'         => $issuance->id,
                'movement_type'          => 'cash_cover_placement',
                'movement_date'          => $issuance->issuance_date,
                'debit_coa_account_id'   => $coa138001?->id,
                'credit_bank_account_id' => $issuance->cash_cover_account_id,
                'amount'                 => $issuance->cash_cover_amount,
                'currency_id'            => $issuance->cash_cover_currency_id ?? $issuance->currency_id,
                'description'            => "Cash cover placed — LG {$issuance->lg_code}",
                'created_by'             => $actor->id,
            ])));
        } elseif (!$cashCoverAccountIsCurrent && $issuance->cash_cover_account_id) {
            // Time Deposit picked as collateral — no cash movement,
            // just a memo entry so the TD-blocked nature of this
            // issuance is visible in the movements trail.
            $movements->push(LgIssuanceMovement::create(array_merge($tag, [
                'company_id'     => $issuance->company_id,
                'lg_issuance_id' => $issuance->id,
                'movement_type'  => 'cash_cover_placement',
                'movement_date'  => $issuance->issuance_date,
                'credit_bank_account_id' => $issuance->cash_cover_account_id,
                'amount'         => $issuance->cash_cover_amount,
                'currency_id'    => $issuance->cash_cover_currency_id ?? $issuance->currency_id,
                'description'    => "Time Deposit blocked as cash cover collateral — LG {$issuance->lg_code} (no cash movement; company continues earning TD interest)",
                'status'         => LgIssuanceMovement::STATUS_MEMO,
                'created_by'     => $actor->id,
            ])));
        }

        // Issuance fee — one-time
        if (bccomp((string) $issuance->issuance_fee_amount, '0', 2) > 0) {
            $movements->push(LgIssuanceMovement::create(array_merge($tag, [
                'company_id'             => $issuance->company_id,
                'lg_issuance_id'         => $issuance->id,
                'movement_type'          => 'issuance_fee',
                'movement_date'          => $issuance->issuance_date,
                'debit_coa_account_id'   => $coa553?->id,
                'credit_bank_account_id' => $issuance->fees_commission_account_id,
                'amount'                 => $issuance->issuance_fee_amount,
                'currency_id'            => $issuance->commission_fees_currency_id ?? $issuance->currency_id,
                'description'            => "Issuance fee — LG {$issuance->lg_code}",
                'created_by'             => $actor->id,
            ])));
        }

        // First commission accrual — free at issuance. Every accrual
        // after this one is driven by next_commission_accrual_date
        // (set by the caller right after this method returns) and
        // posted by postDueCommissionAccruals().
        $movements->push(LgIssuanceMovement::create(array_merge($tag, [
            'company_id'             => $issuance->company_id,
            'lg_issuance_id'         => $issuance->id,
            'movement_type'          => 'commission_accrual',
            'movement_date'          => $issuance->issuance_date,
            'debit_coa_account_id'   => $coa553?->id,
            'credit_bank_account_id' => $issuance->fees_commission_account_id,
            'amount'                 => $issuance->commission_amount,
            'currency_id'            => $issuance->commission_fees_currency_id ?? $issuance->currency_id,
            'description'            => "First commission accrual — LG {$issuance->lg_code}",
            'created_by'             => $actor->id,
        ])));

        return $movements;
    }

    private function postSimpleFeeMovement(LgIssuance $issuance, string $movementType, string $amount, User $actor, string $eventDate): LgIssuanceMovement
    {
        return LgIssuanceMovement::create(array_merge($this->tagMovementData($issuance), [
            'company_id'             => $issuance->company_id,
            'lg_issuance_id'         => $issuance->id,
            'movement_type'          => $movementType,
            'movement_date'          => $eventDate,
            'debit_coa_account_id'   => $this->resolveCoaAccount($issuance->company_id, '553')?->id,
            'credit_bank_account_id' => $issuance->fees_commission_account_id,
            'amount'                 => $amount,
            'currency_id'            => $issuance->commission_fees_currency_id ?? $issuance->currency_id,
            'description'            => "Renewal fee — LG {$issuance->lg_code}",
            'created_by'             => $actor->id,
        ]));
    }

    /**
     * LG Issuance Renewal — cash cover rate change (NOT a Facility
     * Renewal). Only the incremental delta between the old and new
     * cash cover amount ever moves; the original placement is already
     * sitting in 138001. $delta is signed (bcsub result): positive =
     * bank raised the requirement (more cash goes in), negative = bank
     * lowered it (difference released back to the bank account).
     *
     * Mirrors buildIssuanceMovements()'s Current-Account-vs-Time-Deposit
     * check: a Time-Deposit-collateralized cash cover has no real cash
     * leg, so the delta is recorded as a memo only, same as issuance.
     */
    private function postCashCoverRenewalAdjustment(LgIssuance $issuance, string $delta, User $actor, string $eventDate): LgIssuanceMovement
    {
        $coa138001  = $this->resolveCoaAccount($issuance->company_id, '138001');
        $isIncrease = bccomp($delta, '0', 2) > 0;
        $amount     = $isIncrease ? $delta : bcmul($delta, '-1', 2);

        $cashCoverAccountIsCurrent = $issuance->cash_cover_account_id
            ? BankAccount::where('id', $issuance->cash_cover_account_id)->value('account_type') === 'current'
            : false;

        $data = array_merge($this->tagMovementData($issuance), [
            'company_id'     => $issuance->company_id,
            'lg_issuance_id' => $issuance->id,
            'movement_type'  => $isIncrease ? 'cash_cover_renewal_increase' : 'cash_cover_renewal_decrease',
            'movement_date'  => $eventDate,
            'amount'         => $amount,
            'currency_id'    => $issuance->cash_cover_currency_id ?? $issuance->currency_id,
            'created_by'     => $actor->id,
        ]);

        if ($cashCoverAccountIsCurrent) {
            $data['debit_coa_account_id']   = $isIncrease ? $coa138001?->id : null;
            $data['credit_coa_account_id']  = $isIncrease ? null : $coa138001?->id;
            $data['debit_bank_account_id']  = $isIncrease ? null : $issuance->cash_cover_account_id;
            $data['credit_bank_account_id'] = $isIncrease ? $issuance->cash_cover_account_id : null;
            $data['description'] = $isIncrease
                ? "Cash cover increased at renewal — LG {$issuance->lg_code}"
                : "Cash cover decreased at renewal — released to bank — LG {$issuance->lg_code}";
        } else {
            // Time Deposit collateral — no real cash movement, memo only.
            $data['credit_bank_account_id'] = $issuance->cash_cover_account_id;
            $data['status'] = LgIssuanceMovement::STATUS_MEMO;
            $data['description'] = $isIncrease
                ? "Time Deposit cash cover requirement increased at renewal (no cash movement) — LG {$issuance->lg_code}"
                : "Time Deposit cash cover requirement decreased at renewal (no cash movement) — LG {$issuance->lg_code}";
        }

        return LgIssuanceMovement::create($data);
    }

    /**
     * Turns a batch of freshly-created LgIssuanceMovement rows from
     * the SAME event into one real, balanced JournalEntry via
     * JournalEntryService::post(). Movements with no postable legs
     * (hasPostableLegs() === false — a memo row) are marked
     * memo_only and skipped; the rest are marked posted with this
     * entry's id. Returns null (posts nothing) if every movement in
     * the batch turned out to be memo-only.
     */
    private function postMovementsAsJournalEntry(LgIssuance $issuance, Collection $movements, string $narration, User $actor, string $entryDate): ?JournalEntry
    {
        $postable = $movements->filter(fn (LgIssuanceMovement $m) => $m->hasPostableLegs());
        $memoOnly = $movements->reject(fn (LgIssuanceMovement $m) => $m->hasPostableLegs());

        foreach ($memoOnly as $memo) {
            if ($memo->status !== LgIssuanceMovement::STATUS_MEMO) {
                $memo->update(['status' => LgIssuanceMovement::STATUS_MEMO]);
            }
        }

        if ($postable->isEmpty()) {
            return null;
        }

        // Audit C8 FIX (Sept 2026): each movement's amount is in its OWN
        // currency (LG, cash-cover or fees currency). It used to go into
        // the EGP ledger 1:1. Now it is converted at that currency's rate
        // on the entry date (FxService — refuses if the rate is missing).
        $fx = app(\App\Services\Accounting\FxService::class);
        $lines = [];
        foreach ($postable as $movement) {
            $debitAccountId  = $movement->debit_coa_account_id  ?? $this->resolveBankAccountCoaId($movement->debit_bank_account_id);
            $creditAccountId = $movement->credit_coa_account_id ?? $this->resolveBankAccountCoaId($movement->credit_bank_account_id);

            $rate   = $fx->rateOn($issuance->company_id, $movement->currency_id ? (int) $movement->currency_id : null, $entryDate);
            $amount = $fx->toFunctional($movement->amount, $rate);

            // Audit H12/H15: each line keeps the movement's exact foreign amount.
            $fxTag = $movement->currency_id ? ['currency_id' => (int) $movement->currency_id, 'amount_currency' => (string) $movement->amount, 'exchange_rate' => $rate] : [];
            $lines[] = ['chart_of_account_id' => $debitAccountId,  'debit' => $amount, 'credit' => '0.00', 'description' => $movement->description] + $fxTag;
            $lines[] = ['chart_of_account_id' => $creditAccountId, 'debit' => '0.00', 'credit' => $amount, 'description' => $movement->description] + $fxTag;
        }

        $entry = $this->journalEntryService->post(
            companyId: $issuance->company_id,
            entryDate: $entryDate,
            lines: $lines,
            sourceType: 'lg_issuance',
            sourceId: $issuance->id,
            narration: $narration,
            actor: $actor,
        );

        LgIssuanceMovement::whereIn('id', $postable->pluck('id'))->update([
            'status'                   => LgIssuanceMovement::STATUS_POSTED,
            'posted_at'                => now(),
            'posted_journal_entry_id'  => $entry->id,
        ]);

        return $entry;
    }

    /**
     * Reverses every currently POSTED movement belonging to this
     * issuance — used by setToDraft(). Groups by distinct journal
     * entry so a multi-line event (e.g. issuance's cash cover + fee +
     * commission, all one entry) is only reversed once.
     */
    private function reversePostedMovements(LgIssuance $issuance, User $actor, string $narration): void
    {
        $journalEntryIds = LgIssuanceMovement::where('lg_issuance_id', $issuance->id)
            ->where('status', LgIssuanceMovement::STATUS_POSTED)
            ->whereNotNull('posted_journal_entry_id')
            ->pluck('posted_journal_entry_id')
            ->unique();

        foreach ($journalEntryIds as $journalEntryId) {
            $entry = JournalEntry::find($journalEntryId);
            if ($entry && $entry->status === JournalEntry::STATUS_POSTED) {
                $this->journalEntryService->reverse($entry, $actor, $narration);
            }
        }

        LgIssuanceMovement::where('lg_issuance_id', $issuance->id)
            ->where('status', LgIssuanceMovement::STATUS_POSTED)
            ->update(['status' => LgIssuanceMovement::STATUS_REVERSED]);
    }

    /**
     * Resolves a BankAccount's linked Chart of Accounts leg — same
     * pattern as InternalMoneyTransferService::resolveLegAccount().
     */
    private function resolveBankAccountCoaId(?int $bankAccountId): int
    {
        if (! $bankAccountId) {
            throw new \DomainException('An LG movement is missing a bank account leg — cannot post to the GL.');
        }

        $bankAccount = BankAccount::find($bankAccountId);

        if (! $bankAccount || ! $bankAccount->coa_account_id) {
            throw new \DomainException('The bank account on this LG movement is missing its Chart of Accounts link.');
        }

        return $bankAccount->coa_account_id;
    }

    /**
     * Fixed (July 2026, alongside the Journal Entry engine build) —
     * this previously did
     * ChartOfAccount::where('company_id', $companyId)->where('code', $code)->first(),
     * which is exactly the platform's documented "Known Recurring
     * Bug": every code this method is called with (138001, 553) is a
     * shared SYSTEM account with company_id = NULL, so that query
     * silently returned null on every single call. Now delegates to
     * ChartOfAccount::findByCode(), which correctly reaches system
     * accounts via withoutGlobalScopes() + forCompany().
     */
    private function resolveCoaAccount(int $companyId, string $code): ?ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, $code);
    }
}
