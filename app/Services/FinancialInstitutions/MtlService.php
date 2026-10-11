<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  MtlService
//
//  Business logic for Medium Term Loan (facility_type = 'mtl').
//  Confirmed with Mahmoud, distinct from every other facility type
//  built so far:
//    - NO auto-calculated amortization. The Loan Schedule Table is
//      entered manually or via Excel upload — same mechanism as
//      Leasing's cheque schedule (LeasingService::saveCheques() /
//      parseChequesExcel() are the templates this mirrors).
//    - A single schedule line can be settled across MANY partial
//      payments (MtlSettlement) — genuinely different from every
//      other "one event, one row" pattern in this app so far.
//
//  ACCOUNTING NOTE — read both comments below before building the
//  Accounting Engine's handling of MTL. There are TWO separate
//  future postings, not one:
//    1. Initial loan liability (createFacility(), 'new' entry_type
//       only)
//    2. Every installment settlement (recordSettlement(), for BOTH
//       'new' and 'existing' loans — paying down an existing loan is
//       real money movement happening now, regardless of how the
//       loan itself originated)
//
//  @layer    Service
//  @depends  CreditFacility, MtlScheduleLine, MtlSettlement, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\MtlMovement;
use App\Models\FinancialInstitutions\MtlScheduleLine;
use App\Models\FinancialInstitutions\MtlSettlement;
use App\Models\JournalEntry;
use App\Models\User;
use App\Models\FinancialInstitutions\FacilitySettlement;
use App\Services\Accounting\JournalEntryService;
use App\Services\FinancialInstitutions\FacilitySettlementEngine;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class MtlService
{
    use \App\Services\FinancialInstitutions\Concerns\ChecksInterestVariance;

    /** Long-Term Loans — the liability this facility creates. */
    private const LOAN_LIABILITY_CODE = '211';

    /** Interest on Loans — the finance cost each settlement carries. */
    private const INTEREST_EXPENSE_CODE = '551';

    public function __construct(
        private JournalEntryService $journalEntryService,
        private FacilitySettlementEngine $settlementEngine,
    ) {
    }

    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return CreditFacility::with([
                'bank' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name_en', 'name_ar'),
                'currency' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code'),
            ])
            ->where('credit_facilities.company_id', $companyId)
            ->where('facility_type', 'mtl')
            ->when($filters['bank_id'] ?? null, fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['status']  ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    public function stats(int $companyId, array $filters = []): array
    {
        $base = fn () => CreditFacility::where('credit_facilities.company_id', $companyId)
            ->where('facility_type', 'mtl')
            ->when($filters['bank_id'] ?? null, fn ($q, $b) => $q->where('bank_id', $b));

        $totalCount  = $base()->count();
        $activeCount = $base()->where('status', 'active')->count();

        $byCurrency = $base()
            ->join('currencies', 'currencies.id', '=', 'credit_facilities.currency_id')
            ->groupBy('currencies.id', 'currencies.code')
            ->selectRaw('currencies.code as currency_code, SUM(credit_facilities.facility_limit) as total_limit')
            ->orderBy('currencies.code')
            ->get();

        $facilityIds = $base()->pluck('id');
        $pastDueCount = MtlScheduleLine::where('company_id', $companyId)
            ->whereIn('credit_facility_id', $facilityIds)
            ->where('due_date', '<', now())
            ->get()
            ->filter(fn ($line) => in_array($line->computedStatus(), ['past_due', 'partially_paid']))
            ->count();

        return [
            'total_count'      => $totalCount,
            'active_count'     => $activeCount,
            'by_currency'      => $byCurrency,
            'past_due_count'   => $pastDueCount,
        ];
    }

    // ── Create / Update the loan facility ───────────────────────────

    public function create(int $companyId, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {

            $data['company_id']    = $companyId;
            $data['facility_type'] = 'mtl';
            $data['created_by']    = $actor->id;
            $data['status']        = 'active';
            // TRS-002 / CashVero parity (2026-09) — a NEW loan is a
            // payment SOURCE, not a cash disbursement. The bank does
            // not hand the money over; it pays the company's suppliers
            // directly out of the loan, so the facility behaves like a
            // Clean Overdraft: every payment made from it draws the
            // loan down and credits this account.
            //
            // Pointing coa_account_id at 211 Long-Term Loans is what
            // makes that accounting happen with no special code —
            // SupplierPaymentService::resolveBankOrFacilityAccount()
            // already credits a facility's own COA account, and
            // FacilitySettlementEngine already grows
            // outstanding_balance and enforces facility_limit. Room is
            // limit − outstanding, exactly CashVero's limit + end_balance.
            //
            // An EXISTING loan was drawn and spent before this system;
            // there is nothing left to draw, so it gets no COA link and
            // never appears as a payment source.
            $data['coa_account_id'] = $data['entry_type'] === 'new'
                ? $this->resolveAccount($companyId, self::LOAN_LIABILITY_CODE, 'Long-Term Loans')->id
                : null;
            $data['interest_rate']  = bcadd((string) $data['borrowing_rate'], (string) $data['bank_margin_rate'], 4);

            $facility = CreditFacility::create($data);

            // ═══════════════════════════════════════════════════════
            // NO DRAWDOWN POSTING AT CREATION — CashVero parity
            // ═══════════════════════════════════════════════════════
            // An earlier version posted the FULL facility limit as
            // cash into a bank account the moment the loan was
            // created. That is not how the product works: the money
            // is drawn progressively, as the company pays suppliers
            // from the loan, and each of those payments posts its own
            // entry through the ordinary payment path
            // (Dr supplier / Cr 211 Long-Term Loans) and records a
            // facility withdrawal against the limit.
            //
            // Posting the whole limit up front showed cash the company
            // had not received and a liability it had not yet incurred.
            // ═══════════════════════════════════════════════════════

            AuditLog::record($actor, 'mtl_facility.created', $facility, null, $facility->toArray());

            return $facility->fresh(['bank', 'currency']);
        });
    }

    public function update(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $facility, $data, $actor) {
            $before = $facility->toArray();

            // Same rule as create(): a NEW loan is a payment source and
            // must keep its link to 211 Long-Term Loans. Editing used to
            // set this to null for every loan, which took a new loan out
            // of the Cash Status dashboard and made every supplier
            // payment from it fail with "missing its Chart of Accounts
            // link". An EXISTING loan has no link.
            $data['coa_account_id'] = ($data['entry_type'] ?? $facility->entry_type) === 'new'
                ? $this->resolveAccount($companyId, self::LOAN_LIABILITY_CODE, 'Long-Term Loans')->id
                : null;
            $data['interest_rate']  = bcadd((string) $data['borrowing_rate'], (string) $data['bank_margin_rate'], 4);

            $facility->update($data);

            AuditLog::record($actor, 'mtl_facility.updated', $facility, $before, $facility->fresh()->toArray());

            return $facility->fresh(['bank', 'currency']);
        });
    }

    // ── Schedule (manual entry / Excel — mirrors LeasingService) ────

    /**
     * Replaces the facility's entire schedule with the given rows —
     * same full-replace approach as LeasingService::saveCheques().
     * NOTE: replacing wipes existing MtlSettlement history via the
     * cascade on mtl_schedule_lines — this is fine for a schedule
     * being actively edited pre-activation, but calling this again
     * on a facility that already has real settlements recorded would
     * silently destroy that payment history. Flagging this now
     * rather than adding UI protection that wasn't asked for — worth
     * a guard once this form is actually in daily use.
     */
    /**
     * CRITICAL FIX — this method previously deleted every existing
     * schedule line for the facility, then recreated all of them
     * fresh from the submitted array, on every single save. Because
     * mtl_settlements.mtl_schedule_line_id cascades on delete, this
     * meant every real, recorded settlement on the loan was silently
     * destroyed the next time anyone clicked "Save Schedule" for any
     * reason — even just to add one more installment. This was a
     * live, active data-loss bug, found and fixed together with the
     * row-locking feature below (the two had to be fixed together —
     * locking is meaningless if the whole table gets wiped anyway).
     *
     * Now: existing lines are updated in place (same id, same
     * settlements still correctly attached) or left completely
     * untouched if locked; only genuinely new rows are created, and
     * only genuinely removed, never-settled rows are deleted. A
     * locked line can never be edited or removed — reverse its
     * settlement(s) first (deleteSettlement(), already built).
     */
    // Builds the data shape MtlScheduleIndex.vue needs for one facility's
    // schedule — moved out of MtlController::schedule() (was 20 lines,
    // over the controller line-length limit, and doing per-row data
    // shaping that belongs here, not in the controller).
    public function scheduleForFacility(CreditFacility $facility): array
    {
        return [
            'facility'      => $facility->only(['id', 'name', 'currency_id']),
            'currency'      => $facility->currency()->select('id', 'code')->first(),
            // v1.1 — added is_locked per line (Rule: any recorded
            // settlement, even partial, locks the row's schedule
            // fields against editing/removal — see
            // MtlScheduleLine::isLocked() and MtlScheduleIndex.vue,
            // now a single merged table instead of two).
            'scheduleLines' => $facility->mtlScheduleLines()
                ->withCount('settlements')
                ->orderBy('sort_order')
                ->get()
                ->map(fn ($l) => array_merge($l->toArray(), [
                    'settled_amount'   => $l->settledAmount(),
                    'remaining_amount' => $l->remainingAmount(),
                    'computed_status'  => $l->computedStatus(),
                    'is_locked'        => $l->isLocked(),
                ])),
        ];
    }

    public function saveSchedule(CreditFacility $facility, array $lines, User $actor): void
    {
        $this->assertScheduleAddsUp($lines);
        $this->interestWarnings = [];
        foreach ($lines as $row) {
            if (empty($row['due_date']) || empty($row['beginning_balance']) || empty($row['interest_amount'])) continue;
            // days = distance to the previous line's due date, else 30-day month
            $prev = null;
            foreach ($lines as $o) { if (!empty($o['due_date']) && $o['due_date'] < $row['due_date'] && ($prev === null || $o['due_date'] > $prev)) $prev = $o['due_date']; }
            $days = $prev ? (int) abs(\Carbon\Carbon::parse($prev)->diffInDays(\Carbon\Carbon::parse($row['due_date']))) : 0;
            $this->checkInterestVariance((string) $row['interest_amount'], (string) $row['beginning_balance'], (string) $facility->interest_rate, $days, 'Instalment due '.$row['due_date']);
        }

        DB::transaction(function () use ($facility, $lines, $actor) {
            $existingLines = MtlScheduleLine::where('credit_facility_id', $facility->id)
                ->get()
                ->keyBy('id');

            $submittedIds = [];
            $createdCount = 0;
            $updatedCount = 0;

            foreach ($lines as $i => $row) {
                if (empty($row['due_date'])) {
                    continue; // blank placeholder row — never saved
                }

                $id = $row['id'] ?? null;

                if ($id && $existingLines->has($id)) {
                    $line = $existingLines->get($id);
                    $submittedIds[] = $id;

                    if ($line->isLocked()) {
                        $this->assertRowUnchanged($line, $row);
                        // Unchanged and locked — leave every schedule
                        // field untouched. Only sort_order (display
                        // order) is safe to update on a locked row.
                        $line->update(['sort_order' => $i]);
                        continue;
                    }

                    $line->update([
                        'due_date'          => $row['due_date'],
                        'beginning_balance' => $row['beginning_balance'] ?? 0,
                        'schedule_payment'  => $row['schedule_payment']  ?? 0,
                        'interest_amount'   => $row['interest_amount']   ?? 0,
                        'principal_amount'  => $row['principal_amount']  ?? 0,
                        'end_balance'       => $row['end_balance']       ?? 0,
                        'sort_order'        => $i,
                    ]);
                    $updatedCount++;
                } else {
                    MtlScheduleLine::create([
                        'company_id'         => $facility->company_id,
                        'credit_facility_id' => $facility->id,
                        'due_date'           => $row['due_date'],
                        'beginning_balance'  => $row['beginning_balance'] ?? 0,
                        'schedule_payment'   => $row['schedule_payment']  ?? 0,
                        'interest_amount'    => $row['interest_amount']   ?? 0,
                        'principal_amount'   => $row['principal_amount']  ?? 0,
                        'end_balance'        => $row['end_balance']       ?? 0,
                        'sort_order'         => $i,
                        'created_by'         => $actor->id,
                    ]);
                    $createdCount++;
                }
            }

            // Anything that existed before but wasn't in this submission
            // was removed via the Remove (×) button — safe to delete,
            // UNLESS it's locked, in which case something bypassed the
            // screen-level protection and this is the backstop.
            $deletedCount = 0;
            foreach ($existingLines as $id => $line) {
                if (in_array($id, $submittedIds, true)) continue;

                if ($line->isLocked()) {
                    throw new \DomainException(
                        'This installment has a recorded settlement and cannot be removed — reverse the settlement first.'
                    );
                }

                $line->delete();
                $deletedCount++;
            }

            AuditLog::record(
                $actor,
                'mtl_schedule.saved',
                $facility,
                null,
                ['created' => $createdCount, 'updated' => $updatedCount, 'removed' => $deletedCount]
            );
        });
    }

    /**
     * Audit M22 — a typed or imported schedule must add up before it
     * is saved. Per instalment:
     *     interest + principal  =  payment
     *     beginning balance − principal  =  end balance
     * and across instalments:
     *     each beginning balance  =  the previous end balance
     *     due dates run in order
     *     total principal can never exceed the opening balance
     * Anything off by more than one piastre is refused with the row
     * number, so a typo or a bad Excel column is caught here instead of
     * flowing into every interest and current-portion figure later.
     * (The schedule need not reach zero — an existing loan can be
     * entered part-way — so that is not enforced.)
     */
    private function assertScheduleAddsUp(array $lines): void
    {
        $tolerance = '0.01';
        $differs = fn (string $a, string $b): bool =>
            bccomp(ltrim(bcsub($a, $b, 2), '-'), $tolerance, 2) > 0;
        $num = fn ($v): string => Money::round($v, 2); // Audit L5: exact, no float

        $previous = null;
        $previousDue = null;
        $openingBalance = null;
        $principalTotal = '0.00';
        $n = 0;

        foreach ($lines as $row) {
            if (empty($row['due_date'])) {
                continue; // blank placeholder row
            }
            $n++;

            $begin = $num($row['beginning_balance'] ?? 0);
            $pay   = $num($row['schedule_payment'] ?? 0);
            $int   = $num($row['interest_amount'] ?? 0);
            $prin  = $num($row['principal_amount'] ?? 0);
            $end   = $num($row['end_balance'] ?? 0);
            $due   = substr((string) $row['due_date'], 0, 10);

            if ($differs(bcadd($int, $prin, 2), $pay)) {
                throw new \DomainException("Instalment {$n}: interest ({$int}) + principal ({$prin}) does not equal the payment ({$pay}).");
            }
            if ($differs(bcsub($begin, $prin, 2), $end)) {
                throw new \DomainException("Instalment {$n}: beginning balance ({$begin}) minus principal ({$prin}) does not equal the end balance ({$end}).");
            }
            if ($previous !== null && $differs($begin, $previous)) {
                throw new \DomainException("Instalment {$n}: beginning balance ({$begin}) does not continue from the previous end balance ({$previous}).");
            }
            if ($previousDue !== null && $due < $previousDue) {
                throw new \DomainException("Instalment {$n}: due date {$due} is earlier than the previous instalment ({$previousDue}) — put the instalments in date order.");
            }

            $openingBalance ??= $begin;
            $principalTotal = bcadd($principalTotal, $prin, 2);
            $previous = $end;
            $previousDue = $due;
        }

        if ($openingBalance !== null && bccomp($principalTotal, bcadd($openingBalance, $tolerance, 2), 2) > 0) {
            throw new \DomainException("The principal repaid across all instalments ({$principalTotal}) is more than the opening balance ({$openingBalance}).");
        }
    }

    /**
     * Backstop for a locked row — the screen never sends changed
     * values for a locked row, but this makes sure that holds true
     * even if something bypasses the screen entirely.
     */
    private function assertRowUnchanged(MtlScheduleLine $line, array $row): void
    {
        $unchanged =
            $line->due_date->format('Y-m-d') === ($row['due_date'] ?? null)
            && bccomp((string) $line->beginning_balance, (string) ($row['beginning_balance'] ?? 0), 2) === 0
            && bccomp((string) $line->schedule_payment,  (string) ($row['schedule_payment']  ?? 0), 2) === 0
            && bccomp((string) $line->interest_amount,   (string) ($row['interest_amount']   ?? 0), 2) === 0
            && bccomp((string) $line->principal_amount,  (string) ($row['principal_amount']  ?? 0), 2) === 0
            && bccomp((string) $line->end_balance,       (string) ($row['end_balance']        ?? 0), 2) === 0;

        if (! $unchanged) {
            throw new \DomainException(
                'This installment has a recorded settlement and cannot be edited — reverse the settlement first.'
            );
        }
    }

    /**
     * Parses an uploaded Excel schedule for review before saving —
     * does not write to the database. Same pattern and column-order
     * discipline as LeasingService::parseChequesExcel(): this MUST
     * always match the on-screen table's column order exactly.
     * Expected columns (row 1 = header, ignored):
     *   Due Date | Beginning Balance | Schedule Payment | Interest Amount | Principal Amount | End Balance
     */
    public function parseScheduleExcel(\Illuminate\Http\UploadedFile $file): array
    {
        $rows = \Maatwebsite\Excel\Facades\Excel::toArray([], $file)[0] ?? [];

        $parsed = [];
        foreach ($rows as $i => $row) {
            if ($i === 0) continue;
            if (empty(array_filter($row))) continue;

            $dueDate = $row[0] ?? null;
            if (is_numeric($dueDate)) {
                $dueDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dueDate)->format('Y-m-d');
            } elseif ($dueDate) {
                $dueDate = date('Y-m-d', strtotime($dueDate));
            }

            $parsed[] = [
                'due_date'          => $dueDate,
                'beginning_balance' => Money::round($row[1] ?? 0, 2),
                'schedule_payment'  => Money::round($row[2] ?? 0, 2),
                'interest_amount'   => Money::round($row[3] ?? 0, 2),
                'principal_amount'  => Money::round($row[4] ?? 0, 2),
                'end_balance'       => Money::round($row[5] ?? 0, 2),
            ];
        }

        return $parsed;
    }

    // ── Settlement (the $ action — supports partial payments) ──────

    public function recordSettlement(MtlScheduleLine $line, array $data, User $actor): MtlSettlement
    {
        $amount = (string) $data['settlement_amount'];

        if (bccomp($amount, $line->remainingAmount(), 2) > 0) {
            throw new \DomainException(
                'Settlement amount cannot exceed the remaining balance on this installment.'
            );
        }

        return DB::transaction(function () use ($line, $data, $actor, $amount) {

            $settlement = MtlSettlement::create([
                'company_id'          => $line->company_id,
                'mtl_schedule_line_id'=> $line->id,
                'settlement_date'     => $data['settlement_date'],
                'settlement_amount'   => $amount,
                'current_account_id'  => $data['current_account_id'],
                'notes'               => $data['notes'] ?? null,
                'created_by'          => $actor->id,
            ]);

            // ═══════════════════════════════════════════════════════
            // INSTALLMENT SETTLEMENT — TRS-002 fix (2026-09)
            // ═══════════════════════════════════════════════════════
            // Applies to BOTH 'new' and 'existing' loans, unlike the
            // drawdown posting in create(), which is 'new'-only.
            //
            //   Dr  211 Long-Term Loans   (principal portion)
            //   Dr  551 Interest on Loans (interest portion)
            //   Cr  the settlement's own bank account
            //
            // SPLIT RULE — interest first, matching CashVero (see
            // splitSettlement()). A payment clears the instalment's
            // interest in full before any of it reaches principal, so
            // the loan liability only falls once the interest is
            // covered.
            $this->postSettlement($line, $settlement, $actor);

            AuditLog::record($actor, 'mtl_settlement.recorded', $settlement, null, $settlement->toArray());

            return $settlement;
        });
    }

    /**
     * Deleting a settlement reverses whatever it posted.
     *
     * TRS-002 — the row used to be deleted outright, which was
     * harmless while nothing was posted. Now that it carries a journal
     * entry, deleting it silently would leave the repayment in the
     * general ledger with nothing in the module explaining it. The
     * entry is reversed through JournalEntryService::reverse() — the
     * same path every other module uses — so the correction is on the
     * record rather than erased from it.
     */
    public function deleteSettlement(MtlSettlement $settlement, User $actor): void
    {
        DB::transaction(function () use ($settlement, $actor) {
            $movements = MtlMovement::withoutGlobalScopes()
                ->where('mtl_settlement_id', $settlement->id)
                ->get();

            $entryIds = $movements->pluck('posted_journal_entry_id')->filter()->unique();

            foreach ($entryIds as $entryId) {
                $entry = JournalEntry::withoutGlobalScopes()->find($entryId);

                if ($entry && $entry->status === JournalEntry::STATUS_POSTED) {
                    $this->journalEntryService->reverse(
                        $entry,
                        $actor,
                        "Reversal — MTL settlement deleted ({$settlement->settlement_date})"
                    );
                }
            }

            MtlMovement::withoutGlobalScopes()
                ->where('mtl_settlement_id', $settlement->id)
                ->update(['status' => MtlMovement::STATUS_REVERSED]);

            AuditLog::record($actor, 'mtl_settlement.deleted', $settlement, $settlement->toArray(), null);
            $settlement->delete();
        });
    }

    // ══════════════════════════════════════════════════════════════
    //  Accounting — TRS-002
    // ══════════════════════════════════════════════════════════════

    /**
     * Dr loan liability (principal) and Dr interest expense, Cr the
     * bank account the money left.
     */
    private function postSettlement(MtlScheduleLine $line, MtlSettlement $settlement, User $actor): void
    {
        $facility = CreditFacility::withoutGlobalScopes()->findOrFail($line->credit_facility_id);

        [$principal, $interest] = $this->splitSettlement($line, (string) $settlement->settlement_amount);

        $date = $settlement->settlement_date instanceof \DateTimeInterface
            ? $settlement->settlement_date->toDateString()
            : (string) $settlement->settlement_date;

        $movements = new Collection();

        // A zero half posts nothing. An interest-free loan, or an
        // instalment that is pure interest, is a real shape — and an
        // empty journal line is not a record of it.
        if (bccomp($principal, '0', 2) > 0) {
            $movements->push(MtlMovement::create([
                'company_id'             => $line->company_id,
                'credit_facility_id'     => $facility->id,
                'mtl_schedule_line_id'   => $line->id,
                'mtl_settlement_id'      => $settlement->id,
                'movement_type'          => MtlMovement::TYPE_PRINCIPAL_REPAYMENT,
                'movement_date'          => $date,
                'debit_coa_account_id'   => $this->resolveAccount($line->company_id, self::LOAN_LIABILITY_CODE, 'Long-Term Loans')->id,
                'credit_bank_account_id' => $settlement->current_account_id,
                'amount'                 => $principal,
                'currency_id'            => $facility->currency_id,
                'description'            => "Loan principal repaid — {$facility->name}",
                'created_by'             => $actor->id,
            ]));
        }

        if (bccomp($interest, '0', 2) > 0) {
            $movements->push(MtlMovement::create([
                'company_id'             => $line->company_id,
                'credit_facility_id'     => $facility->id,
                'mtl_schedule_line_id'   => $line->id,
                'mtl_settlement_id'      => $settlement->id,
                'movement_type'          => MtlMovement::TYPE_INTEREST_EXPENSE,
                'movement_date'          => $date,
                'debit_coa_account_id'   => $this->resolveAccount($line->company_id, self::INTEREST_EXPENSE_CODE, 'Interest on Loans')->id,
                'credit_bank_account_id' => $settlement->current_account_id,
                'amount'                 => $interest,
                'currency_id'            => $facility->currency_id,
                'description'            => "Loan interest paid — {$facility->name}",
                'created_by'             => $actor->id,
            ]));
        }

        if ($movements->isEmpty()) {
            return;
        }

        $entry = $this->postMovements(
            $facility,
            $movements,
            "Medium-Term Loan instalment settled — {$facility->name}",
            $actor,
            $date,
            'mtl_settlement',
            $settlement->id,
        );

        // CashVero parity — ONLY the principal reduces what the loan
        // has drawn, and so only the principal replenishes the room
        // available to draw again. Interest is a cost of carrying the
        // debt, not a repayment of it; it is recorded on the ledger
        // (551 above) and never touches the facility balance.
        //
        // Skipped when the facility has nothing outstanding — an
        // 'existing' loan was drawn before this system and has no
        // drawdown here to pay down, which is CashVero's own
        // hasDrawdowns() guard.
        if ($entry && bccomp($principal, '0', 2) > 0
            && bccomp((string) $facility->fresh()->outstanding_balance, '0', 2) > 0) {

            $outstanding = (string) $facility->fresh()->outstanding_balance;

            // Never settle more than was actually drawn: a schedule can
            // run ahead of what the company chose to draw.
            $applied = bccomp($principal, $outstanding, 2) > 0 ? $outstanding : $principal;

            $this->settlementEngine->recordSettlement($facility->fresh(), [
                'bank_account_id'  => $settlement->current_account_id,
                'settlement_date'  => $date,
                'amount'           => $applied,
                'source_type'      => FacilitySettlement::SOURCE_MTL_SETTLEMENT,
                'source_id'        => $settlement->id,
                'journal_entry_id' => $entry->id,
            ], $actor);
        }
    }

    // ── Audit M22 — month-end figures ────────────────────────────────

    /**
     * Interest EARNED by the bank up to $asOf but not yet paid, in the
     * loan's own currency — the amount to accrue at month-end.
     *
     * Per schedule line (the interest is for the period ending on its
     * due date; the period starts on the previous line's due date, or
     * one month earlier for the first line):
     *   • due on/before $asOf      → all of its interest is earned
     *   • period straddles $asOf   → pro-rata by days (calendar days)
     *   • period starts after it   → nothing yet
     * less whatever interest settlements (dated on/before $asOf) have
     * already paid on that line (interest is paid first — the
     * owner-confirmed split rule). Never below zero.
     */
    public function accruedInterestAsOf(CreditFacility $facility, string $asOf): string
    {
        $lines = MtlScheduleLine::withoutGlobalScopes()
            ->where('credit_facility_id', $facility->id)
            ->orderBy('due_date')->orderBy('sort_order')->get();

        $total   = '0.00';
        $prevDue = null;

        foreach ($lines as $line) {
            $due      = $line->due_date->copy()->startOfDay();
            $interest = (string) $line->interest_amount;
            $start    = $prevDue ?? $due->copy()->subMonth();
            $prevDue  = $due;

            $asOfDay = \Carbon\Carbon::parse($asOf)->startOfDay();

            if ($due->lte($asOfDay)) {
                $earned = $interest;
            } elseif ($start->lt($asOfDay)) {
                $periodDays = max(1, (int) $start->diffInDays($due));
                $elapsed    = (int) $start->diffInDays($asOfDay);
                $earned     = bcadd(bcdiv(bcmul($interest, (string) $elapsed, 8), (string) $periodDays, 8), '0.005', 8);
                $earned     = bcadd($earned, '0', 2);
            } else {
                continue;
            }

            $paid = (string) $line->settlements()->whereDate('settlement_date', '<=', $asOf)->sum('settlement_amount');
            $open = bcsub($earned, $this->interestPaidFor($paid, $interest), 2);

            if (bccomp($open, '0', 2) > 0) {
                $total = bcadd($total, $open, 2);
            }
        }

        return $total;
    }

    /**
     * Principal due within 12 months of $asOf that is still unpaid, in
     * the loan's own currency — the amount that belongs under Current
     * Portion of Long-Term Loans (225) rather than Long-Term Loans (211).
     *
     * Includes instalments already past due and unpaid. For a NEW loan
     * (drawn through this system) it can never exceed what has actually
     * been drawn and not yet repaid — a schedule can run ahead of the
     * drawdowns.
     */
    public function currentPortionAsOf(CreditFacility $facility, string $asOf): string
    {
        $limit = \Carbon\Carbon::parse($asOf)->addMonths(12)->toDateString();

        $lines = MtlScheduleLine::withoutGlobalScopes()
            ->where('credit_facility_id', $facility->id)
            ->whereDate('due_date', '<=', $limit)
            ->get();

        $total = '0.00';
        foreach ($lines as $line) {
            $paid = (string) $line->settlements()->whereDate('settlement_date', '<=', $asOf)->sum('settlement_amount');
            $open = bcsub(
                (string) $line->principal_amount,
                $this->principalPaidFor($paid, (string) $line->interest_amount, (string) $line->principal_amount),
                2
            );
            if (bccomp($open, '0', 2) > 0) {
                $total = bcadd($total, $open, 2);
            }
        }

        if ($facility->entry_type === 'new') {
            $drawn = (string) $facility->outstanding_balance;
            if (bccomp($total, $drawn, 2) > 0) {
                $total = $drawn;
            }
        }

        return bccomp($total, '0', 2) > 0 ? $total : '0.00';
    }

    /**
     * This settlement's share of the instalment's principal and
     * interest.
     *
     * SPLIT RULE — INTEREST FIRST, taken from CashVero, where the
     * project owner settled it on 2026-08-16 and
     * LoanScheduleSettlement::principlePaidFor() implements it:
     *
     *     principlePaid(x) = max(0, min(x - interest, principal))
     *     interestPaid(x)  = max(0, min(x, interest))
     *
     * A payment covers the instalment's interest in full before any
     * of it reaches principal. On a 100,000 instalment of 60,000
     * interest + 40,000 principal, paying 50,000 is ALL interest and
     * repays no principal at all; paying 80,000 is 60,000 interest
     * and 20,000 principal.
     *
     * This settlement's own share is the difference between the two
     * running totals — what the split says after this payment, less
     * what it said before it. Computing it as a delta rather than
     * per-payment is what makes a sequence of partial payments add up
     * to exactly the instalment's own principal and interest, and it
     * is how CashVero does it too.
     *
     * (An earlier version of this method split pro-rata. That was
     * wrong: the decision had already been made, and the two rules
     * disagree on every partial payment.)
     */
    private function splitSettlement(MtlScheduleLine $line, string $amount): array
    {
        $interest  = (string) $line->interest_amount;
        $principal = (string) $line->principal_amount;

        // Everything settled on this line BEFORE this payment.
        $paidBefore = bcsub($line->settledAmount(), $amount, 2);
        $paidAfter  = bcadd($paidBefore, $amount, 2);

        $principalShare = bcsub(
            $this->principalPaidFor($paidAfter, $interest, $principal),
            $this->principalPaidFor($paidBefore, $interest, $principal),
            2
        );

        $interestShare = bcsub(
            $this->interestPaidFor($paidAfter, $interest),
            $this->interestPaidFor($paidBefore, $interest),
            2
        );

        return [$principalShare, $interestShare];
    }

    /** max(0, min(paid - interest, principal)) — see splitSettlement(). */
    private function principalPaidFor(string $paidSoFar, string $interest, string $principal): string
    {
        $afterInterest = bcsub($paidSoFar, $interest, 2);
        $capped = bccomp($afterInterest, $principal, 2) > 0 ? $principal : $afterInterest;

        return bccomp($capped, '0', 2) < 0 ? '0.00' : $capped;
    }

    /** max(0, min(paid, interest)) — see splitSettlement(). */
    private function interestPaidFor(string $paidSoFar, string $interest): string
    {
        $capped = bccomp($paidSoFar, $interest, 2) > 0 ? $interest : $paidSoFar;

        return bccomp($capped, '0', 2) < 0 ? '0.00' : $capped;
    }

    /**
     * Turns movements into one journal entry and marks them posted —
     * the same shape as LgIssuanceService::postMovementsAsJournalEntry().
     */
    private function postMovements(CreditFacility $facility, Collection $movements, string $narration, User $actor, string $entryDate, string $sourceType, int $sourceId): ?JournalEntry
    {
        $postable = $movements->filter(fn (MtlMovement $m) => $m->hasPostableLegs());

        foreach ($movements->reject(fn (MtlMovement $m) => $m->hasPostableLegs()) as $memo) {
            $memo->update(['status' => MtlMovement::STATUS_MEMO]);
        }

        if ($postable->isEmpty()) {
            return null;
        }

        // Audit C8 FIX (Sept 2026): instalments are in the LOAN's currency
        // and used to be posted 1:1 into the EGP ledger. Now converted at
        // that currency's rate on the settlement date (FxService — refuses
        // if the rate is missing). Any remaining difference between the
        // EGP at which 211 was drawn and at which it is repaid is a
        // month-end FX revaluation matter (separate audit item).
        $fx = app(\App\Services\Accounting\FxService::class);
        $lines = [];
        foreach ($postable as $movement) {
            $debitId  = $movement->debit_coa_account_id  ?? $this->resolveBankCoaId($movement->debit_bank_account_id);
            $creditId = $movement->credit_coa_account_id ?? $this->resolveBankCoaId($movement->credit_bank_account_id);

            $movementRate = $fx->rateOn($facility->company_id, $movement->currency_id ? (int) $movement->currency_id : null, $entryDate);
            $amount = $fx->toFunctional($movement->amount, $movementRate);

            // Audit H12/H15: each line keeps the movement's exact foreign amount.
            $fxTag = $movement->currency_id ? ['currency_id' => (int) $movement->currency_id, 'amount_currency' => (string) $movement->amount, 'exchange_rate' => $movementRate] : [];
            $lines[] = ['chart_of_account_id' => $debitId,  'debit' => $amount, 'credit' => '0.00', 'description' => $movement->description] + $fxTag;
            $lines[] = ['chart_of_account_id' => $creditId, 'debit' => '0.00', 'credit' => $amount, 'description' => $movement->description] + $fxTag;
        }

        $entry = $this->journalEntryService->post(
            companyId:  $facility->company_id,
            entryDate:  $entryDate,
            lines:      $lines,
            // Two distinct source types on purpose: a drawdown and a
            // repayment are different events, and a ledger that says
            // which one it is can be read without joining back to the
            // module. sourceId points at the facility for a drawdown
            // and at the settlement for a repayment, so each entry
            // names the exact row it came from.
            sourceType: $sourceType,
            sourceId:   $sourceId,
            narration:  $narration,
            actor:      $actor,
        );

        MtlMovement::withoutGlobalScopes()->whereIn('id', $postable->pluck('id'))->update([
            'status'                  => MtlMovement::STATUS_POSTED,
            'posted_at'               => now(),
            'posted_journal_entry_id' => $entry->id,
        ]);

        return $entry;
    }

    private function resolveAccount(int $companyId, string $code, string $label): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, $code)
            ?? throw new \DomainException("Chart of Accounts is missing system account {$code} ({$label}) — check the seeder.");
    }

    private function resolveBankCoaId(?int $bankAccountId): int
    {
        if (! $bankAccountId) {
            throw new \DomainException('An MTL movement is missing its bank account leg — cannot post to the GL.');
        }

        $bankAccount = BankAccount::withoutGlobalScopes()->find($bankAccountId);

        if (! $bankAccount || ! $bankAccount->coa_account_id) {
            throw new \DomainException('The bank account on this MTL movement is missing its Chart of Accounts link.');
        }

        return $bankAccount->coa_account_id;
    }

    /** Round half up — same convention as StockLedgerService (INV-005). */
    private function bcround(string $number, int $scale = 2): string
    {
        $halfUnit = '0.'.str_repeat('0', $scale).'5';

        return bccomp($number, '0', $scale + 10) >= 0
            ? bcadd($number, $halfUnit, $scale)
            : bcsub($number, $halfUnit, $scale);
    }
}
