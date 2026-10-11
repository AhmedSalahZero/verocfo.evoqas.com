<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  ProductCategoryService
//
//  All business logic for Product Category management.
//  Two-level hierarchy: Main Category → Sub-Category.
//
//  create():
//    Auto-assigns next code (CAT-001, CAT-002 …)
//    parent_id = null → Main Category (level 1)
//    parent_id = set  → Sub-Category (level 2)
//
//  update():
//    name_en, name_ar, cost_treatment, expense_account_id only
//    level and parent immutable after creation
//
//  Consumable categories require expense_account_id.
//  Non-consumable categories must have expense_account_id = null.
//
//  @layer    Service
//  @depends  ProductCategory, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProductCategoryService
{
    // ── Tree for Index ────────────────────────────────────────────

    public function tree(int $companyId): Collection
    {
        return ProductCategory::with(['children.expenseAccount', 'expenseAccount'])
            ->main()
            ->active()
            ->orderBy('name_en')
            ->get();
    }

    // ── Flat list for dropdowns ───────────────────────────────────

    public function forDropdown(int $companyId): Collection
    {
        return ProductCategory::with('children')
            ->main()
            ->active()
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'level', 'parent_id', 'cost_treatment']);
    }

    // ── Main categories only (for parent selector) ────────────────

    public function mainForSelector(int $companyId): Collection
    {
        return ProductCategory::main()
            ->active()
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar']);
    }

    // Moved out of ProductCategoryController (July 2026 audit — was a
    // private controller method querying the database directly).
    // Expense accounts from the P&L section: account codes/types
    // matching the six fixed expense categories a consumable category
    // can be assigned to.
    //
    // Fix (July 2026 audit, applied): two separate bugs here.
    //   1) The old `where('company_id', $companyId)` excluded COGS
    //      (511-513), Finance Expenses (551-557/571-572), and D&A
    //      (561-563) permanently — those are seeded as hardcoded
    //      Level 3 system accounts with company_id = NULL forever,
    //      the same "Known Recurring Bug" pattern already fixed in
    //      LgIssuanceService. Swapped to ChartOfAccount::forCompany(),
    //      the model's own scope for "system accounts OR this
    //      company's own accounts" — same pattern used everywhere
    //      else in the app.
    //   2) The filter list said 'G&A Expenses' but the seeder's real
    //      account_type value is 'General & Administrative Expenses'
    //      (ChartOfAccountSeeder.php, the 54 group) — so G&A accounts
    //      never matched even once the company_id issue was fixed.
    //      Corrected to the exact seeder string.
    public function expenseAccounts(int $companyId): Collection
    {
        return ChartOfAccount::withoutGlobalScopes()->forCompany($companyId)
            ->where('is_postable', true)
            ->where('is_active', true)
            ->whereIn('account_type', [
                'Cost of Goods Sold',
                'Sales Expenses',
                'Marketing Expenses',
                'General & Administrative Expenses',
                'Finance Expenses',
                'Depreciation & Amortization',
            ])
            ->orderBy('code')
            ->get(['id', 'code', 'name_en', 'name_ar', 'account_type']);
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): ProductCategory
    {
        $this->validate($data);

        return DB::transaction(function () use ($companyId, $data, $actor) {
            $isMain = empty($data['parent_id']);
            $level  = $isMain ? ProductCategory::LEVEL_MAIN : ProductCategory::LEVEL_SUB;

            $category = ProductCategory::create([
                'company_id'         => $companyId,
                'parent_id'          => $data['parent_id'] ?? null,
                'name_en'            => $data['name_en'],
                'name_ar'            => $data['name_ar'] ?? null,
                'level'              => $level,
                'cost_treatment'     => $data['cost_treatment'] ?? null,
                'expense_account_id' => $data['expense_account_id'] ?? null,
                'is_active'          => true,
            ]);

            AuditLog::create([
                'company_id'     => $companyId,
                'user_id'        => $actor->id,
                'action'         => 'create_product_category',
                'auditable_type' => ProductCategory::class,
                'auditable_id'   => $category->id,
                'before'         => null,
                'after'          => $category->toArray(),
            ]);

            return $category;
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(ProductCategory $category, array $data, User $actor): ProductCategory
    {
        $this->validate($data, $category);

        return DB::transaction(function () use ($category, $data, $actor) {
            $before = $category->toArray();

            $category->update([
                'name_en'            => $data['name_en'],
                'name_ar'            => $data['name_ar'] ?? null,
                'cost_treatment'     => $data['cost_treatment'] ?? null,
                'expense_account_id' => $data['expense_account_id'] ?? null,
            ]);

            AuditLog::create([
                'company_id'     => $category->company_id,
                'user_id'        => $actor->id,
                'action'         => 'update_product_category',
                'auditable_type' => ProductCategory::class,
                'auditable_id'   => $category->id,
                'before'         => $before,
                'after'          => $category->fresh()->toArray(),
            ]);

            return $category->fresh();
        });
    }

    // ── Toggle Active ─────────────────────────────────────────────

    public function toggleActive(ProductCategory $category, User $actor): ProductCategory
    {
        if ($category->isMain() && $category->is_active) {
            $activeSubs = $category->children()->where('is_active', true)->count();
            if ($activeSubs > 0) {
                throw new \DomainException(
                    "Cannot deactivate [{$category->name_en}] — it has {$activeSubs} active sub-categories."
                );
            }
        }

        return DB::transaction(function () use ($category, $actor) {
            $before = ['is_active' => $category->is_active];
            $category->update(['is_active' => ! $category->is_active]);

            AuditLog::create([
                'company_id'     => $category->company_id,
                'user_id'        => $actor->id,
                'action'         => 'toggle_product_category',
                'auditable_type' => ProductCategory::class,
                'auditable_id'   => $category->id,
                'before'         => $before,
                'after'          => ['is_active' => $category->is_active],
            ]);

            return $category;
        });
    }

    // ── Private ───────────────────────────────────────────────────

    private function validate(array $data, ?ProductCategory $existing = null): void
    {
        $isConsumable = ! empty($data['cost_treatment']);

        if ($isConsumable && empty($data['expense_account_id'])) {
            throw new \DomainException(
                'Consumable categories require an expense account. Set the cost treatment and expense account together.'
            );
        }

        if (! $isConsumable && ! empty($data['expense_account_id'])) {
            throw new \DomainException(
                'Expense account is only for consumable categories. Remove the expense account or set a cost treatment.'
            );
        }

        if (! empty($data['parent_id'])) {
            $parent = ProductCategory::findOrFail($data['parent_id']);
            if ($parent->level !== ProductCategory::LEVEL_MAIN) {
                throw new \DomainException('Sub-categories can only be created under a Main category.');
            }
        }
    }
}