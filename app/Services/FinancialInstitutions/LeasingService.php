<?php

namespace App\Services\FinancialInstitutions;

// ══════════════════════════════════════════════════════════════════
//  LeasingService
//
//  Leasing Company master + Leasing Contracts (terms only in Layer 1C).
//  Contract activation (journal posting) wired in Layer 7.
//
//  Accounting treatment is chosen at creation and LOCKED on activation (Rule 29.1).
//  Capital Leases always use IFRS 16 — no treatment choice (Rule 29.3).
//
//  Fix log:
//    - createContract()/updateContract() now handle currency_id +
//      exchange_rate. leasing_contracts previously had no currency at
//      all.
//    - activeCurrencies() added — same proven pattern as
//      ProjectController/CleanOverdraftController. Currency::
//      forCompany() alone never works; the model's own global
//      CompanyScope silently drops every system currency.
//    - saveCheques()/importChequesFromExcel() added — manually-entered
//      real cheque schedule (LeaseCheque), separate from and not
//      replacing the existing bcmath auto-amortization
//      (LeaseAmortizationService, untouched by this change).
//
//  @layer    Service
//  @depends  LeasingCompany, LeasingContract, LeaseCheque, LeaseAmortizationLine, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Models\AuditLog;
use App\Models\CompanyActiveCurrency;
use App\Models\Currency;
use App\Models\FinancialInstitutions\LeaseCheque;
use App\Models\FinancialInstitutions\LeasingCompany;
use App\Models\FinancialInstitutions\LeasingContract;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class LeasingService
{
    // ── Leasing Companies ─────────────────────────────────────────

    public function paginateCompanies(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return LeasingCompany::withCount('contracts')
            ->when($filters['search'] ?? null, fn ($q, $s) =>
                $q->where(fn ($w) => $w->where('name_en', 'like', "%{$s}%")->orWhere('name_ar', 'like', "%{$s}%")) // Audit L-18
            )
            ->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']))
            ->orderBy('name_en')
            ->paginate(20)
            ->withQueryString();
    }

    public function createCompany(int $companyId, array $data, User $actor): LeasingCompany
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $data['company_id'] = $companyId;
            $data['created_by'] = $actor->id;
            $company = LeasingCompany::create($data);
            AuditLog::record($actor, 'leasing_company.created', $company, null, $company->toArray());
            return $company;
        });
    }

    public function updateCompany(LeasingCompany $company, array $data, User $actor): LeasingCompany
    {
        return DB::transaction(function () use ($company, $data, $actor) {
            $before = $company->toArray();
            $company->update($data);
            AuditLog::record($actor, 'leasing_company.updated', $company, $before, $company->fresh()->toArray());
            return $company->fresh();
        });
    }

    public function toggleCompany(LeasingCompany $company, User $actor): LeasingCompany
    {
        return DB::transaction(function () use ($company, $actor) {
            $before = $company->toArray();
            $company->update(['is_active' => ! $company->is_active]);
            AuditLog::record($actor, 'leasing_company.toggled', $company, $before, $company->fresh()->toArray());
            return $company->fresh();
        });
    }

    // ── Leasing Contracts ─────────────────────────────────────────

    public function paginateContracts(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return LeasingContract::with(['leasingCompany:id,name_en', 'bankAccount:id,account_name'])
            ->when($filters['leasing_company_id'] ?? null, fn ($q, $id) => $q->where('leasing_company_id', $id))
            ->when($filters['contract_type'] ?? null, fn ($q, $t) => $q->where('contract_type', $t))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    public function createContract(int $companyId, array $data, User $actor): LeasingContract
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $this->validateContractData($data);

            $cheques = $data['cheques'] ?? [];
            unset($data['cheques']);

            $data['company_id'] = $companyId;
            $data['created_by'] = $actor->id;

            // Auto-calculate end date
            $data['end_date'] = date('Y-m-d', strtotime(
                $data['start_date'] . ' +' . $data['duration_months'] . ' months'
            ));

            $contract = LeasingContract::create($data);

            // ═══════════════════════════════════════════════════════
            // LEASE AMORTIZATION ENGINE — built July 2026, see
            // LeaseAmortizationService::activate(). That's a SEPARATE
            // action from creating the contract (this method) — a
            // contract is saved here as 'draft' first; activation
            // (and the real opening entry) only happens when the user
            // deliberately activates it, via a separate button/action.
            //
            // Confirmed with Mahmoud: entry_type = 'existing' never
            // posts anything (same principle as Opening Balances).
            // entry_type = 'new' posts a real opening entry on
            // activation: Dr the asset (ROU or Fixed Asset, whichever
            // applies) / Cr system account 213 (Lease Liabilities —
            // Long-Term) — NOT bank_liability_account_id (that field
            // turned out to be for something else; the Lease
            // Amortization Engine doesn't use it).
            // ═══════════════════════════════════════════════════════

            if (! empty($cheques)) {
                $this->saveCheques($contract, $cheques, $actor);
            }

            AuditLog::record($actor, 'leasing_contract.created', $contract, null, $contract->toArray());

            return $contract->fresh(['leasingCompany', 'bankAccount', 'currency', 'cheques']);
        });
    }

    public function updateContract(LeasingContract $contract, array $data, User $actor): LeasingContract
    {
        return DB::transaction(function () use ($contract, $data, $actor) {

            // Rule 29.1 — treatment is locked once active
            if ($contract->isActive() && isset($data['accounting_treatment'])
                && $data['accounting_treatment'] !== $contract->accounting_treatment) {
                throw new \DomainException(
                    'Accounting treatment cannot be changed on an active lease contract.'
                );
            }

            $cheques = $data['cheques'] ?? null;
            unset($data['cheques']);

            $before = $contract->toArray();

            if (isset($data['start_date'], $data['duration_months'])) {
                $data['end_date'] = date('Y-m-d', strtotime(
                    $data['start_date'] . ' +' . $data['duration_months'] . ' months'
                ));
            }

            $contract->update($data);

            if ($cheques !== null) {
                $this->saveCheques($contract, $cheques, $actor);
            }

            AuditLog::record($actor, 'leasing_contract.updated', $contract, $before, $contract->fresh()->toArray());

            return $contract->fresh(['leasingCompany', 'bankAccount', 'currency', 'cheques']);
        });
    }

    public function toggleContractStatus(LeasingContract $contract, string $newStatus, User $actor): LeasingContract
    {
        return DB::transaction(function () use ($contract, $newStatus, $actor) {
            $before = $contract->toArray();
            $contract->update(['status' => $newStatus]);
            // Fix: audit_log.action is capped at 30 chars — the original
            // 31-char string was a latent bug, never yet triggered live.
            AuditLog::record($actor, 'leasing_contract.status_set', $contract, $before, $contract->fresh()->toArray());
            return $contract->fresh();
        });
    }

    // ── Cheque schedule ─────────────────────────────────────────────

    /**
     * Replaces the contract's cheque schedule with the given rows.
     * Full replace (delete + re-create) — simplest correct behaviour
     * for a user-edited repeater, same approach as SalesOrder lines.
     */
    public function saveCheques(LeasingContract $contract, array $cheques, User $actor): void
    {
        DB::transaction(function () use ($contract, $cheques, $actor) {
            LeaseCheque::withoutGlobalScopes()
                ->where('leasing_contract_id', $contract->id)
                ->delete();

            foreach ($cheques as $row) {
                if (empty($row['payment_date']) || empty($row['cheque_number'])) {
                    continue; // skip fully-blank rows
                }

                LeaseCheque::create([
                    'company_id'           => $contract->company_id,
                    'leasing_contract_id'  => $contract->id,
                    'payment_date'         => $row['payment_date'],
                    'beginning_balance'    => $row['beginning_balance'] ?? null,
                    'cheque_number'        => $row['cheque_number'],
                    'cheque_amount'        => $row['cheque_amount']    ?? 0,
                    'principal_amount'     => $row['principal_amount'] ?? 0,
                    'interest_amount'      => $row['interest_amount']  ?? 0,
                    'end_balance'          => $row['end_balance'] ?? null,
                    'status'               => LeaseCheque::STATUS_PENDING,
                    'created_by'           => $actor->id,
                ]);
            }

            // Audit M21 — tie each cheque to the schedule instalment it
            // pays (a no-op until the lease has been activated).
            app(LeaseAmortizationService::class)->linkChequesToSchedule($contract);
        });
    }

    /**
     * Parses an uploaded Excel file into cheque rows for the user to
     * review in the repeater before saving — does NOT write to the
     * database. Expected columns (row 1 = header, ignored):
     *   Payment Date | Beginning Balance | Cheque Number | Cheque Amount | Principal Amount | Interest Amount | End Balance
     *
     * UPDATED — column layout changed from 5 to 7 columns when
     * Beginning Balance / End Balance were added to the on-screen
     * Cheque Schedule table; this must always match that table's
     * column order exactly, since it's the same repeater being
     * bulk-populated either way.
     */
    public function parseChequesExcel(UploadedFile $file): array
    {
        $rows = Excel::toArray([], $file)[0] ?? [];

        $parsed = [];
        foreach ($rows as $i => $row) {
            if ($i === 0) continue; // skip header row
            if (empty(array_filter($row))) continue; // skip blank row

            $paymentDate = $row[0] ?? null;
            if (is_numeric($paymentDate)) {
                // Excel serial date number
                $paymentDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($paymentDate)->format('Y-m-d');
            } elseif ($paymentDate) {
                $paymentDate = date('Y-m-d', strtotime($paymentDate));
            }

            $parsed[] = [
                'payment_date'      => $paymentDate,
                'beginning_balance' => Money::round($row[1] ?? 0, 2),
                'cheque_number'     => (string) ($row[2] ?? ''),
                'cheque_amount'     => Money::round($row[3] ?? 0, 2),
                'principal_amount'  => Money::round($row[4] ?? 0, 2),
                'interest_amount'   => Money::round($row[5] ?? 0, 2),
                'end_balance'       => Money::round($row[6] ?? 0, 2),
            ];
        }

        return $parsed;
    }

    // ── Currency lookup ──────────────────────────────────────────

    /**
     * Active currencies for this company via company_active_currencies
     * pivot. Functional currency always first, then alphabetical.
     * Same pattern as ProjectController::activeCurrencies().
     */
    public function activeCurrencies(int $companyId): \Illuminate\Support\Collection
    {
        $activeCurrencyIds = CompanyActiveCurrency::where('company_id', $companyId)
            ->where('is_active', true)
            ->pluck('currency_id');

        $functionalCurrencyId = $this->functionalCurrencyId($companyId);

        return Currency::withoutGlobalScopes()
            ->whereIn('id', $activeCurrencyIds)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$functionalCurrencyId])
            ->orderBy('code')
            ->get(['id', 'code', 'name_en']);
    }

    public function functionalCurrencyId(int $companyId): ?int
    {
        return CompanyActiveCurrency::where('company_id', $companyId)
            ->where('is_functional', true)
            ->value('currency_id');
    }

    // ── Private helpers ───────────────────────────────────────────

    private function validateContractData(array $data): void
    {
        // Capital leases must not have accounting_treatment set
        if (($data['contract_type'] ?? '') === 'capital' && isset($data['accounting_treatment'])) {
            unset($data['accounting_treatment']); // strip it silently
        }

        // Operational leases must have accounting_treatment
        if (($data['contract_type'] ?? '') === 'operational'
            && ! in_array($data['accounting_treatment'] ?? '', ['expense_a', 'rou_b'])) {
            throw new \DomainException(
                'Operational leases must specify an accounting treatment (Treatment A or Treatment B).'
            );
        }

        // Treatment A must have an expense account
        if (($data['accounting_treatment'] ?? '') === 'expense_a' && empty($data['expense_account_id'])) {
            throw new \DomainException(
                'Treatment A (Direct Expense) requires an expense account to be selected.'
            );
        }
    }
}
