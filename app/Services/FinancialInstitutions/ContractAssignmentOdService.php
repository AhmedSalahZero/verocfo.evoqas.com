<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  ContractAssignmentOdService
//
//  All business logic for OD Against Contract/SO Assignment
//  (facility_type = 'contract_so', COA 223003).
//
//  Layer 1C responsibilities (this service):
//    - Create / update OD facility contract
//    - Manage assigned contract/SO collateral (many per facility)
//    - Seed opening balance withdrawals (same as Clean OD)
//    - Initialize daily balance log from opening balance date
//    - Validate: outstanding_balance <= facility_limit
//    - Validate: breakdown total ≈ outstanding_balance (tolerance 0.01)
//    - Validate: collateral is active and end_date > facility review_date
//      (Rule 30 — same as BankService::validateCollateral but adapted
//      for per-assignment validation, not per-facility)
//    - Auto-calculate and store interest_rate = borrowing + margin
//    - Enforce lending cap: MIN(lending_amount, max_lending_limit)
//
//  Assignment business rules:
//    - One facility → many assigned contracts/SOs
//    - Each assignment has its own lending_pct and max_lending_limit
//    - lending_amount = contract_value × lending_pct / 100 (bcmath)
//    - Effective lending = MIN(lending_amount, max_lending_limit)
//    - A contract/SO can only be assigned to ONE active facility
//      per company (no double-pledging)
//    - Collateral must be active and end_date > facility review_date
//
//  Layer 6 responsibilities (added later — settlement engine):
//    - recordWithdrawal()    — called by supplier payment posting
//    - recordSettlement()    — called by any inflow to OD account
//    - postMonthlyInterest() — called by month-end closing
//    - postHdbFee()          — called by quarter-end closing
//    - updateDailyBalance()  — called after every transaction
//
//  Interest formula: daily = balance × (rate / 100) / 360
//  All monetary calculations use bcmath. Never PHP float.
//
//  Note on code duplication: calcInterestRate(), calcDailyInterest(),
//  calcQuarterHdbFee(), initializeDailyBalance(), validateForeignKeys()
//  are near-identical to CleanOverdraftService and
//  CommercialPaperOverdraftService. Deliberately duplicated — with
//  three facility types now built we can still see patterns emerging
//  but extracting a base trait prematurely risks destabilising the
//  already-working services. Refactoring flagged for after all
//  facility types are complete.
//
//  @layer    Service
//  @depends  CreditFacility, FacilityContractAssignment,
//            FacilityWithdrawal, FacilityDailyBalance,
//            Project, SalesOrder, Partner, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\FinancialInstitutions\ExpiredCollateralException;
use App\Exceptions\FinancialInstitutions\InactiveSalesOrderCollateralException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilityContractAssignment;
use App\Models\FinancialInstitutions\FacilityDailyBalance;
use App\Models\FinancialInstitutions\FacilityWithdrawal;
use App\Models\Partner;
use App\Models\Project;
use App\Models\SalesOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ContractAssignmentOdService
{
    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = [], ?int $bankId = null): LengthAwarePaginator
    {
        return CreditFacility::with([
                // Bank model has BelongsToCompany — withoutGlobalScopes() prevents
                // CompanyScope from silently dropping the relationship (Known Recurring Bug).
                'bank' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name_en', 'name_ar'),
                'currency'    => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code'),
                'coaAccount'  => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code', 'name_en'),
                'contractAssignments',
                // Facility Renewal — chapter timeline for the Renew
                // modal and the renewal-count badge.
                'termsHistories',
            ])
            ->withCount([
                'contractAssignments',
                'withdrawals',
                'liabilityCharges as posted_liability_charges_count' => fn ($q) => $q->where('status', 'posted'),
            ])
            ->where('credit_facilities.company_id', $companyId)
            ->where('facility_type', 'contract_so')            ->when($bankId,                fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['bank_id']  ?? null, fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['status']   ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    // ── Portfolio Stats (KPI cards) ───────────────────────────────

    /**
     * Portfolio KPIs for the index page.
     * Currency-safe: totals are grouped by currency, never summed across.
     * avg_utilization is a per-facility ratio (currency-neutral).
     * Extra KPI for this facility type: total_assigned_contracts.
     */
    public function stats(int $companyId, array $filters = [], ?int $bankId = null): array
    {
        $base = fn () => CreditFacility::where('credit_facilities.company_id', $companyId)
            ->where('facility_type', 'contract_so')
            ->when($bankId,                fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['bank_id'] ?? null, fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['status']  ?? null, fn ($q, $s) => $q->where('status', $s));

        $totalCount  = $base()->count();
        $activeCount = $base()->where('status', 'active')->count();

        // Total assigned contracts across all facilities in scope
        $facilityIds = $base()->pluck('credit_facilities.id');
        $totalAssigned = FacilityContractAssignment::whereIn('credit_facility_id', $facilityIds)
            ->where('company_id', $companyId)
            ->count();

        // Totals per currency — shown side-by-side, never combined
        $byCurrency = $base()
            ->join('currencies', 'currencies.id', '=', 'credit_facilities.currency_id')
            ->groupBy('currencies.id', 'currencies.code')
            ->select([
                'currencies.code as currency_code',
                DB::raw('SUM(credit_facilities.facility_limit) as total_limit'),
                DB::raw('SUM(credit_facilities.outstanding_balance) as total_outstanding'),
            ])
            ->orderBy('currencies.code')
            ->get();

        // Average utilization + near-limit count (bcmath throughout)
        $rows = $base()
            ->where('facility_limit', '>', 0)
            ->get(['facility_limit', 'outstanding_balance']);

        $utilizationSum = '0';
        $nearLimitCount = 0;

        foreach ($rows as $row) {
            $pct = bcmul(
                bcdiv((string) $row->outstanding_balance, (string) $row->facility_limit, 6),
                '100',
                6
            );
            $utilizationSum = bcadd($utilizationSum, $pct, 6);
            if (bccomp($pct, '85', 2) >= 0) {
                $nearLimitCount++;
            }
        }

        $avgUtilization = $rows->isEmpty()
            ? '0.00'
            : bcdiv($utilizationSum, (string) $rows->count(), 2);

        return [
            'total_count'       => $totalCount,
            'active_count'      => $activeCount,
            'total_assigned'    => $totalAssigned,   // ← extra KPI for this facility type
            'by_currency'       => $byCurrency,
            'avg_utilization'   => $avgUtilization,
            'near_limit_count'  => $nearLimitCount,
        ];
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, Bank $bank, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $bank, $data, $actor) {

            $this->validateForeignKeys($companyId, $data);
            $this->validateOdData($data);

            $data['company_id']     = $companyId;
            $data['bank_id']        = $bank->id;
            $data['facility_type']  = 'contract_so';
            $data['created_by']     = $actor->id;
            $data['interest_rate']  = $this->calcInterestRate(
                (string) ($data['borrowing_rate']   ?? 0),
                (string) ($data['bank_margin_rate'] ?? 0)
            );

            $facility = CreditFacility::create($data);

            // Facility Renewal — every facility gets its "chapter one" row.
            $facility->createOriginalTermsHistory();

            // Seed opening balance withdrawals if breakdown provided
            if (!empty($data['breakdown'])) {
                $this->seedOpeningWithdrawals($companyId, $facility, $data['breakdown']);
            }

            // Initialize day-one balance log
            if (isset($data['outstanding_balance']) && isset($data['balance_date'])) {
                $this->initializeDailyBalance($companyId, $facility);
            }

            AuditLog::record($actor, 'credit_facility.created', $facility, null, $facility->toArray());

            return $facility->fresh(['bank', 'currency', 'coaAccount', 'contractAssignments']);
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $facility, $data, $actor) {
            $before = $facility->toArray();

            $this->validateForeignKeys($companyId, $data);
            $this->validateOdData($data);
            $facility->assertOutstandingFollowsMovements($data);

            // Recalculate interest rate whenever rates change
            $data['interest_rate'] = $this->calcInterestRate(
                (string) ($data['borrowing_rate']   ?? $facility->borrowing_rate   ?? 0),
                (string) ($data['bank_margin_rate'] ?? $facility->bank_margin_rate ?? 0)
            );

            $facility->update($data);

            // Facility Renewal — Edit always corrects whichever chapter
            // is CURRENTLY live, in place (never locked by a renewal).
            $facility->syncLatestTermsChapter();

            AuditLog::record($actor, 'credit_facility.updated', $facility, $before, $facility->fresh()->toArray());

            return $facility->fresh(['bank', 'currency', 'coaAccount', 'contractAssignments']);
        });
    }

    // ── Facility Renewal ─────────────────────────────────────────

    public function renewFacility(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        if ($facility->follows_corridor_rate) {
            unset($data['borrowing_rate']);
        }

        $facility->renew($data, $actor);

        return $facility->fresh(['bank', 'currency', 'coaAccount', 'contractAssignments']);
    }

    public function deleteRenewal(int $companyId, CreditFacility $facility, User $actor): CreditFacility
    {
        $facility->deleteLatestRenewal($actor);

        return $facility->fresh(['bank', 'currency', 'coaAccount', 'contractAssignments']);
    }

    /**
     * Deletes the whole facility outright. Only ever allowed while
     * still on its Original chapter, and only if no real money has
     * moved through it. Contract/SO assignments cascade-delete with
     * it (no separate guard needed — an assignment isn't "money
     * moved", it's collateral, and canBeDeleted() only checks real
     * transactions).
     */
    public function delete(int $companyId, CreditFacility $facility, User $actor): void
    {
        if (! $facility->canBeDeleted()) {
            throw new \DomainException(
                $facility->hasRenewals()
                    ? 'This facility has been renewed and cannot be deleted directly. Delete its most recent renewal first to bring it back to its original terms.'
                    : 'This facility cannot be deleted because it already has real transactions recorded against it.'
            );
        }

        $before = $facility->toArray();

        $facility->delete();

        AuditLog::record($actor, 'credit_facility.deleted', $facility, $before, null);
    }

    // Moved out of ContractAssignmentOdController::edit() (July 2026 audit —
    // was 17 lines, over the controller line-length limit). The relation
    // list itself is unchanged, just relocated to where the rest of this
    // facility's data-shaping lives.
    public function withEditRelations(CreditFacility $facility): CreditFacility
    {
        return $facility->load([
            'bank:id,name_en,name_ar',
            'currency'           => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code'),
            'coaAccount'         => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code', 'name_en'),
            'contractAssignments.partner:id,name_en',
            'contractAssignments.collateralProject:id,contract_code,name_en,end_date',
            'contractAssignments.collateralSo:id,so_code,subject,end_date,so_value,start_date',
            'withdrawals',
            'termsHistories',
        ]);
    }

    // ── Contract Assignment — Add ─────────────────────────────────

    /**
     * Add one contract/SO as collateral for this facility.
     *
     * Validates:
     *  1. Collateral is active and its end_date > facility review_date (Rule 30)
     *  2. This contract/SO is not already pledged to another active
     *     facility for the same company (no double-pledging)
     *  3. lending_pct is between 0 and 100
     *  4. max_lending_limit > 0
     *
     * Stores:
     *  - lending_amount = contract_value × lending_pct / 100 (bcmath)
     *  - max_lending_limit as-entered
     *  - Effective lending cap = MIN(lending_amount, max_lending_limit)
     *    (computed on read — not stored separately)
     */
    public function addAssignment(
        int $companyId,
        CreditFacility $facility,
        array $data,
        User $actor
    ): FacilityContractAssignment {

        return DB::transaction(function () use ($companyId, $facility, $data, $actor) {

            // ── Resolve collateral ────────────────────────────────
            $type     = $data['collateral_type']; // 'project' or 'sales_order'
            $isProject = $type === 'project';

            // Fix (July 2026 audit — #9, applied): this used to rely
            // on Eloquent's firstOrFail(), which throws a generic
            // ModelNotFoundException (a bare 404-style error with no
            // Vero-specific message) if the collateral is missing or
            // not active. Switched to an explicit find + named
            // exception, same as BankService::validateCollateral().
            $collateral = $isProject
                ? Project::where('company_id', $companyId)
                         ->where('id', $data['collateral_project_id'])
                         ->where('status', 'active')
                         ->first()
                : SalesOrder::where('company_id', $companyId)
                            ->where('id', $data['collateral_so_id'])
                            ->where('status', 'active')
                            ->first();

            if (! $collateral) {
                throw new InactiveSalesOrderCollateralException(
                    'The selected collateral was not found or is not active.'
                );
            }

            // ── Rule 30: end_date must be after review_date ───────
            $reviewDate = $facility->review_date
                ? $facility->review_date->format('Y-m-d')
                : now()->format('Y-m-d');

            if ($collateral->end_date->format('Y-m-d') <= $reviewDate) {
                throw new ExpiredCollateralException(
                    'The assigned contract/SO end date must be after the facility review date. ' .
                    'Banks do not finance expired or finishing contracts.'
                );
            }

            // Soft warning — within 30 days (Rule 30.1)
            $daysDiff = Carbon::parse($reviewDate)->diffInDays(
                Carbon::parse($collateral->end_date->format('Y-m-d'))
            );
            if ($daysDiff <= 30) {
                session()->flash('warning',
                    'The assigned contract/SO ends within 30 days of the facility review date. ' .
                    'Confirm this is acceptable with your bank.'
                );
            }

            // ── No double-pledging ────────────────────────────────
            // TRS-004 fix (8 Sep 2026) — this used to exclude the
            // CURRENT facility from the check (`!= $facility->id`),
            // which correctly blocked pledging the same contract to a
            // DIFFERENT active facility, but left the door wide open
            // to pledging it to THIS SAME facility a second time. A
            // contract cannot be pledged twice, full stop — whether
            // the duplicate lands on another facility or this one.
            $alreadyPledged = FacilityContractAssignment::where('company_id', $companyId)
                ->when($isProject,
                    fn ($q) => $q->where('collateral_project_id', $collateral->id),
                    fn ($q) => $q->where('collateral_so_id', $collateral->id)
                )
                ->whereHas('facility', fn ($q) => $q->where('status', 'active'))
                ->exists();

            if ($alreadyPledged) {
                throw new \DomainException(
                    'This contract/SO is already assigned to an active overdraft facility. ' .
                    'A contract cannot be pledged more than once, whether to this facility or another one.'
                );
            }

            // ── Calculate lending amount (bcmath) ─────────────────
            $contractValue = (string) ($isProject
                ? ($collateral->contract_value ?? 0)
                : ($collateral->so_value        ?? 0)
            );

            // M-30: the contract may be in a different currency from the
            // facility (and its cap). Convert the contract value into the
            // FACILITY currency at the assignment date before applying the
            // percentage, so lending amount and cap are always comparable.
            $contractCurrency = $collateral->currency_id ?? null;
            if ($contractCurrency && $facility->currency_id && (int) $contractCurrency !== (int) $facility->currency_id) {
                $fx = app(\App\Services\Accounting\FxService::class);
                $asOf = (string) $data['assignment_date'];
                $inFunctional = bcmul($contractValue, $fx->rateOn($companyId, (int) $contractCurrency, $asOf), 6);
                $contractValue = bcdiv($inFunctional, $fx->rateOn($companyId, (int) $facility->currency_id, $asOf), 2);
            }

            $lendingPct    = (string) $data['lending_pct'];
            $lendingAmount = bcmul(
                $contractValue,
                bcdiv($lendingPct, '100', 10),
                2
            );

            // Effective lending = MIN(lending_amount, facility cap).
            // The cap comes from the facility itself (max_lending_limit_per_contract),
            // NOT from the user — it was set when the OD contract was created.
            // NULL cap means no restriction — full lending_amount applies.
            $facilityCap = $facility->max_lending_limit_per_contract
                ? (string) $facility->max_lending_limit_per_contract
                : null;

            // ── Create the assignment ─────────────────────────────
            $assignment = FacilityContractAssignment::create([
                'company_id'            => $companyId,
                'credit_facility_id'    => $facility->id,
                'partner_id'            => $data['partner_id'],
                'collateral_type'       => $type,
                'collateral_project_id' => $isProject ? $collateral->id : null,
                'collateral_so_id'      => $isProject ? null : $collateral->id,
                'assignment_date'       => $data['assignment_date'],
                'lending_pct'           => $lendingPct,
                'lending_amount'        => $lendingAmount,
                // Store the cap that was in effect at assignment time for audit trail
                'facility_cap_at_assignment' => $facilityCap,
                'created_by'            => $actor->id,
            ]);

            AuditLog::record(
                $actor,
                'facility_contract_assignment.added',
                $assignment,
                null,
                $assignment->toArray()
            );

            return $assignment->load([
                'partner:id,name_en',
                'collateralProject:id,contract_code,name_en,end_date,contract_value,start_date',
                'collateralSo:id,so_code,subject,end_date,so_value,start_date',
            ]);
        });
    }

    // ── Contract Assignment — Remove ──────────────────────────────

    public function removeAssignment(
        int $companyId,
        FacilityContractAssignment $assignment,
        User $actor
    ): void {
        DB::transaction(function () use ($companyId, $assignment, $actor) {
            $before = $assignment->toArray();

            // Verify the assignment belongs to this company
            if ((int) $assignment->company_id !== $companyId) {
                throw new \DomainException('Assignment not found for this company.');
            }

            $assignment->delete();

            AuditLog::record(
                $actor,
                'facility_contract_assignment.removed',
                $assignment,
                $before,
                null
            );
        });
    }

    // ── Eligible Collateral (for modal dropdowns) ─────────────────

    /**
     * Returns active Projects and Sales Orders eligible to be assigned
     * as collateral for the given facility.
     *
     * Filters:
     *  - Active status only
     *  - end_date > today (hard) and > facility review_date (hard)
     *  - Not already pledged to another active facility
     *  - If partner_id given: only that customer's records
     *
     * Returns unified array for the Vue modal dropdown:
     *  [{id, type, label, partner_id, partner_name,
     *    amount, start_date, end_date}]
     */
    public function eligibleCollateral(
        int $companyId,
        CreditFacility $facility,
        ?int $partnerId = null
    ): array {
        $reviewDate  = $facility->review_date
            ? $facility->review_date->format('Y-m-d')
            : now()->format('Y-m-d');

        // Already-pledged collateral IDs. TRS-004 fix (8 Sep 2026) —
        // this used to exclude the current facility (`!= $facility->id`),
        // which meant this dropdown would still offer a contract that
        // was already pledged to THIS SAME facility, letting someone
        // pick it a second time. Now excludes any active pledge,
        // regardless of which facility it's on.
        $pledgedProjectIds = FacilityContractAssignment::where('company_id', $companyId)
            ->whereNotNull('collateral_project_id')
            ->whereHas('facility', fn ($q) => $q->where('status', 'active'))
            ->pluck('collateral_project_id')
            ->all();

        $pledgedSoIds = FacilityContractAssignment::where('company_id', $companyId)
            ->whereNotNull('collateral_so_id')
            ->whereHas('facility', fn ($q) => $q->where('status', 'active'))
            ->pluck('collateral_so_id')
            ->all();

        // Active projects — with contract value and partner
        $projects = Project::where('company_id', $companyId)
            ->where('status', 'active')
            ->where('end_date', '>', $reviewDate)
            ->when($partnerId, fn ($q, $p) => $q->where('partner_id', $p))
            ->when($pledgedProjectIds, fn ($q) => $q->whereNotIn('id', $pledgedProjectIds))
            ->with('partner:id,name_en')
            ->get(['id', 'contract_code', 'name_en', 'partner_id', 'end_date',
                   'contract_value', 'budget', 'start_date'])
            ->map(fn ($p) => [
                'id'           => $p->id,
                'type'         => 'project',
                'label'        => ($p->contract_code ?? 'PRJ-' . $p->id) . ' — ' . $p->name_en,
                'partner_id'   => $p->partner_id,
                'partner_name' => $p->partner?->name_en ?? '—',
                'amount'       => $p->contract_value ?? $p->budget ?? 0,
                'start_date'   => $p->start_date?->format('Y-m-d'),
                'end_date'     => $p->end_date?->format('Y-m-d'),
            ]);

        // Active sales orders — column names from migration 000039:
        // so_value (not total_amount/grand_total), so_code (not so_number), subject (not name)
        $salesOrders = SalesOrder::where('company_id', $companyId)
            ->where('status', 'active')
            ->where('end_date', '>', $reviewDate)
            ->when($partnerId, fn ($q, $p) => $q->where('partner_id', $p))
            ->when($pledgedSoIds, fn ($q) => $q->whereNotIn('id', $pledgedSoIds))
            ->with('partner:id,name_en')
            ->get(['id', 'so_code', 'partner_id', 'end_date',
                   'so_value', 'start_date', 'subject'])
            ->map(fn ($so) => [
                'id'           => $so->id,
                'type'         => 'sales_order',
                'label'        => $so->so_code . ($so->subject ? ' — ' . $so->subject : ''),
                'partner_id'   => $so->partner_id,
                'partner_name' => $so->partner?->name_en ?? '—',
                'amount'       => $so->so_value ?? 0,
                'start_date'   => $so->start_date?->format('Y-m-d'),
                'end_date'     => $so->end_date?->format('Y-m-d'),
            ]);

        return $projects->concat($salesOrders)->values()->all();
    }

    /**
     * Returns the list of customers (partners) who have active
     * Projects or Sales Orders eligible for assignment.
     * Used to populate the Customer dropdown in the modal.
     */
    public function eligiblePartners(int $companyId, CreditFacility $facility): array
    {
        $reviewDate = $facility->review_date
            ? $facility->review_date->format('Y-m-d')
            : now()->format('Y-m-d');

        $projectPartnerIds = Project::where('company_id', $companyId)
            ->where('status', 'active')
            ->where('end_date', '>', $reviewDate)
            ->pluck('partner_id');

        $soPartnerIds = SalesOrder::where('company_id', $companyId)
            ->where('status', 'active')
            ->where('end_date', '>', $reviewDate)
            ->pluck('partner_id');
        $allIds = $projectPartnerIds->merge($soPartnerIds)->unique()->values();

        return Partner::where('company_id', $companyId)
            ->whereIn('id', $allIds)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar'])
            ->toArray();
    }

    // ── Interest Rate Calculation ─────────────────────────────────

    /** interest_rate = borrowing_rate + bank_margin_rate (bcmath, 4dp) */
    public function calcInterestRate(string $borrowingRate, string $bankMarginRate): string
    {
        return bcadd((string) $borrowingRate, (string) $bankMarginRate, 4);
    }

    // ── Daily Interest (360-day Egyptian standard) ────────────────

    /** daily = balance × (annual_rate / 100) / 360 */
    public function calcDailyInterest(string $balance, string $annualRate): string
    {
        $dailyRate      = bcdiv($annualRate, '100', 10);
        $annualInterest = bcmul($balance, $dailyRate, 10);
        return bcdiv($annualInterest, '360', 6);
    }

    // ── Private Helpers ───────────────────────────────────────────

    private function validateOdData(array $data): void
    {
        $limit   = (string) ($data['facility_limit']      ?? 0);
        $balance = (string) ($data['outstanding_balance'] ?? 0);

        if (bccomp($balance, $limit, 2) > 0) {
            throw new \DomainException(
                "Outstanding balance ({$balance}) cannot exceed facility limit ({$limit})."
            );
        }

        if (!empty($data['breakdown'])) {
            $total = array_reduce(
                $data['breakdown'],
                fn ($carry, $row) => bcadd($carry, (string) ($row['amount'] ?? 0), 2),
                '0'
            );
            $diff = bcsub($total, $balance, 2);
            if (bccomp(ltrim($diff, '-'), '0.01', 2) > 0) {
                throw new \DomainException(
                    "Outstanding breakdown total ({$total}) differs from outstanding balance ({$balance}) by more than 0.01."
                );
            }
        }
    }

    private function seedOpeningWithdrawals(int $companyId, CreditFacility $facility, array $breakdown): void
    {
        foreach ($breakdown as $row) {
            if (empty($row['amount']) || bccomp((string) $row['amount'], '0', 2) <= 0) {
                continue;
            }
            FacilityWithdrawal::create([
                'company_id'         => $companyId,
                'credit_facility_id' => $facility->id,
                'withdrawal_date'    => $facility->balance_date,
                'due_date'           => $row['due_date'],
                'amount'             => $row['amount'],
                'settled_amount'     => '0.00',
                'remaining_amount'   => $row['amount'],
                'status'             => 'open',
                'is_opening_balance' => true,
                'description'        => 'Opening balance withdrawal',
            ]);
        }
    }

    private function initializeDailyBalance(int $companyId, CreditFacility $facility): void
    {
        // Search keys must match the composite unique index exactly:
        // (credit_facility_id, balance_date) — see migration 000058.
        // company_id is included in the update payload (not the search)
        // so it is always written but never causes a false non-match.
        FacilityDailyBalance::updateOrCreate(
            [
                'credit_facility_id' => $facility->id,
                'balance_date'       => $facility->balance_date,
            ],
            [
                'company_id'            => $companyId,
                'opening_balance'       => '0.00',
                'withdrawals_today'     => $facility->outstanding_balance,
                'charges_today'         => '0.00',
                'settlements_today'     => '0.00',
                'closing_balance'       => $facility->outstanding_balance,
                'daily_interest_amount' => $this->calcDailyInterest(
                    (string) $facility->outstanding_balance,
                    (string) ($facility->interest_rate ?? 0)
                ),
                'is_quarter_peak'       => false,
            ]
        );
    }

    /** Rule 2.5 — Cross-company FK validation (coa_account_id, currency_id) */
    private function validateForeignKeys(int $companyId, array $data): void
    {
        if (!empty($data['coa_account_id'])) {
            $ok = ChartOfAccount::withoutGlobalScopes()
                ->forCompany($companyId)
                ->where('id', $data['coa_account_id'])
                ->exists();
            if (!$ok) {
                throw new \DomainException('Selected COA account is not available for this company.');
            }
        }

        if (!empty($data['currency_id'])) {
            $ok = Currency::withoutGlobalScopes()
                ->forCompany($companyId)
                ->where('id', $data['currency_id'])
                ->exists();
            if (!$ok) {
                throw new \DomainException('Selected currency is not available for this company.');
            }
        }
    }
}