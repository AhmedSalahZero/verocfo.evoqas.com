<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  ChartOfAccountService
//
//  All business logic for Chart of Accounts management.
//  Called exclusively by ChartOfAccountController.
//
//  Responsibilities:
//    - Return full COA tree for a company (system + custom)
//    - Return postable accounts for transaction dropdowns
//    - Create a new Level 4 or Level 5 account with auto-code
//    - Update an existing user-defined account
//    - Toggle active/inactive (system accounts cannot be deactivated)
//    - Validate: posting only at levels 4–5
//    - Validate: currency required for cash/bank accounts
//
//  All writes use DB::transaction(). Audit log on every write.
//  System accounts (is_system = TRUE) cannot be edited or deleted.
//
//  @layer    Service
//  @depends  App\Models\ChartOfAccount
//            App\Models\AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Support\Money;
use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ChartOfAccountService
{
    // ── Full tree for COA page ────────────────────────────────────

    /**
     * Returns the complete COA tree for a company.
     * Includes system accounts + company custom accounts.
     * Ordered by code for correct hierarchy display.
     */
    public function tree(int $companyId): Collection
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->with('currency')
            ->orderBy('code')
            ->get();
    }

    /**
     * Returns the tree grouped by section then class.
     * Used to render the two-section COA page.
     */
    public function treeGrouped(int $companyId): array
    {
        $all = $this->tree($companyId);

        return [
            'balance_sheet' => [
                'assets'      => $this->buildSubTree($all, 'assets'),
                'liabilities' => $this->buildSubTree($all, 'liabilities'),
                'equity'      => $this->buildSubTree($all, 'equity'),
            ],
            'income_statement' => [
                'revenue'  => $this->buildSubTree($all, 'revenue'),
                'expenses' => $this->buildSubTree($all, 'expenses'),
            ],
        ];
    }

    /**
     * Returns postable accounts for dropdowns on transaction forms.
     * Optionally filtered by class (e.g. 'assets' for debit side).
     */
    public function postableForDropdown(int $companyId, ?string $class = null): Collection
    {
        return ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->postable()
            ->active()
            ->when($class, fn ($q) => $q->ofClass($class))
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'class', 'normal_balance', 'currency_id']);
    }

    /**
     * Refuses a code the company can already see.
     *
     * "Can see" is the point: an account is visible to a company if it
     * is the company's own OR a shared system account (company_id
     * NULL), which is what forCompany() expresses everywhere else in
     * this codebase. The database's unique(company_id, code) index
     * cannot enforce this, because a NULL company_id never equals a
     * real one in SQL — so 111001 Land (system) and 111001 dd
     * (company 107) sit side by side without the index objecting.
     *
     * Placed in the service rather than only in the form request, so
     * an import, an API call or a seeder is covered too — the same
     * reasoning as the PRD-001 fix.
     */
    private function assertCodeIsFree(int $companyId, string $code): void
    {
        $existing = ChartOfAccount::withoutGlobalScopes()
            ->forCompany($companyId)
            ->where('code', $code)
            ->first(['name_en', 'company_id']);

        if (! $existing) {
            return;
        }

        $owner = $existing->company_id === null
            ? ' It belongs to the standard chart of accounts.'
            : '';

        throw new \DomainException(
            "Account code {$code} is already used by \"{$existing->name_en}\".{$owner}"
        );
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): ChartOfAccount
    {
        $this->validateUserAccount($data);

        return DB::transaction(function () use ($companyId, $data, $actor) {

            $parent = ChartOfAccount::withoutGlobalScopes()->findOrFail($data['parent_id']);

            // L-24: a parent that already carries postings would end up
            // with its own balance AND sub-account balances (mixed-level
            // balances). Refuse; the accountant must move the postings
            // by journal entry first.
            $hasPostings = DB::table('journal_entry_lines')
                ->where('company_id', $companyId)
                ->where('chart_of_account_id', $parent->id)
                ->exists()
                || DB::table('ob_coa_balances')
                    ->where('company_id', $companyId)
                    ->where('account_id', $parent->id)
                    ->where('amount', '<>', 0)
                    ->exists();

            if ($hasPostings) {
                throw new \DomainException(
                    "Account {$parent->code} already has postings, so it cannot receive sub-accounts. "
                    . 'Choose another parent, or move its balance with a journal entry first.'
                );
            }

            $code = $data['use_auto_code']
                ? ChartOfAccount::nextCode($parent->id, $companyId)
                : $data['code'];

            $this->assertCodeIsFree($companyId, $code);

            $account = ChartOfAccount::create([
                'company_id'           => $companyId,
                'parent_id'            => $parent->id,
                'code'                 => $code,
                'name_en'              => $data['name_en'],
                'name_ar'              => $data['name_ar'] ?? null,
                'level'                => $parent->level + 1,
                'section'              => $parent->section,
                'class'                => $parent->class,
                'account_type'         => $parent->account_type,
                'normal_balance'       => $parent->normal_balance,
                'is_system'            => false,
                'is_postable'          => true,
                // Audit H3: a sub-account of a control account (AR, AP,
                // inventory, …) is itself a control account.
                'blocks_manual_posting' => (bool) $parent->blocks_manual_posting,
                'allow_currency'       => $parent->allow_currency,
                'currency_id'          => $data['currency_id'] ?? null,
                'requires_cost_center' => $data['requires_cost_center'] ?? false,
                'is_active'            => true,
            ]);

            AuditLog::record($actor, 'create', $account, null, $account->toArray());

            return $account;
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(ChartOfAccount $account, array $data, User $actor): ChartOfAccount
    {
        if ($account->is_system) {
            throw new \DomainException('System accounts cannot be modified.');
        }

        return DB::transaction(function () use ($account, $data, $actor) {

            $before = $account->toArray();

            $account->update([
                'name_en'              => $data['name_en'],
                'name_ar'              => $data['name_ar'] ?? null,
                'currency_id'          => $data['currency_id'] ?? null,
                'requires_cost_center' => $data['requires_cost_center'] ?? false,
            ]);

            AuditLog::record($actor, 'update', $account, $before, $account->fresh()->toArray());

            return $account->fresh();
        });
    }

    // ── Toggle Active ─────────────────────────────────────────────

    public function toggleActive(ChartOfAccount $account, User $actor): ChartOfAccount
    {
        if ($account->is_system) {
            throw new \DomainException('System accounts cannot be deactivated.');
        }

        // Audit M7 — an inactive account drops out of the Trial Balance,
        // Balance Sheet and P&L, so deactivating one that still carries a
        // balance makes those reports stop balancing. Only an account
        // (and everything under it) with a zero balance may be switched
        // off. Re-activating is always allowed.
        if ($account->is_active) {
            $this->assertNoBalance($account);
        }

        return DB::transaction(function () use ($account, $actor) {

            $before = ['is_active' => $account->is_active];
            $account->update(['is_active' => ! $account->is_active]);

            AuditLog::record($actor, 'toggle_active', $account, $before, ['is_active' => $account->is_active]);

            return $account;
        });
    }

    // ── Private Helpers ───────────────────────────────────────────

    /**
     * Audit M7 — refuse to deactivate an account whose own balance, or
     * the balance of any account beneath it, is not exactly zero.
     * Every ledger line counts (reversals are separate lines, so they
     * net out on their own). Math is done on strings with bcmath.
     */
    private function assertNoBalance(ChartOfAccount $account): void
    {
        $ids      = [(int) $account->id];
        $frontier = $ids;

        while ($frontier) {
            $frontier = ChartOfAccount::withoutGlobalScopes()
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->diff($ids)
                ->values()
                ->all();
            $ids = array_merge($ids, $frontier);
        }

        // A company's own account carries its company_id; a shared system
        // row (company_id NULL) is looked at for the company in session.
        $companyId = $account->company_id ?? \App\Support\TenantContext::companyId();

        $row = DB::table('journal_entry_lines')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('chart_of_account_id', $ids)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $balance = bcsub((string) $row->d, (string) $row->c, 2);

        if (bccomp($balance, '0', 2) !== 0) {
            throw new \DomainException(
                "Account {$account->code} still has a balance of ".Money::display($balance)
                .' — it cannot be deactivated or it would disappear from the Trial Balance, Balance Sheet and Income Statement. '
                .'Transfer the balance out with a journal entry first, then deactivate it.'
            );
        }
    }

    private function validateUserAccount(array $data): void
    {
        $parent = ChartOfAccount::withoutGlobalScopes()
            ->findOrFail($data['parent_id']);

        if ($parent->level >= 5) {
            throw new \DomainException('Cannot add sub-accounts beyond Level 5.');
        }

        // Fix (July 2026): Sales Expenses (52), Marketing (53), and
        // G&A (54) were never seeded with anything at Level 3 — only
        // their Level 2 group header exists. The general rule below
        // (Level 3 or 4 only) was blocking users from ever adding an
        // account under any of these three groups, since there was
        // nothing at Level 3 to attach to. These three are named
        // explicitly, rather than opening up every Level 2 group —
        // Level 2 groups elsewhere (Assets, Current Assets, etc.)
        // should still go through their seeded Level 3 accounts as
        // normal.
        $level2ExceptionCodes = ['52', '53', '54'];

        if ($parent->level < 3 && ! ($parent->level === 2 && in_array($parent->code, $level2ExceptionCodes, true))) {
            throw new \DomainException('User accounts can only be added under Level 3 or Level 4 accounts.');
        }

        // Fix (July 2026): 135001 (Raw Materials), 135002 (WIP),
        // 135003 (Finished Goods), and 135004 (Project WIP) are now
        // managed exclusively through Product Categories —
        // ProductService::resolveCategoryInventoryAccount() creates
        // one sub-account per category automatically the first time
        // it's needed. A manually-created sub-account here would sit
        // orphaned: no product would ever be assigned to it, and it
        // would never receive a posting, since GRN (and everything
        // after it) always resolves the account through the
        // product's category, never through a user's manual pick.
        $inventoryParentCodes = ['135001', '135002', '135003', '135004'];

        if (in_array($parent->code, $inventoryParentCodes, true)) {
            throw new \DomainException(
                'Sub-accounts under this Inventory account are created automatically, one per Product Category, ' .
                'the first time a product needs one — they cannot be added manually here.'
            );
        }
    }

    /**
     * Returns all accounts for one class sorted by code.
     * The Vue component receives a flat list and renders
     * indentation itself based on the level field.
     */
    private function buildSubTree(Collection $all, string $class): Collection
    {
        return $all->where('class', $class)
                   ->sortBy('code')
                   ->values();
    }
}