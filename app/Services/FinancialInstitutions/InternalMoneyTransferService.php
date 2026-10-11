<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  InternalMoneyTransferService
//
//  Moves cash between two of the company's own accounts — Bank
//  (a BankAccount's linked COA account) or Safe (a 131xxx COA
//  sub-account directly — no separate Safe/Branch entity, confirmed
//  by Mahmoud) — in any of four leg combinations.
//
//  ONE ATOMIC ACTION for an instant transfer — record() posts
//  immediately, no draft-then-post step, same shape as Customer/
//  Supplier Payment.
//
//  TWO-STAGE for an in-transit transfer (transfer_days > 0), same
//  lifecycle shape as a Cheque:
//    record()            → Dr 145 Cash in Transit / Cr [source]
//    confirmReceived()   → Dr [destination] / Cr 145
//    cancel() (while in_transit) → reverses the initiate entry
//
//  Functional pattern re-architected from CashVero's own
//  internal_money_transfers (see CashVero → Vero CFO Treasury Gap
//  Analysis, Section 1.10) — the in-transit concept (transfer_days)
//  is worth keeping; everything else here (real journal entries, COA
//  145 as the clearing account, company scoping, bcmath) is new,
//  since CashVero has no GL at all.
//
//  AUDIT M28 FIX (Oct 2026) — bank and safe legs must hold the
//  currency being moved, and a hand-typed exchange rate must stay
//  within 5% of the system rate (see ChecksLegCurrency).
//
//  AUDIT C8 FIX (Sept 2026) — a foreign-currency transfer with no
//  rate typed fell back to a rate of 1, so USD 10,000 moved as EGP
//  10,000. The rate now comes from FxService: the rate typed on the
//  transfer if any, otherwise the rate table on the transfer date
//  (refused if missing); EGP transfers always use 1. The
//  insufficient-funds check now compares the EGP value with the EGP
//  ledger balance (it compared the foreign amount).
//
//  @layer    Service
//  @depends  FxService, InternalMoneyTransfer, BankAccount, ChartOfAccount,
//            JournalEntryService, FiscalYearService, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\FinancialInstitutions\FacilitySettlement;
use App\Models\FinancialInstitutions\FacilityWithdrawal;
use App\Models\FinancialInstitutions\InternalMoneyTransfer;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use App\Services\Accounting\CashAvailabilityService;
use App\Services\FinancialInstitutions\FacilitySettlementEngine;
use App\Services\FiscalYearService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InternalMoneyTransferService
{
    use \App\Services\FinancialInstitutions\Concerns\ChecksLegCurrency;

    public function __construct(
        private JournalEntryService $journalEntryService,
        private FiscalYearService $fiscalYearService,
        private FacilitySettlementEngine $settlementEngine,
        private CashAvailabilityService $cashAvailability,
    ) {}

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return InternalMoneyTransfer::with(['fromBankAccount.bank', 'fromSafeAccount', 'toBankAccount.bank', 'toSafeAccount', 'currency'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('transfer_code', 'like', "%{$s}%"))
            ->orderByDesc('transfer_date')
            ->paginate(20)
            ->withQueryString();
    }

    public function record(int $companyId, array $data, User $actor): InternalMoneyTransfer
    {
        $this->assertLegsValid($data);
        $this->assertLegCurrenciesAndRate($companyId, $data); // Audit M28

        if ($this->fiscalYearService->isPeriodClosed($companyId, (string) $data['transfer_date'])) {
            throw new \DomainException('This accounting period is closed — the transfer cannot be posted.');
        }

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $exchangeRate = app(\App\Services\Accounting\FxService::class)->resolveRate(
                $companyId,
                isset($data['currency_id']) ? (int) $data['currency_id'] : null,
                (string) $data['transfer_date'],
                $data['exchange_rate'] ?? null
            );
            $amountFunctional = bcmul((string) $data['amount'], $exchangeRate, 2);
            $transferDays = (int) ($data['transfer_days'] ?? 0);

            $transfer = InternalMoneyTransfer::create([
                'company_id'            => $companyId,
                'transfer_code'         => $this->generateTransferCode($companyId, $data['transfer_date'] ?? null),
                'from_leg_type'         => $data['from_leg_type'],
                'from_bank_account_id'  => $data['from_leg_type'] === InternalMoneyTransfer::LEG_BANK ? $data['from_bank_account_id'] : null,
                'from_safe_account_id'  => $data['from_leg_type'] === InternalMoneyTransfer::LEG_SAFE ? $data['from_safe_account_id'] : null,
                'from_credit_facility_id' => $data['from_leg_type'] === InternalMoneyTransfer::LEG_FACILITY ? $data['from_credit_facility_id'] : null,
                'to_leg_type'           => $data['to_leg_type'],
                'to_bank_account_id'    => $data['to_leg_type'] === InternalMoneyTransfer::LEG_BANK ? $data['to_bank_account_id'] : null,
                'to_safe_account_id'    => $data['to_leg_type'] === InternalMoneyTransfer::LEG_SAFE ? $data['to_safe_account_id'] : null,
                'to_credit_facility_id' => $data['to_leg_type'] === InternalMoneyTransfer::LEG_FACILITY ? $data['to_credit_facility_id'] : null,
                'currency_id'           => $data['currency_id'],
                'amount'                => $data['amount'],
                'amount_functional'     => $amountFunctional,
                'exchange_rate'         => $exchangeRate,
                'transfer_date'         => $data['transfer_date'],
                'transfer_days'         => $transferDays,
                'expected_clearing_date' => $transferDays > 0 ? date('Y-m-d', strtotime($data['transfer_date'].(" +{$transferDays} days"))) : null,
                'status'                => $transferDays > 0 ? InternalMoneyTransfer::STATUS_IN_TRANSIT : InternalMoneyTransfer::STATUS_CLEARED,
                'cleared_date'          => $transferDays > 0 ? null : $data['transfer_date'],
                'comment'               => $data['comment'] ?? null,
                'created_by'            => $actor->id,
            ]);

            $sourceAccount = $this->resolveLegAccount($transfer, 'from');

            // Insufficient-funds guard (see CashAvailabilityService
            // header) — only for a bank or safe leg; a from-facility
            // leg's own available-room check happens inside
            // recordFacilityWithdrawal() below.
            if (in_array($transfer->from_leg_type, [InternalMoneyTransfer::LEG_BANK, InternalMoneyTransfer::LEG_SAFE], true)) {
                $this->cashAvailability->assertAccountCanCover(
                    $companyId, $sourceAccount->id, $amountFunctional, $sourceAccount->name_en
                );
            }

            if ($transferDays > 0) {
                $transitAccount = $this->resolveCashInTransitAccount($companyId);

                $entry = $this->journalEntryService->post(
                    companyId: $companyId,
                    entryDate: $transfer->transfer_date,
                    lines: app(\App\Services\Accounting\FxService::class)->tagLines([
                        ['chart_of_account_id' => $transitAccount->id, 'debit' => $amountFunctional, 'credit' => '0', 'description' => 'Internal Transfer '.$transfer->transfer_code.' — in transit'],
                        ['chart_of_account_id' => $sourceAccount->id,  'debit' => '0', 'credit' => $amountFunctional, 'description' => 'Internal Transfer '.$transfer->transfer_code.' — in transit'],
                    ], $transfer->currency_id ? (int) $transfer->currency_id : null, $transfer->exchange_rate), // Audit H12/H15
                    sourceType: 'internal_money_transfer',
                    sourceId: $transfer->id,
                    narration: "Internal Transfer {$transfer->transfer_code} — initiated, expected clearing {$transfer->expected_clearing_date->toDateString()}",
                    actor: $actor,
                );

                $transfer->update(['journal_entry_id' => $entry->id]);

                // Facility Settlement Engine — the source leg always
                // posts immediately (whether instant or in-transit),
                // so a from-facility drawdown is recorded right here,
                // now that the entry it references actually exists.
                if ($transfer->from_leg_type === InternalMoneyTransfer::LEG_FACILITY) {
                    $this->recordFacilityWithdrawal($transfer, $entry->id, $actor);
                }
            } else {
                $destinationAccount = $this->resolveLegAccount($transfer, 'to');

                $entry = $this->journalEntryService->post(
                    companyId: $companyId,
                    entryDate: $transfer->transfer_date,
                    lines: app(\App\Services\Accounting\FxService::class)->tagLines([
                        ['chart_of_account_id' => $destinationAccount->id, 'debit' => $amountFunctional, 'credit' => '0', 'description' => 'Internal Transfer '.$transfer->transfer_code],
                        ['chart_of_account_id' => $sourceAccount->id,      'debit' => '0', 'credit' => $amountFunctional, 'description' => 'Internal Transfer '.$transfer->transfer_code],
                    ], $transfer->currency_id ? (int) $transfer->currency_id : null, $transfer->exchange_rate), // Audit H12/H15
                    sourceType: 'internal_money_transfer',
                    sourceId: $transfer->id,
                    narration: "Internal Transfer {$transfer->transfer_code}",
                    actor: $actor,
                );

                $transfer->update(['journal_entry_id' => $entry->id]);

                if ($transfer->from_leg_type === InternalMoneyTransfer::LEG_FACILITY) {
                    $this->recordFacilityWithdrawal($transfer, $entry->id, $actor);
                }

                // Instant transfer — the destination leg posts right
                // here, in the same entry above, so a to-facility
                // settlement is recorded at this exact moment too.
                if ($transfer->to_leg_type === InternalMoneyTransfer::LEG_FACILITY) {
                    $this->recordFacilitySettlement($transfer, $transfer->transfer_date, $entry->id, $actor);
                }
            }

            AuditLog::record($actor, 'internal_money_transfer.recorded', $transfer, null, $transfer->fresh()->toArray());

            return $transfer->fresh(['fromBankAccount', 'fromSafeAccount', 'toBankAccount', 'toSafeAccount', 'currency', 'journalEntry.lines']);
        });
    }

    /**
     * Confirms an in-transit transfer has actually landed in the
     * destination account — the second and final leg. Never automatic
     * (no scheduled job watching expected_clearing_date) — matches
     * how every other status change in this app is a real user action,
     * not a silent background assumption that money arrived on time.
     */
    public function confirmReceived(InternalMoneyTransfer $transfer, User $actor, ?string $clearedDate = null): InternalMoneyTransfer
    {
        if (! $transfer->isInTransit()) {
            throw new \DomainException('Only an in-transit transfer can be confirmed as received.');
        }

        $clearedDate = $clearedDate ?: now()->toDateString();

        if ($this->fiscalYearService->isPeriodClosed($transfer->company_id, $clearedDate)) {
            throw new \DomainException('This accounting period is closed — the transfer cannot be confirmed.');
        }

        return DB::transaction(function () use ($transfer, $actor, $clearedDate) {
            $before = $transfer->toArray();

            $transitAccount = $this->resolveCashInTransitAccount($transfer->company_id);
            $destinationAccount = $this->resolveLegAccount($transfer, 'to');

            $entry = $this->journalEntryService->post(
                companyId: $transfer->company_id,
                entryDate: $clearedDate,
                lines: app(\App\Services\Accounting\FxService::class)->tagLines([
                    ['chart_of_account_id' => $destinationAccount->id, 'debit' => (string) $transfer->amount_functional, 'credit' => '0', 'description' => 'Internal Transfer '.$transfer->transfer_code.' — received'],
                    ['chart_of_account_id' => $transitAccount->id,     'debit' => '0', 'credit' => (string) $transfer->amount_functional, 'description' => 'Internal Transfer '.$transfer->transfer_code.' — received'],
                ], $transfer->currency_id ? (int) $transfer->currency_id : null, $transfer->exchange_rate), // Audit H12/H15
                sourceType: 'internal_money_transfer',
                sourceId: $transfer->id,
                narration: "Internal Transfer {$transfer->transfer_code} — confirmed received",
                actor: $actor,
            );

            $transfer->update([
                'status'                    => InternalMoneyTransfer::STATUS_CLEARED,
                'cleared_date'              => $clearedDate,
                'clearing_journal_entry_id' => $entry->id,
                'updated_by'                => $actor->id,
            ]);

            // Facility Settlement Engine — the destination leg only
            // actually posts NOW for an in-transit transfer, so a
            // to-facility settlement is only recorded here, never at
            // record() time, matching exactly when the money really
            // lands.
            if ($transfer->to_leg_type === InternalMoneyTransfer::LEG_FACILITY) {
                $this->recordFacilitySettlement($transfer, $clearedDate, $entry->id, $actor);
            }

            AuditLog::record($actor, 'internal_money_transfer.confirmed_received', $transfer, $before, $transfer->fresh()->toArray());

            return $transfer->fresh();
        });
    }

    /**
     * Cancels a transfer while it's still in transit — reverses the
     * initiate entry, cash returns to the source account. A CLEARED
     * transfer cannot be cancelled here (both legs are real,
     * completed movements by then) — correct it with a new transfer
     * back, same as any other posted, completed transaction in this
     * app.
     */
    public function cancel(InternalMoneyTransfer $transfer, User $actor): InternalMoneyTransfer
    {
        if (! $transfer->isInTransit()) {
            throw new \DomainException('Only an in-transit transfer can be cancelled — a cleared transfer is complete on both sides.');
        }

        return DB::transaction(function () use ($transfer, $actor) {
            $before = $transfer->toArray();

            // Facility Settlement Engine — cancel() is only reachable
            // while in_transit, and a to-facility settlement is never
            // created until confirmReceived() clears the transfer, so
            // the only thing that could exist here is a from-facility
            // withdrawal (the source leg posts immediately in
            // record(), regardless of transfer_days).
            if ($transfer->from_leg_type === InternalMoneyTransfer::LEG_FACILITY) {
                $withdrawal = FacilityWithdrawal::where('source_type', FacilityWithdrawal::SOURCE_INTERNAL_MONEY_TRANSFER)
                    ->where('source_id', $transfer->id)
                    ->first();

                if ($withdrawal) {
                    $this->settlementEngine->reverseWithdrawal($withdrawal, $actor);
                }
            }

            if ($transfer->journal_entry_id) {
                $entry = JournalEntry::findOrFail($transfer->journal_entry_id);
                $this->journalEntryService->reverse($entry, $actor, 'Cancelled — '.$transfer->transfer_code);
            }

            $transfer->update([
                'status'     => InternalMoneyTransfer::STATUS_CANCELLED,
                'updated_by' => $actor->id,
            ]);

            AuditLog::record($actor, 'internal_money_transfer.cancelled', $transfer, $before, $transfer->fresh()->toArray());

            return $transfer->fresh();
        });
    }

    // ── Private ────────────────────────────────────────────────────

    /**
     * Audit M28 — a transfer moves ONE currency. Bank and safe legs must
     * hold that currency (a facility leg is checked against its own
     * currency further down), and a hand-typed exchange rate must stay
     * within 5% of the system rate for the transfer date.
     */
    private function assertLegCurrenciesAndRate(int $companyId, array $data): void
    {
        $currencyId = (int) ($data['currency_id'] ?? 0);
        if ($currencyId <= 0) {
            return;
        }

        foreach (['from', 'to'] as $side) {
            $this->assertLegCurrency(
                $companyId,
                $side,
                (string) $data["{$side}_leg_type"],
                isset($data["{$side}_bank_account_id"]) ? (int) $data["{$side}_bank_account_id"] : null,
                isset($data["{$side}_safe_account_id"]) ? (int) $data["{$side}_safe_account_id"] : null,
                $currencyId
            );
        }

        $fx = app(\App\Services\Accounting\FxService::class);
        if (! empty($data['exchange_rate']) && ! $fx->isFunctional($companyId, $currencyId)) {
            $system = \App\Models\ExchangeRate::rateFor($companyId, $currencyId, substr((string) $data['transfer_date'], 0, 10));
            $this->assertRateWithinTolerance((string) $data['exchange_rate'], $system, 'transfer');
        }
    }

    private function assertLegsValid(array $data): void
    {
        foreach (['from', 'to'] as $side) {
            $legType = $data["{$side}_leg_type"] ?? null;

            if (! in_array($legType, [InternalMoneyTransfer::LEG_BANK, InternalMoneyTransfer::LEG_SAFE, InternalMoneyTransfer::LEG_FACILITY], true)) {
                throw new \DomainException("Invalid {$side} leg type.");
            }
            if ($legType === InternalMoneyTransfer::LEG_BANK && empty($data["{$side}_bank_account_id"])) {
                throw new \DomainException(ucfirst($side)." bank account is required.");
            }
            if ($legType === InternalMoneyTransfer::LEG_SAFE && empty($data["{$side}_safe_account_id"])) {
                throw new \DomainException(ucfirst($side)." safe account is required.");
            }
            if ($legType === InternalMoneyTransfer::LEG_FACILITY && empty($data["{$side}_credit_facility_id"])) {
                throw new \DomainException(ucfirst($side)." facility is required.");
            }
        }

        if ($data['from_leg_type'] === InternalMoneyTransfer::LEG_FACILITY
            && $data['to_leg_type'] === InternalMoneyTransfer::LEG_FACILITY
            && (int) $data['from_credit_facility_id'] === (int) $data['to_credit_facility_id']
        ) {
            throw new \DomainException('Source and destination cannot be the same facility.');
        }

        if (
            $data['from_leg_type'] === InternalMoneyTransfer::LEG_BANK
            && $data['to_leg_type'] === InternalMoneyTransfer::LEG_BANK
            && (int) $data['from_bank_account_id'] === (int) $data['to_bank_account_id']
        ) {
            throw new \DomainException('Source and destination cannot be the same bank account.');
        }
        if (
            $data['from_leg_type'] === InternalMoneyTransfer::LEG_SAFE
            && $data['to_leg_type'] === InternalMoneyTransfer::LEG_SAFE
            && (int) $data['from_safe_account_id'] === (int) $data['to_safe_account_id']
        ) {
            throw new \DomainException('Source and destination cannot be the same safe account.');
        }
    }

    /**
     * Facility Settlement Engine — resolves the facility from
     * whichever leg is 'facility', locks it, and records a drawdown.
     * Called from both branches of record() right after that
     * branch's own journal entry is posted, so journal_entry_id
     * always points at a real, already-existing entry.
     */
    private function recordFacilityWithdrawal(InternalMoneyTransfer $transfer, int $journalEntryId, User $actor): void
    {
        $facility = CreditFacility::lockForUpdate()->findOrFail($transfer->from_credit_facility_id);

        if (! $facility->bank_account_id) {
            throw new \DomainException('This facility has no linked bank account — cannot record a drawdown against it.');
        }

        // Currency bug fix (2026-09) — see the matching note in
        // SupplierPaymentService::postPaymentTransaction(). An Internal
        // Transfer moves ONE currency between two accounts — it never
        // converts — so transfer->currency_id must equal the facility's
        // own currency, and the NATIVE amount (not amount_functional) is
        // what belongs in a balance tracked in that same currency.
        if ((int) $transfer->currency_id !== (int) $facility->currency_id) {
            throw new \DomainException(
                "This transfer's currency doesn't match the facility's own currency — ".
                'an Internal Transfer moves one currency only, it cannot convert.'
            );
        }

        $this->settlementEngine->recordWithdrawal($facility, [
            'bank_account_id'  => $facility->bank_account_id,
            'withdrawal_date'  => $transfer->transfer_date->toDateString(),
            'amount'           => (string) $transfer->amount,
            'source_type'      => FacilityWithdrawal::SOURCE_INTERNAL_MONEY_TRANSFER,
            'source_id'        => $transfer->id,
            'journal_entry_id' => $journalEntryId,
            'description'      => 'Internal Transfer '.$transfer->transfer_code,
        ], $actor);
    }

    /**
     * Facility Settlement Engine — resolves the destination facility
     * and records a settlement. Called at record() for an instant
     * transfer, or at confirmReceived() for an in-transit one —
     * whichever moment the destination leg's own journal line
     * actually posts.
     */
    private function recordFacilitySettlement(InternalMoneyTransfer $transfer, string $settlementDate, int $journalEntryId, User $actor): void
    {
        $facility = CreditFacility::lockForUpdate()->findOrFail($transfer->to_credit_facility_id);

        if (! $facility->bank_account_id) {
            throw new \DomainException('This facility has no linked bank account — cannot record a settlement against it.');
        }

        // Currency bug fix (2026-09) — see recordFacilityWithdrawal() above.
        if ((int) $transfer->currency_id !== (int) $facility->currency_id) {
            throw new \DomainException(
                "This transfer's currency doesn't match the facility's own currency — ".
                'an Internal Transfer moves one currency only, it cannot convert.'
            );
        }

        $this->settlementEngine->recordSettlement($facility, [
            'bank_account_id'  => $facility->bank_account_id,
            'settlement_date'  => $settlementDate,
            'amount'           => (string) $transfer->amount,
            'source_type'      => FacilitySettlement::SOURCE_INTERNAL_MONEY_TRANSFER,
            'source_id'        => $transfer->id,
            'journal_entry_id' => $journalEntryId,
        ], $actor);
    }

    private function resolveLegAccount(InternalMoneyTransfer $transfer, string $side): ChartOfAccount
    {
        $legType = $transfer->{"{$side}_leg_type"};

        if ($legType === InternalMoneyTransfer::LEG_FACILITY) {
            $facility = CreditFacility::where('id', $transfer->{"{$side}_credit_facility_id"})
                ->where('company_id', $transfer->company_id)->first();

            if (! $facility || ! $facility->coa_account_id) {
                throw new \DomainException('The selected facility is missing its Chart of Accounts link.');
            }

            return ChartOfAccount::findOrFail($facility->coa_account_id);
        }

        if ($legType === InternalMoneyTransfer::LEG_BANK) {
            $bankAccount = BankAccount::where('id', $transfer->{"{$side}_bank_account_id"})
                ->where('company_id', $transfer->company_id)->first();

            if (! $bankAccount || ! $bankAccount->coa_account_id) {
                throw new \DomainException('The selected bank account is missing its Chart of Accounts link.');
            }

            return ChartOfAccount::findOrFail($bankAccount->coa_account_id);
        }

        $account = ChartOfAccount::withoutGlobalScopes()->forCompany($transfer->company_id)
            ->where('id', $transfer->{"{$side}_safe_account_id"})->first();

        return $account ?? throw new \DomainException('The selected safe account does not belong to this company.');
    }

    private function resolveCashInTransitAccount(int $companyId): ChartOfAccount
    {
        return ChartOfAccount::findByCode($companyId, '145')
            ?? throw new \DomainException('Chart of Accounts is missing system account 145 (Cash in Transit) — check the seeder.');
    }

    private function generateTransferCode(int $companyId, \Carbon\Carbon|string|null $date = null): string
    {
        // Audit L-7: atomic counter, month follows the document date.
        return \App\Support\DocumentNumber::code($companyId, \App\Models\FinancialInstitutions\InternalMoneyTransfer::class, 'transfer_code', 'IMT', $date);
    }
}
