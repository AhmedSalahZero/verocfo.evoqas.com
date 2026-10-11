<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  BankService
//
//  Covers Bank master + Bank Accounts + Credit Facilities.
//  Three separate concerns, one service — they are always used together.
//
//  CreditFacility for facility_type = contract_so:
//    validateCollateral() enforces Rule 30 — end_date must exceed review_date.
//
//  v1.1 — paginateFacilities() eager-loaded currency via the
//    shorthand 'currency:id,code', which silently re-applies
//    Currency's global CompanyScope and drops every system
//    currency (company_id = NULL) from the relation — i.e. almost
//    all of them. Loads it through a closure with
//    withoutGlobalScopes() instead. Same root cause and fix as
//    CleanOverdraftService::paginate() and
//    BankController::accountsIndex().
//
//  v1.2 — Bank Account build:
//    - createBankAccounts() added — repeater-aware, creates several
//      accounts for one bank in a single transaction (e.g. opening
//      multiple currency accounts at once).
//    - updateBankAccount() now writes to bank_account_terms_history
//      whenever interest_rate or minimum_balance_for_interest
//      actually changes — banks change these terms over time and
//      the history is preserved, not overwritten. Same pattern as
//      PartnerService::trackEmployeeChanges().
//    - hasFunctionalCurrencyCurrentAccount() + functionalCurrencyId()
//      added — used by paginateBanks() to flag (soft warning only,
//      not blocking) banks with no active current account in the
//      company's functional currency.
//
//  @layer    Service
//  @depends  Bank, BankAccount, BankAccountTermsHistory, CreditFacility, Project, SalesOrder, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Exceptions\FinancialInstitutions\ExpiredCollateralException;
use App\Exceptions\FinancialInstitutions\InactiveSalesOrderCollateralException;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\CompanyActiveCurrency;
use App\Models\FinancialInstitutions\Bank;
use App\Models\FinancialInstitutions\BankAccount;
use App\Models\FinancialInstitutions\BankAccountTermsHistory;
use App\Models\FinancialInstitutions\CreditFacility;
use App\Models\Project;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Accounting\JournalEntryService;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class BankService
{
    public function __construct(private JournalEntryService $journalEntryService)
    {
    }

    // ── Banks ─────────────────────────────────────────────────────

    public function paginateBanks(int $companyId, array $filters = []): LengthAwarePaginator
    {
        $functionalCurrencyId = $this->functionalCurrencyId($companyId);

        $banks = Bank::withCount('accounts', 'creditFacilities')
            ->when($filters['search'] ?? null, fn ($q, $s) =>
                $q->where(fn ($w) => $w->where('name_en', 'like', "%{$s}%")->orWhere('name_ar', 'like', "%{$s}%")) // Audit L-18
            )
            ->orderBy('name_en')
            ->paginate(20)
            ->withQueryString();

        // Soft validation flag only — never blocks anything. Computed
        // per-page (max 20 rows), cheap enough to do per-bank.
        $banks->getCollection()->transform(function (Bank $bank) use ($functionalCurrencyId) {
            $bank->has_functional_current_account = $functionalCurrencyId && BankAccount::withoutGlobalScopes()
                ->where('bank_id', $bank->id)
                ->where('account_type', 'current')
                ->where('currency_id', $functionalCurrencyId)
                ->where('is_active', true)
                ->exists();
            return $bank;
        });

        return $banks;
    }

    public function createBank(int $companyId, array $data, User $actor): Bank
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $data['company_id'] = $companyId;
            $data['created_by'] = $actor->id;
            $bank = Bank::create($data);
            AuditLog::record($actor, 'bank.created', $bank, null, $bank->toArray());
            return $bank;
        });
    }

    public function updateBank(Bank $bank, array $data, User $actor): Bank
    {
        return DB::transaction(function () use ($bank, $data, $actor) {
            $before = $bank->toArray();
            $bank->update($data);
            AuditLog::record($actor, 'bank.updated', $bank, $before, $bank->fresh()->toArray());
            return $bank->fresh();
        });
    }

    public function toggleBank(Bank $bank, User $actor): Bank
    {
        return DB::transaction(function () use ($bank, $actor) {
            $before = $bank->toArray();
            $bank->update(['is_active' => ! $bank->is_active]);
            AuditLog::record($actor, 'bank.toggled', $bank, $before, $bank->fresh()->toArray());
            return $bank->fresh();
        });
    }

    // ── Bank Accounts ─────────────────────────────────────────────

    public function createBankAccount(int $companyId, Bank $bank, array $data, User $actor): BankAccount
    {
        return DB::transaction(function () use ($companyId, $bank, $data, $actor) {
            $data['company_id'] = $companyId;
            $data['bank_id']    = $bank->id;
            $data['created_by'] = $actor->id;
            $data = $this->normalizeAccountData($data);
            $account = BankAccount::create($data);

            if ($account->isTimeDeposit() && $account->isNewEntry()) {
                $this->postTdOpeningEntry($account, $actor);
            }

            AuditLog::record($actor, 'bank_account.created', $account, null, $account->toArray());
            return $account->fresh(['currency', 'coaAccount']);
        });
    }

    /**
     * Time Deposit Lifecycle (July 2026): a 'new' TD's cash actually
     * moves out of a real Current account into the TD at opening —
     * same principle as the Leasing engine's 'new' entry_type. An
     * 'existing' TD (predates Vero CFO) skips this entirely, same as
     * an existing lease or an Opening Balance — nothing to post,
     * the user is just recording where things already stand.
     */
    private function postTdOpeningEntry(BankAccount $account, User $actor): void
    {
        $funding = BankAccount::where('id', $account->td_funding_bank_account_id)
            ->where('company_id', $account->company_id)
            ->where('account_type', 'current')
            ->first();

        if (! $funding) {
            throw new \DomainException('A new Time Deposit must be funded from an active Current account belonging to this company.');
        }

        $this->journalEntryService->post(
            companyId: $account->company_id,
            entryDate: $account->td_start_date ?? now(),
            lines: [
                ['chart_of_account_id' => $account->coa_account_id, 'debit' => (string) $account->td_amount, 'credit' => '0', 'description' => 'Time Deposit opened — '.$account->account_name],
                ['chart_of_account_id' => $funding->coa_account_id, 'debit' => '0', 'credit' => (string) $account->td_amount, 'description' => 'Funds moved to Time Deposit — '.$account->account_name],
            ],
            sourceType: 'bank_account_td_event',
            sourceId: null,
            narration: "Time Deposit {$account->account_name} ({$account->account_number}) opened, funded from {$funding->account_name}",
            actor: $actor,
        );
    }

    /**
     * td_amount has a DB-level default(0) but is NOT nullable. A
     * default only applies when the column is omitted from the
     * INSERT entirely — explicitly passing NULL (which happens for
     * every non-Time-Deposit account, since the form always submits
     * td_amount as an empty value) overrides that default and the
     * NOT NULL constraint rejects it. Force it to 0 here so every
     * account type can be saved regardless of what the form sends.
     */
    private function normalizeAccountData(array $data): array
    {
        $data['td_amount'] = $data['td_amount'] ?? 0;
        // M-33: column is NOT NULL (default 20) — a blank form field must not become NULL.
        $data['td_wht_rate'] = $data['td_wht_rate'] ?? 20;
        return $data;
    }

    /**
     * Repeater-aware create — multiple accounts for one bank in a
     * single transaction (e.g. opening EGP + USD current accounts
     * at the same bank in one save).
     *
     * @param  array<int, array>  $accountsData
     * @return array<int, BankAccount>
     */
    public function createBankAccounts(int $companyId, Bank $bank, array $accountsData, User $actor): array
    {
        return DB::transaction(function () use ($companyId, $bank, $accountsData, $actor) {
            $created = [];
            foreach ($accountsData as $data) {
                $created[] = $this->createBankAccount($companyId, $bank, $data, $actor);
            }
            return $created;
        });
    }

    public function updateBankAccount(BankAccount $account, array $data, User $actor): BankAccount
    {
        return DB::transaction(function () use ($account, $data, $actor) {
            $before = $account->toArray();

            $this->trackTermsChanges($account, $data, $actor);

            $data = $this->normalizeAccountData($data);
            $account->update($data);
            AuditLog::record($actor, 'bank_account.updated', $account, $before, $account->fresh()->toArray());
            return $account->fresh(['currency', 'coaAccount', 'termsHistory']);
        });
    }

    public function toggleBankAccount(BankAccount $account, User $actor): BankAccount
    {
        return DB::transaction(function () use ($account, $actor) {
            $before = $account->toArray();
            $account->update(['is_active' => ! $account->is_active]);
            AuditLog::record($actor, 'bank_account.toggled', $account, $before, $account->fresh()->toArray());
            return $account->fresh();
        });
    }

    // Moved out of BankController::accountsIndex() (July 2026 audit — was
    // 19 lines, over the controller line-length limit, and querying the
    // database directly rather than through this service).
    //
    // BUG FIX kept from the original controller code: shorthand
    // 'currency:id,code' re-applies Currency's global CompanyScope and
    // drops every system currency (company_id = NULL) — i.e. almost all
    // of them. withoutGlobalScopes() here is what actually fixes it. Same
    // root cause as CleanOverdraftService::paginate() and
    // BankService::paginateFacilities() below.
    public function paginateAccounts(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return BankAccount::with([
                'bank:id,name_en',
                'currency' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code'),
            ])
            ->when($filters['bank_id'] ?? null, fn ($q, $b) => $q->where('bank_id', $b))
            ->when(($filters['account_type'] ?? null) === 'cash_cover',
                fn ($q) => $q->whereIn('account_type', ['lg_cash_cover', 'lc_cash_cover']),
                fn ($q) => $q->when($filters['account_type'] ?? null, fn ($q, $t) => $q->where('account_type', $t)))
            ->orderBy('account_name')
            ->paginate(20)
            ->withQueryString();
    }

    /**
     * Writes a bank_account_terms_history row for interest_rate and/or
     * minimum_balance_for_interest if the incoming value differs from
     * what's currently stored. Same comparison pattern as
     * PartnerService::trackEmployeeChanges().
     */
    private function trackTermsChanges(BankAccount $account, array $data, User $actor): void
    {
        foreach (BankAccountTermsHistory::TRACKED_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $oldValue = (string) ($account->{$field} ?? '');
            $newValue = (string) ($data[$field] ?? '');

            if ($oldValue === $newValue) {
                continue;
            }

            BankAccountTermsHistory::create([
                'company_id'      => $account->company_id,
                'bank_account_id' => $account->id,
                'field'           => $field,
                'old_value'       => $oldValue ?: null,
                'new_value'       => $newValue ?: null,
                'effective_date'  => now()->toDateString(),
                'changed_by'      => $actor->id,
            ]);
        }
    }

    /**
     * Whether this bank has at least one active current account in
     * the company's functional currency. Soft check only — used for
     * a non-blocking UI warning, never enforced as validation.
     */
    public function hasFunctionalCurrencyCurrentAccount(int $companyId, int $bankId): bool
    {
        $functionalCurrencyId = $this->functionalCurrencyId($companyId);
        if (! $functionalCurrencyId) {
            return true; // nothing to warn about if functional currency isn't set up
        }

        return BankAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('bank_id', $bankId)
            ->where('account_type', 'current')
            ->where('currency_id', $functionalCurrencyId)
            ->where('is_active', true)
            ->exists();
    }

    public function functionalCurrencyId(int $companyId): ?int
    {
        return CompanyActiveCurrency::where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');
    }

    /**
     * Active currencies for this company via company_active_currencies
     * pivot. Functional currency always first, then alphabetical.
     * Same pattern as ProjectController/LeasingService::activeCurrencies().
     */
    public function activeCurrencies(int $companyId): \Illuminate\Support\Collection
    {
        $activeCurrencyIds = CompanyActiveCurrency::where('company_id', $companyId)
            ->where('is_active', true)
            ->pluck('currency_id');

        $functionalCurrencyId = $this->functionalCurrencyId($companyId);

        return \App\Models\Currency::withoutGlobalScopes()
            ->whereIn('id', $activeCurrencyIds)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$functionalCurrencyId])
            ->orderBy('code')
            ->get(['id', 'code', 'name_en']);
    }

    // ── Credit Facilities ─────────────────────────────────────────

    public function paginateFacilities(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return CreditFacility::with([
                'bank:id,name_en',
                // BUG FIX: shorthand 'currency:id,code' re-applies Currency's
                // global CompanyScope and drops every system currency
                // (company_id = NULL) — i.e. almost all of them. Same fix as
                // CleanOverdraftService::paginate().
                'currency' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'code'),
            ])
            ->when($filters['bank_id'] ?? null, fn ($q, $b) => $q->where('bank_id', $b))
            ->when($filters['facility_type'] ?? null, fn ($q, $t) => $q->where('facility_type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    public function createFacility(int $companyId, Bank $bank, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $bank, $data, $actor) {

            if (($data['facility_type'] ?? '') === 'contract_so') {
                $this->validateCollateral($companyId, $data);
            }

            $data['company_id'] = $companyId;
            $data['bank_id']    = $bank->id;
            $data['created_by'] = $actor->id;

            $facility = CreditFacility::create($data);
            AuditLog::record($actor, 'credit_facility.created', $facility, null, $facility->toArray());

            return $facility->fresh(['bank', 'currency', 'coaAccount']);
        });
    }

    public function updateFacility(int $companyId, CreditFacility $facility, array $data, User $actor): CreditFacility
    {
        return DB::transaction(function () use ($companyId, $facility, $data, $actor) {
            $before = $facility->toArray();

            if ($facility->facility_type === 'contract_so') {
                $this->validateCollateral($companyId, $data);
            }

            $facility->update($data);
            AuditLog::record($actor, 'credit_facility.updated', $facility, $before, $facility->fresh()->toArray());

            return $facility->fresh(['bank', 'currency', 'coaAccount']);
        });
    }

    // ── Collateral validation — Rule 30 ───────────────────────────

    public function validateCollateral(int $companyId, array $data): void
    {
        $projectId  = $data['collateral_project_id'] ?? null;
        $soId       = $data['collateral_so_id'] ?? null;
        $reviewDate = $data['review_date'] ?? now()->format('Y-m-d');

        if (! $projectId && ! $soId) {
            throw new \DomainException(
                'OD Against Contract/SO requires a linked Project or Sales Order as collateral.'
            );
        }

        $endDate = $projectId
            ? Project::where('id', $projectId)->where('company_id', $companyId)->where('status', 'active')->value('end_date')
            : SalesOrder::where('id', $soId)->where('company_id', $companyId)->where('status', 'active')->value('end_date');

        if (! $endDate) {
            throw new InactiveSalesOrderCollateralException(
                'The selected collateral was not found or is not active.'
            );
        }

        // Hard block — Rule 30.1
        if ($endDate <= $reviewDate) {
            throw new ExpiredCollateralException(
                'The assigned contract/SO end date must be after the facility review date. Banks do not finance expired or finishing contracts.'
            );
        }

        // Soft warning — within 30 days (Rule 30.1)
        $daysDiff = Carbon::parse($reviewDate)->diffInDays(Carbon::parse($endDate));
        if ($daysDiff <= 30) {
            session()->flash('warning',
                "The assigned contract/SO ends within 30 days of the facility review date. Confirm this is acceptable with your bank."
            );
        }
    }

    // ── Collateral expiry check (called by scheduler) ─────────────

    public function flagExpiredCollateral(): int
    {
        $today = now()->format('Y-m-d');
        $count = 0;

        CreditFacility::where('facility_type', 'contract_so')
            ->where('collateral_status', 'valid')
            ->where('status', 'active')
            ->with(['collateralProject', 'collateralSo'])
            ->each(function (CreditFacility $facility) use ($today, &$count) {
                $collateral = $facility->collateral_project_id
                    ? $facility->collateralProject
                    : $facility->collateralSo;

                $expired = ! $collateral
                    || in_array($collateral->status, ['closed', 'cancelled'])
                    || $collateral->end_date <= $today;

                if ($expired) {
                    $facility->update(['collateral_status' => 'expired']);
                    // TODO: Create notification for Company Admin (Stream notification system)
                    $count++;
                }
            });

        return $count;
    }
}