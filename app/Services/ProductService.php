<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  ProductService
//
//  All business logic for Products & Services management.
//
//  Account auto-assignment logic:
//    Revenue:   always COA code 411 (Sales Local)
//    COGS:      tradable→511, manufactured→512, service→513
//    Inventory: raw_material→135001, service_with_inv WIP→135002,
//               tradable/manufactured→135003
//    Consumable expense: from category.expense_account_id
//
//  resolveAccounts() is called on every create/update to keep
//  accounts in sync with the product type automatically.
//
//  @layer    Service
//  @depends  Product, ProductCategory, UnitOfMeasure
//            ChartOfAccount, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUomLine;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProductService
{
    // ── List ──────────────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return Product::with(['category', 'primaryUom'])
            ->withCount('uomLines')
            // Fixed Asset "products" are a separate concept with their
            // own dedicated page (Settings → Fixed Asset Types) — never
            // shown mixed in with real Products & Services, confirmed
            // with Mahmoud after the initial combined-form design
            // proved confusing (no UOM/barcode/selling price applies
            // to owned, depreciated equipment).
            //
            // NOTE: nature is NULL for raw_material/consumable products
            // (see migration 000033) — a plain != comparison in SQL
            // evaluates to NULL (excluded) for those rows too, so this
            // must explicitly allow NULL through as well.
            ->where(function ($q) {
                $q->whereNull('nature')->orWhere('nature', '!=', Product::NATURE_FIXED_ASSET);
            })
            ->when($filters['search'] ?? null, fn ($q, $v) =>
                // Audit L-18: grouped, so the OR cannot escape the other filters.
                $q->where(fn ($w) => $w->where('name_en', 'like', "%{$v}%")
                  ->orWhere('code', 'like', "%{$v}%")
                  ->orWhere('barcode', 'like', "%{$v}%"))
            )
            ->when($filters['category_id'] ?? null, fn ($q, $v) =>
                $q->where('category_id', $v)
            )
            ->when($filters['nature'] ?? null, fn ($q, $v) =>
                $q->where('nature', $v)
            )
            ->when(isset($filters['has_stock']), fn ($q) =>
                $q->where('has_stock', $filters['has_stock'])
            )
            ->when($filters['status'] ?? null, fn ($q, $v) =>
                $q->where('is_active', $v === 'active')
            )
            ->orderBy('code')
            ->paginate(25)
            ->withQueryString();
    }

    // ── Create ────────────────────────────────────────────────────

    public function create(int $companyId, array $data, User $actor): Product
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {

            $usageTypes = $data['usage_types'];
            // Fixed Assets module, Phase 1 — nature is forced, never
            // ambiguous (mutually exclusive with the operational usage
            // types is already enforced in ProductRequest).
            $nature     = in_array(Product::TYPE_FIXED_ASSET, $usageTypes, true)
                ? Product::NATURE_FIXED_ASSET
                : $this->resolveNature($usageTypes, $data['nature'] ?? null, $companyId);
            $hasStock   = Product::deriveHasStock($usageTypes, $nature);
            $accounts   = $this->resolveAccounts($companyId, $data['category_id'], $usageTypes, $nature, $data['fixed_asset_account_id'] ?? null);

            $product = Product::create([
                'company_id'          => $companyId,
                'code'                => $data['use_auto_code']
                                            ? Product::nextCode($companyId)
                                            : $data['code'],
                'name_en'             => $data['name_en'],
                'name_ar'             => $data['name_ar'] ?? null,
                'barcode'             => $data['barcode'] ?? null,
                'description'         => $data['description'] ?? null,
                'category_id'         => $data['category_id'],
                'usage_types'         => $usageTypes,
                'nature'              => $nature,
                'has_stock'           => $hasStock,
                'primary_uom_id'      => $data['primary_uom_id'],
                'revenue_account_id'  => $accounts['revenue'],
                'cogs_account_id'     => $accounts['cogs'],
                'inventory_account_id'=> $accounts['inventory'],
                'fixed_asset_account_id'              => $accounts['fixed_asset'],
                'accumulated_depreciation_account_id'  => $accounts['accumulated_depreciation'],
                'default_useful_life_months'           => $data['default_useful_life_months'] ?? null,
                'default_residual_value'               => $data['default_residual_value'] ?? null,
                'weight'              => $data['weight'] ?? null,
                'weight_uom_id'       => $data['weight_uom_id'] ?? null,
                'reorder_level'       => $data['reorder_level'] ?? null,
                'reorder_qty'         => $data['reorder_qty'] ?? null,
                'is_active'           => true,
            ]);

            // Create primary UOM line
            ProductUomLine::create([
                'company_id'       => $companyId,
                'product_id'       => $product->id,
                'uom_id'           => $data['primary_uom_id'],
                'conversion_rate'  => 1.000000,
                'is_primary'       => true,
                'use_for_purchase' => true,
                'use_for_sales'    => true,
            ]);

            // Create alternate UOM lines
            foreach ($data['alternate_uoms'] ?? [] as $altUom) {
                if (empty($altUom['uom_id']) || empty($altUom['conversion_rate'])) continue;
                ProductUomLine::create([
                    'company_id'       => $companyId,
                    'product_id'       => $product->id,
                    'uom_id'           => $altUom['uom_id'],
                    'conversion_rate'  => bcadd((string)$altUom['conversion_rate'], '0', 6),
                    'is_primary'       => false,
                    'use_for_purchase' => $altUom['use_for_purchase'] ?? false,
                    'use_for_sales'    => $altUom['use_for_sales'] ?? false,
                ]);
            }

            AuditLog::create([
                'company_id'     => $companyId,
                'user_id'        => $actor->id,
                'action'         => 'create_product',
                'auditable_type' => Product::class,
                'auditable_id'   => $product->id,
                'before'         => null,
                'after'          => $product->toArray(),
            ]);

            return $product->load(['category', 'primaryUom', 'uomLines.uom']);
        });
    }

    // ── Update ────────────────────────────────────────────────────

    public function update(Product $product, array $data, User $actor): Product
    {
        return DB::transaction(function () use ($product, $data, $actor) {

            $before     = $product->toArray();
            $usageTypes = $data['usage_types'];
            $nature     = in_array(Product::TYPE_FIXED_ASSET, $usageTypes, true)
                ? Product::NATURE_FIXED_ASSET
                : $this->resolveNature($usageTypes, $data['nature'] ?? null, $product->company_id);
            $hasStock   = Product::deriveHasStock($usageTypes, $nature);
            $accounts   = $this->resolveAccounts($product->company_id, $data['category_id'], $usageTypes, $nature, $data['fixed_asset_account_id'] ?? null);

            $this->guardChangesAfterStockMoved($product, $data, $hasStock, $accounts);

            $oldInventoryAccountId = $product->inventory_account_id;

            $product->update([
                'name_en'             => $data['name_en'],
                'name_ar'             => $data['name_ar'] ?? null,
                'barcode'             => $data['barcode'] ?? null,
                'description'         => $data['description'] ?? null,
                'category_id'         => $data['category_id'],
                'usage_types'         => $usageTypes,
                'nature'              => $nature,
                'has_stock'           => $hasStock,
                'primary_uom_id'      => $data['primary_uom_id'],
                'revenue_account_id'  => $accounts['revenue'],
                'cogs_account_id'     => $accounts['cogs'],
                'inventory_account_id'=> $accounts['inventory'],
                'fixed_asset_account_id'              => $accounts['fixed_asset'],
                'accumulated_depreciation_account_id'  => $accounts['accumulated_depreciation'],
                'default_useful_life_months'           => $data['default_useful_life_months'] ?? null,
                'default_residual_value'               => $data['default_residual_value'] ?? null,
                'weight'              => $data['weight'] ?? null,
                'weight_uom_id'       => $data['weight_uom_id'] ?? null,
                'reorder_level'       => $data['reorder_level'] ?? null,
                'reorder_qty'         => $data['reorder_qty'] ?? null,
            ]);

            // Audit M-17 — UOM lines are synchronised in place instead of
            // being deleted and recreated on every save (new ids and a
            // silently reset set of flags each time).
            $this->syncUomLines($product, $data);

            $this->reclassifyInventoryIfAccountChanged($product, $oldInventoryAccountId, $actor);

            AuditLog::create([
                'company_id'     => $product->company_id,
                'user_id'        => $actor->id,
                'action'         => 'update_product',
                'auditable_type' => Product::class,
                'auditable_id'   => $product->id,
                'before'         => $before,
                'after'          => $product->fresh()->toArray(),
            ]);

            return $product->fresh()->load(['category', 'primaryUom', 'uomLines.uom']);
        });
    }

    // ── Audit M-17 helpers ─────────────────────────────────────────

    /**
     * Once a product has stock movements, three changes would quietly
     * corrupt history and are refused: switching its primary unit (every
     * past quantity would change meaning), and no longer tracking stock
     * while units are still on hand. Moving to another inventory account
     * is allowed — the value is reclassified (see below).
     */
    private function guardChangesAfterStockMoved(Product $product, array $data, bool $hasStock, array $accounts): void
    {
        $moved = \App\Models\StockMovement::withoutGlobalScopes()
            ->where('company_id', $product->company_id)->where('product_id', $product->id)->exists();

        if (! $moved) {
            return;
        }

        if ((int) $data['primary_uom_id'] !== (int) $product->primary_uom_id) {
            throw new \DomainException('This product already has stock movements — its primary unit of measure can no longer be changed, because every past quantity is expressed in it.');
        }

        if ($product->has_stock && ! $hasStock) {
            $qty = (string) \App\Models\ProductStockBalance::withoutGlobalScopes()
                ->where('company_id', $product->company_id)->where('product_id', $product->id)->value('quantity_on_hand');
            if (bccomp($qty ?: '0', '0', 4) > 0) {
                throw new \DomainException('This product still has stock on hand — it cannot be changed to a product that does not track stock. Write the stock off first.');
            }
        }
    }

    /**
     * When a change of category or nature moves the product to another
     * inventory account, the value already sitting in the old account is
     * moved with it (Dr new / Cr old, dated today), so the old account
     * does not keep stock that the product no longer posts to.
     */
    private function reclassifyInventoryIfAccountChanged(Product $product, $oldAccountId, User $actor): void
    {
        $newAccountId = $product->fresh()->inventory_account_id;
        if (! $oldAccountId || ! $newAccountId || (int) $oldAccountId === (int) $newAccountId) {
            return;
        }

        $value = (string) \App\Models\ProductStockBalance::withoutGlobalScopes()
            ->where('company_id', $product->company_id)->where('product_id', $product->id)->value('wac_total_value');
        $value = bcadd($value ?: '0', (string) \App\Models\JobOrderStockBalance::withoutGlobalScopes()
            ->where('company_id', $product->company_id)->where('product_id', $product->id)->sum('total_value'), 2);

        if (bccomp($value, '0', 2) == 0) {
            return;
        }

        $abs = bccomp($value, '0', 2) > 0 ? $value : bcmul($value, '-1', 2);
        $pos = bccomp($value, '0', 2) > 0;
        $desc = "Inventory reclassified — {$product->name_en}";

        app(\App\Services\Accounting\JournalEntryService::class)->post(
            companyId:  $product->company_id,
            entryDate:  now()->toDateString(),
            lines: [
                ['chart_of_account_id' => $newAccountId, 'debit' => $pos ? $abs : '0', 'credit' => $pos ? '0' : $abs, 'description' => $desc],
                ['chart_of_account_id' => $oldAccountId, 'debit' => $pos ? '0' : $abs, 'credit' => $pos ? $abs : '0', 'description' => $desc],
            ],
            sourceType: 'product_inventory_reclass',
            sourceId:   $product->id,
            narration:  $desc.' (category or nature changed)',
            actor:      $actor,
        );
    }

    private function syncUomLines(Product $product, array $data): void
    {
        $product->uomLines()->update(['is_primary' => false]);
        $keep = [(int) $data['primary_uom_id']];

        ProductUomLine::updateOrCreate(
            ['product_id' => $product->id, 'uom_id' => $data['primary_uom_id']],
            ['company_id' => $product->company_id, 'conversion_rate' => 1.000000, 'is_primary' => true, 'use_for_purchase' => true, 'use_for_sales' => true]
        );

        foreach ($data['alternate_uoms'] ?? [] as $alt) {
            if (empty($alt['uom_id']) || empty($alt['conversion_rate']) || (int) $alt['uom_id'] === (int) $data['primary_uom_id']) {
                continue;
            }
            $keep[] = (int) $alt['uom_id'];
            ProductUomLine::updateOrCreate(
                ['product_id' => $product->id, 'uom_id' => $alt['uom_id']],
                [
                    'company_id'       => $product->company_id,
                    'conversion_rate'  => bcadd((string) $alt['conversion_rate'], '0', 6),
                    'is_primary'       => false,
                    'use_for_purchase' => $alt['use_for_purchase'] ?? false,
                    'use_for_sales'    => $alt['use_for_sales'] ?? false,
                ]
            );
        }

        $product->uomLines()->whereNotIn('uom_id', $keep)->delete();
    }

    // ── Toggle Active ─────────────────────────────────────────────

    public function toggleActive(Product $product, User $actor): Product
    {
        return DB::transaction(function () use ($product, $actor) {
            $product->update(['is_active' => ! $product->is_active]);
            AuditLog::create([
                'company_id'     => $product->company_id,
                'user_id'        => $actor->id,
                'action'         => 'toggle_product',
                'auditable_type' => Product::class,
                'auditable_id'   => $product->id,
                'before'         => ['is_active' => ! $product->is_active],
                'after'          => ['is_active' => $product->is_active],
            ]);
            return $product;
        });
    }

    // ── Add Price ─────────────────────────────────────────────────

    public function addPrice(int $companyId, array $data, User $actor): \App\Models\ProductPrice
    {
        return DB::transaction(function () use ($companyId, $data, $actor) {
            $price = \App\Models\ProductPrice::create([
                'company_id'     => $companyId,
                'product_id'     => $data['product_id'],
                'price_type'     => $data['price_type'],
                'uom_id'         => $data['uom_id'],
                'currency_id'    => $data['currency_id'],
                'price'          => bcadd((string)$data['price'], '0', 4),
                'effective_date' => $data['effective_date'],
                'notes'          => $data['notes'] ?? null,
                'created_by'     => $actor->id,
                'created_at'     => now(),
            ]);

            AuditLog::create([
                'company_id'     => $companyId,
                'user_id'        => $actor->id,
                'action'         => 'add_product_price',
                'auditable_type' => \App\Models\ProductPrice::class,
                'auditable_id'   => $price->id,
                'before'         => null,
                'after'          => $price->toArray(),
            ]);

            return $price;
        });
    }

    // ── Nature safety net (July 2026 fix; hardened 2026-09) ─────────

    /**
     * Defensive backstop for a Vue reactivity bug on the Product form:
     * when only one Nature option applies for the company's type (e.g.
     * a pure Trading company only ever has "tradable"), the form is
     * supposed to auto-select it — but a non-deep watch on an
     * in-place-mutated array silently failed to fire, so `nature`
     * could reach the server as null even though the form displayed
     * "Nature auto-set to Tradable". ProductRequest also allowed
     * `nature` as nullable, so this passed validation and produced a
     * product with no resolvable inventory account (GRN posting
     * failure: "no account to post to").
     *
     * This does NOT replace the front-end fix (still needed — the
     * user should see the real selected value, not just a promise).
     * This guarantees the server never saves a saleable product with
     * an unresolved nature: it re-derives it the same way the form
     * itself would when there's exactly one valid answer, and — PRD-001
     * fix, confirmed with Mahmoud, 2026-09 — REFUSES outright when
     * there's more than one valid answer and nothing was supplied,
     * rather than silently returning null.
     *
     * That refusal used to live only in ProductRequest (the web form's
     * own validation) — which protects the Products screen, but
     * nothing else. It didn't protect a caller that talks to this
     * service directly, bypassing the HTTP request entirely — which
     * is a real gap, not a theoretical one: the demo-data generator
     * (SeedDemoManufacturingCompany) already has to work around it by
     * hand, with its own comment noting "this can't auto-resolve; it
     * must be explicit." A future import feature or API endpoint would
     * hit the exact same gap. Moving the refusal here, into the
     * service every caller goes through, closes it everywhere at once
     * — the same reasoning applied throughout this build (see
     * JournalEntryService::reverse(), StockLedgerService::
     * reverseReceipt()): a check that only lives at one entry point
     * protects only that one entry point.
     */
    private function resolveNature(array $usageTypes, ?string $nature, int $companyId): ?string
    {
        if ($nature || ! in_array(Product::TYPE_SALEABLE, $usageTypes)) {
            return $nature;
        }

        $company = \App\Models\Company::withoutGlobalScopes()->find($companyId);
        $options = $this->availableNaturesFor($company);

        if (count($options) === 1) {
            return $options[0];
        }

        $labels = ['tradable' => 'Tradable', 'manufactured' => 'Manufactured', 'service' => 'Service'];
        $optionList = implode(', ', array_map(fn ($o) => $labels[$o] ?? $o, $options));

        throw new \DomainException(
            "This product could be {$optionList} — please select which one it is before saving. " .
            'The correct inventory account can\'t be determined automatically when more than one option applies.'
        );
    }

    /** Mirrors the Vue form's availableNatures computed — see Products/Form.vue. */
    private function availableNaturesFor(?\App\Models\Company $company): array
    {
        $isManufacturing = $company?->isManufacturing() ?? false;
        $isTrading       = $company?->hasInventory() && ! $isManufacturing;
        $isServiceOnly   = ! ($company?->hasInventory() ?? false);

        if ($isManufacturing) return [Product::NATURE_TRADABLE, Product::NATURE_MANUFACTURED, Product::NATURE_SERVICE];
        if ($isTrading && ! ($company?->hasMixedInvoices() ?? false)) return [Product::NATURE_TRADABLE];
        if ($isServiceOnly) return [Product::NATURE_SERVICE];

        return [Product::NATURE_TRADABLE, Product::NATURE_SERVICE];
    }

    // ── Account Resolution ────────────────────────────────────────

    /**
     * Resolves all account IDs automatically from product type/nature.
     * Zero user input required.
     *
     * Revenue:   always 411 (Sales Local)
     * COGS:      tradable→511, manufactured→512, service→513
     * Inventory: raw_material/consumable → category's Raw Materials
     *            sub-account; tradable/manufactured → category's
     *            Finished Goods sub-account. See
     *            resolveCategoryInventoryAccount() — auto-created
     *            per category, not per product (confirmed with
     *            Mahmoud — companies can carry 10,000+ SKUs, so a
     *            sub-account per product is unworkable).
     */
    public function resolveAccounts(
        int    $companyId,
        ?int   $categoryId,
        array  $usageTypes,
        ?string $nature,
        ?int   $fixedAssetAccountId = null
    ): array {
        $get = fn(string $code) => ChartOfAccount::withoutGlobalScopes()
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->where('code', $code)
            ->value('id');

        // Revenue — always 411 Sales Local
        $revenue = $get('411');

        // COGS — from nature
        $cogs = match($nature) {
            Product::NATURE_TRADABLE     => $get('511'),
            Product::NATURE_MANUFACTURED => $get('512'),
            Product::NATURE_SERVICE      => $get('513'),
            default                      => $get('511'),
        };

        // Inventory — bucket determined by usage type / nature, account
        // resolved (and auto-created if missing) at the category level.
        $bucket = null;
        if (in_array(Product::TYPE_RAW_MATERIAL, $usageTypes) || in_array(Product::TYPE_CONSUMABLE, $usageTypes)) {
            $bucket = 'raw_material';
        } elseif (in_array($nature, [Product::NATURE_MANUFACTURED, Product::NATURE_TRADABLE], true)) {
            $bucket = 'finished_goods';
        }

        $inventory = ($bucket && $categoryId)
            ? $this->resolveCategoryInventoryAccount($companyId, $categoryId, $bucket)
            : null;

        // Fixed Assets module, Phase 1 — the ONLY account here the
        // user picks directly (not auto-derived from usage_type/nature
        // the way every other account above is). Its Accumulated
        // Depreciation counterpart IS auto-derived, by swapping the
        // 111xxx code's leading '111' for '113' (same category — e.g.
        // 111004 Vehicles → 113004). Confirmed with Mahmoud: one
        // shared account per category, always paired 1:1 — never
        // picked independently, to avoid a mismatched pairing (e.g.
        // Vehicles' cost account paired with Computer Equipment's
        // depreciation account).
        $accumulatedDepreciation = null;
        if ($fixedAssetAccountId) {
            $fixedAssetAccount = ChartOfAccount::withoutGlobalScopes()->find($fixedAssetAccountId);
            if ($fixedAssetAccount && str_starts_with($fixedAssetAccount->code, '111')) {
                $pairedCode = '113' . substr($fixedAssetAccount->code, 3);
                $accumulatedDepreciation = $get($pairedCode);
            }
        }

        return [
            'revenue'                  => $revenue,
            'cogs'                     => $cogs,
            'inventory'                => $inventory,
            'fixed_asset'              => $fixedAssetAccountId,
            'accumulated_depreciation' => $accumulatedDepreciation,
        ];
    }

    /**
     * Returns the category's own inventory sub-account for the given
     * bucket ('raw_material' → under global 135001, 'finished_goods'
     * → under global 135003) — creating it the first time it's
     * actually needed (first physical product of that bucket assigned
     * to the category or one of its sub-categories), never in advance
     * and never per-product.
     *
     * IMPORTANT (confirmed with Mahmoud, July 2026 — final design):
     * this always resolves to the MAIN Category's account, even when
     * the product is linked directly to a Sub-Category. Sub-Categories
     * never get their own Chart of Accounts entry — every category in
     * the COA sits at the same, consistent depth (Level 5), with no
     * exceptions. A Sub-Category is purely an organizational grouping
     * for the product list and for reporting (see the Category Value
     * Statement report, which shows Sub-Category detail as rows under
     * their Main Category) — it has no accounting-side existence of
     * its own. An earlier version of this method resolved to whichever
     * exact category level the product pointed to (so a Sub-Category
     * could get its own account); that was walked back in favor of
     * this simpler, fully consistent design.
     *
     * Confirmed with Mahmoud: a per-SKU sub-account is unworkable at
     * 10,000+ SKU scale; per-Main-Category is the right granularity.
     * Per-product inventory statements (quantity + value) don't depend
     * on this at all — they're read straight from stock_movements /
     * product_stock_balances, regardless of which GL account the
     * category posts to.
     */
    private function resolveCategoryInventoryAccount(int $companyId, int $categoryId, string $bucket): ?int
    {
        $column     = $bucket === 'raw_material' ? 'raw_material_account_id' : 'finished_goods_account_id';
        $parentCode = $bucket === 'raw_material' ? '135001' : '135003';

        $category = ProductCategory::withoutGlobalScopes()->find($categoryId);
        if (! $category) {
            return null;
        }

        // Always roll up to the Main Category — a Sub-Category (has a
        // parent_id) never gets its own account.
        if ($category->parent_id) {
            $category = ProductCategory::withoutGlobalScopes()->find($category->parent_id) ?? $category;
        }

        if ($category->{$column}) {
            return $category->{$column};
        }

        $parent = ChartOfAccount::withoutGlobalScopes()
            ->whereNull('company_id')
            ->where('code', $parentCode)
            ->first();

        if (! $parent) {
            return null;
        }

        $account = ChartOfAccount::create([
            'company_id'            => $companyId,
            'parent_id'             => $parent->id,
            'code'                  => ChartOfAccount::nextCode($parent->id, $companyId),
            'name_en'               => "{$parent->name_en} — {$category->name_en}",
            'name_ar'               => "{$parent->name_ar} — {$category->name_ar}",
            'level'                 => 5,
            'section'               => $parent->section,
            'class'                 => $parent->class,
            'account_type'          => $parent->account_type,
            'normal_balance'        => $parent->normal_balance,
            'is_system'             => false,
            'is_postable'           => true,
            'allow_currency'        => false,
            'requires_cost_center'  => false,
            'is_active'             => true,
        ]);

        $category->update([$column => $account->id]);

        return $account->id;
    }

    // ── Categories for selector ───────────────────────────────────

    public function categoriesForDropdown(int $companyId): \Illuminate\Database\Eloquent\Collection
    {
        return ProductCategory::with('children')
            ->main()
            ->active()
            ->orderBy('name_en')
            ->get();
    }

    // ── Fixed Asset Types (Settings → Fixed Asset Types, own page) ──

    /**
     * Fixed Asset "products" get their own dedicated, much simpler
     * Settings page — confirmed with Mahmoud after the combined-form
     * design (Fixed Asset as a 4th tile on the regular Products &
     * Services form) proved confusing: a Fixed Asset never has a UOM,
     * barcode, or selling price, so cramming it into that form's UOM/
     * Identity/Pricing sections just added noise. Under the hood this
     * still creates a real Product row (nature = 'fixed_asset') — the
     * whole PO → GRN → Bill → FixedAssetService pipeline already
     * built around that stays exactly as-is — but category_id and
     * primary_uom_id (both required, non-nullable columns on
     * products) are auto-resolved here rather than ever asked of the
     * user: a hidden system "Fixed Assets" category and the system
     * "UNIT" UOM, found-or-created once per company.
     */
    public function paginateFixedAssetTypes(int $companyId, array $filters = []): LengthAwarePaginator
    {
        return Product::with('fixedAssetAccount:id,code,name_en')
            ->where('nature', Product::NATURE_FIXED_ASSET)
            ->when($filters['search'] ?? null, fn ($q, $v) =>
                // Audit L-18: grouped, so the OR cannot escape the other filters.
                $q->where(fn ($w) => $w->where('name_en', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%"))
            )
            ->when($filters['status'] ?? null, fn ($q, $v) =>
                $q->where('is_active', $v === 'active')
            )
            ->orderBy('name_en')
            ->paginate(25)
            ->withQueryString();
    }

    public function createFixedAssetType(int $companyId, array $data, User $actor): Product
    {
        $fullData = $this->buildFixedAssetTypeData($companyId, $data);
        $fullData['use_auto_code'] = true;

        return $this->create($companyId, $fullData, $actor);
    }

    public function updateFixedAssetType(Product $product, array $data, User $actor): Product
    {
        // AUDIT M2 FIX (Sept 2026): only an existing Fixed Asset Type
        // may be edited here. Before, any product id (e.g. a stock
        // item with inventory) could be sent to this screen and was
        // silently converted into a fixed asset, moving its accounts.
        $this->assertIsFixedAssetType($product);

        return $this->update($product, $this->buildFixedAssetTypeData($product->company_id, $data), $actor);
    }

    /**
     * Refuses any product that is not a Fixed Asset Type
     * (nature = fixed_asset). Used by the Fixed Asset Types screen.
     */
    public function assertIsFixedAssetType(Product $product): void
    {
        if ($product->nature !== Product::NATURE_FIXED_ASSET) {
            throw new \DomainException('This product is not a Fixed Asset Type and cannot be edited from this screen.');
        }
    }

    private function buildFixedAssetTypeData(int $companyId, array $data): array
    {
        return [
            'name_en'                     => $data['name_en'],
            'name_ar'                     => $data['name_ar'] ?? null,
            'description'                 => $data['description'] ?? null,
            'category_id'                 => $this->resolveFixedAssetCategory($companyId)->id,
            'usage_types'                 => [Product::TYPE_FIXED_ASSET],
            'nature'                      => Product::NATURE_FIXED_ASSET,
            'primary_uom_id'              => $this->resolveUnitUom($companyId)->id,
            'fixed_asset_account_id'      => $data['fixed_asset_account_id'],
            'default_useful_life_months'  => $data['default_useful_life_months'],
            'default_residual_value'      => $data['default_residual_value'] ?? '0.00',
            'barcode'       => null,
            'weight'        => null,
            'weight_uom_id' => null,
            'reorder_level' => null,
            'reorder_qty'   => null,
        ];
    }

    /**
     * Finds or creates a single hidden "Fixed Assets" category per
     * company — the user never sees or picks this; it exists purely
     * because products.category_id is a required column. Created
     * inactive so it never leaks into the regular Products & Services
     * form's category dropdown (categoriesForDropdown() filters
     * .active()) — this category is a plumbing detail, not something
     * the user should ever see or choose. Idempotent, same reasoning
     * as resolveCategoryInventoryAccount()'s own find-or-create-on-
     * first-use pattern.
     */
    private function resolveFixedAssetCategory(int $companyId): ProductCategory
    {
        return ProductCategory::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'name_en' => 'Fixed Assets', 'level' => ProductCategory::LEVEL_MAIN],
            ['name_ar' => 'أصول ثابتة', 'is_active' => false]
        );
    }

    /**
     * The system "UNIT" UOM (seeded company_id = NULL, shared — see
     * UomSeeder) — used as every Fixed Asset Type's primary_uom_id
     * since a Fixed Asset never actually needs a unit of measure; the
     * column is simply required by the shared products table.
     */
    private function resolveUnitUom(int $companyId): \App\Models\UnitOfMeasure
    {
        $uom = \App\Models\UnitOfMeasure::withoutGlobalScopes()
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->where('code', 'UNIT')
            ->first();

        if (! $uom) {
            throw new \DomainException('System UOM "UNIT" is not seeded — cannot create a Fixed Asset Type.');
        }

        return $uom;
    }
}