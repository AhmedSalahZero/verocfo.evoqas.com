<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Currency;
use App\Models\InventoryLocation;
use App\Models\Partner;
use App\Models\Product;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\Accounting\GoodsReceiptNoteService;
use App\Services\Accounting\PurchaseOrderService;
use App\Services\Accounting\BillOfMaterialService;
use App\Services\Accounting\JobOrderTemplateService;
use App\Services\Projects\SalesOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// ══════════════════════════════════════════════════════════════════
//  AccountingTestDataSeeder
//
//  Fills the gaps that make the Accounting forms untestable on a
//  fresh dev database. Auditing what a company actually had showed
//  reference data present (suppliers, ~100 postable accounts,
//  currencies, payment terms) but the *documents* those forms depend
//  on missing entirely — every company had 0 projects and 0 purchase
//  orders, so Supplier Bill's PO picker had nothing to offer and a
//  Goods bill could not be started at all (it needs an unbilled
//  Goods Receipt Note line, and there were no GRNs either).
//
//  WHY IT GOES THROUGH THE SERVICES rather than inserting rows:
//  a Purchase Order is not just a row. Its code is generated and
//  uniqueness-checked, its value is recalculated from its lines, its
//  receipt/billing statuses are owned by the GRN and Supplier Bill
//  flows, and posting a GRN moves stock and writes a journal entry.
//  Seeding rows directly would produce documents that look right in
//  a list and then behave wrongly the moment a form touches them —
//  which is worse than having no data. Everything below is created
//  the same way the controllers create it, so what you test against
//  is a real document.
//
//  IT ALSO CREATES ITS OWN PREREQUISITES. The first version refused to
//  run when a company had no supplier or no product — which was
//  exactly the case for SQUAD System Integrator, a company with zero
//  partners and zero products, i.e. the company most in need of test
//  data. A seeder that only works where data already exists is not
//  much of a seeder, so a missing supplier / category / product is now
//  created rather than reported.
//
//  The one thing it still will NOT invent is an account mapping. A
//  stock product needs an inventory account, and picking the wrong one
//  produces a company whose stock postings are quietly wrong — far
//  worse than a missing test fixture. It looks for a postable
//  inventory account by the standard codes and, if there genuinely
//  isn't one, skips the products/PO/GRN chain and says so; expense and
//  prepaid bills are still fully testable without it.
//
//  IDEMPOTENT: every record is tagged in its own code/notes with
//  TEST-DATA and re-running skips whatever already exists.
//
//  IT SEEDS EVERY ACTIVE COMPANY by default. The first version took a
//  single company id defaulting to 1, which quietly left every other
//  company untouched — including one created after the seeder ran,
//  whose Supplier dropdown was then empty with no obvious reason why.
//  Seeding all of them is the behaviour that matches what this is for:
//  making the forms testable, wherever you happen to be working.
//
//  USAGE
//    php artisan db:seed --class=AccountingTestDataSeeder
//    COMPANY_ID=3 php artisan db:seed --class=AccountingTestDataSeeder   (one company)
//
//  TO REMOVE: everything it creates carries 'TEST-DATA' in
//  internal_notes; see the query at the bottom of this file's header
//  comment in the repo history, or delete by that marker.
// ══════════════════════════════════════════════════════════════════

class AccountingTestDataSeeder extends Seeder
{
    private const MARKER = 'TEST-DATA — seeded for form testing';

    public function run(): void
    {
        $actor = User::query()->orderBy('id')->first();
        if (! $actor) {
            $this->command->error('No user to attribute the records to.');
            return;
        }

        $only = env('COMPANY_ID');

        $companies = Company::withoutGlobalScopes()
            ->when($only, fn ($q) => $q->where('id', (int) $only))
            ->where('is_active', true)
            ->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->command->error($only ? "Company {$only} not found or inactive." : 'No active companies.');
            return;
        }

        foreach ($companies as $company) {
            $this->seedCompany((int) $company->id, $company->name_en, $actor);
        }
    }

    private function seedCompany(int $companyId, string $companyName, User $actor): void
    {

        // The models' CompanyScope keys off auth() + the bound
        // currentCompanyId, both of which only exist during a real
        // request. Bind them here so the services below see exactly
        // the same scoped world a controller would. Rebound per company,
        // since one run now walks several of them.
        Auth::login($actor);
        app()->instance('currentCompanyId', $companyId);

        $this->command->info("Seeding Accounting test data for [{$companyId}] {$companyName}");

        // A currency is the one thing that cannot be invented: which
        // currency a company keeps its books in is a setup decision,
        // not a fixture.
        $currencyId = DB::table('company_active_currencies')
            ->where('company_id', $companyId)->where('is_functional', true)->value('currency_id')
            ?? DB::table('company_active_currencies')->where('company_id', $companyId)->value('currency_id');

        if (! $currencyId) {
            $this->command->error("Company {$companyId} has no active currency. Set one in Settings, then re-run.");
            return;
        }

        $supplier = $this->ensureSupplier($companyId);
        $location = $this->ensureInventoryLocation($companyId);

        $this->ensureCostCenter($companyId, $actor);
        $projects = $this->ensureProjects($companyId, $currencyId, $supplier, $actor);

        // Everything from here needs stock products, which need an
        // inventory account this seeder is not willing to guess.
        $products = $this->ensureProducts($companyId);

        if ($products->isEmpty()) {
            $this->command->warn('  purchase order  · skipped (no stock products)');
            $this->command->warn('  goods receipt   · skipped (no stock products)');
            $this->command->info('Done. Expense and Prepaid bills are testable; Goods bills need a product first.');
            return;
        }

        $pos = $this->ensurePurchaseOrders($companyId, $supplier, $currencyId, $products, $projects, $actor);
        $this->ensureGoodsReceipt($companyId, $pos, $supplier, $location, $products, $actor);

        // Sales Orders feed a second family of pickers — Job Order,
        // Stock Transfer, Customer Invoice — that a purchase-side
        // fixture alone leaves empty.
        $customer = $this->ensureCustomer($companyId);
        $this->ensureSalesOrders($companyId, $customer, $currencyId, $products, $projects, $actor);
        $this->ensureLandingCostSupplier($companyId);
        $this->ensureClearingAccount($companyId);
        $faProduct = $this->ensureFixedAssetProduct($companyId);
        $this->ensureFixedAssetPurchaseOrder($companyId, $supplier, $currencyId, $faProduct, $actor);
        $this->ensureManufacturedProduct($companyId, $products, $actor);
        $this->ensureJobOrderTemplate($companyId, $products, $actor);

        $this->command->info('Done. Re-running is safe — existing records are skipped.');
    }

    // ── Supplier ──────────────────────────────────────────────────
    private function ensureSupplier(int $companyId): Partner
    {
        $existing = Partner::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereJsonContains('types', 'supplier')->where('is_active', true)->orderBy('id')->first();

        if ($existing) {
            $this->command->line("  supplier        · using existing {$existing->code} — {$existing->name_en}");
            return $existing;
        }

        // ap_account_id is left null on purpose: every real supplier in
        // this database has it null too, so the payable account is
        // resolved from company defaults at posting time. Setting one
        // here would make the fixture behave unlike a real supplier.
        $partner = Partner::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'code'       => $this->nextPartnerCode($companyId),
            'types'      => ['supplier'],
            'name_en'    => 'Test Supplier Co.',
            'name_ar'    => 'شركة مورّد تجريبية',
            'email'      => 'supplier@test.local',
            'phone'      => '+20 100 000 0000',
            'country'    => 'Egypt',
            'notes'      => self::MARKER,
            'is_active'  => true,
        ]);

        $this->command->line("  supplier        · created {$partner->code} — Test Supplier Co.");
        return $partner;
    }

    private function nextPartnerCode(int $companyId): string
    {
        $last = Partner::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('code', 'like', 'P-%')->orderByDesc('code')->value('code');
        $n = $last ? ((int) substr($last, 2)) + 1 : 1;
        return 'P-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    // ── Customer ──────────────────────────────────────────────────
    private function ensureCustomer(int $companyId): Partner
    {
        $existing = Partner::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereJsonContains('types', 'customer')->where('is_active', true)->orderBy('id')->first();

        if ($existing) {
            $this->command->line("  customer        · using existing {$existing->code} — {$existing->name_en}");
            return $existing;
        }

        $partner = Partner::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'code'       => $this->nextPartnerCode($companyId),
            'types'      => ['customer'],
            'name_en'    => 'Test Customer Co.',
            'name_ar'    => 'شركة عميل تجريبية',
            'email'      => 'customer@test.local',
            'country'    => 'Egypt',
            'notes'      => self::MARKER,
            'is_active'  => true,
        ]);
        $this->command->line("  customer        · created {$partner->code} — Test Customer Co.");
        return $partner;
    }

    // ── Sales Orders ──────────────────────────────────────────────
    private function ensureSalesOrders(int $companyId, Partner $customer, int $currencyId, $products, $projects, User $actor): void
    {
        // Two of them, deliberately different. Stock Transfer's picker
        // asks for standalone().active() — a Sales Order with no project
        // and past draft — while Customer Invoice happily takes a
        // project-linked one. A single fixture satisfies one screen and
        // leaves the other empty, which is how the first pass shipped.
        $this->makeSalesOrder($companyId, $customer, $currencyId, $products, $actor,
            'Test SO — fabrication and supply', $projects[0]->id ?? null, false);

        $this->makeSalesOrder($companyId, $customer, $currencyId, $products, $actor,
            'Test SO — standalone stock order', null, true);
    }

    private function makeSalesOrder(int $companyId, Partner $customer, int $currencyId, $products, User $actor, string $subject, ?int $projectId, bool $activate): void
    {
        $exists = DB::table('sales_orders')->where('company_id', $companyId)
            ->where('subject', $subject)->exists();
        if ($exists) {
            $this->command->line('  sales order     · "' . $subject . '" already present, skipped');
            return;
        }

        $lines = [];
        foreach ($products->take(2) as $n => $product) {
            $lines[] = [
                'product_id'   => $product->id,
                'description'  => $product->name_en,
                'quantity'     => [40, 10][$n] ?? 5,
                'uom_id'       => $product->primary_uom_id,
                'unit_price'   => [3200.00, 850.00][$n] ?? 100,
                'discount_pct' => 0,
            ];
        }

        try {
            $service = app(SalesOrderService::class);
            $so = $service->create($companyId, [
                'project_id'      => $projectId,
                'partner_id'      => $customer->id,
                'subject'         => $subject,
                'description'     => self::MARKER,
                'currency_id'     => $currencyId,
                'exchange_rate'   => 1,
                'issuance_date'   => now()->subDays(20)->toDateString(),
                'start_date'      => now()->subDays(20)->toDateString(),
                'end_date'        => now()->addDays(90)->toDateString(),
                'internal_notes'  => self::MARKER,
                'lines'           => $lines,
            ], $actor);

            if ($activate) {
                $so = $service->activate($so, $actor);
            }
            $this->command->line("  sales order     · created {$so->so_code}" . ($activate ? ' (standalone, active)' : ' (project-linked)'));
        } catch (\Throwable $e) {
            $this->command->warn('  sales order     · skipped: ' . $e->getMessage());
        }
    }

    // ── Landing-cost supplier ─────────────────────────────────────
    // The Landing Cost form filters suppliers down to the ones flagged
    // as providing landing-cost services (freight, customs, clearance).
    // The flag is a property of a supplier, not a separate record, so
    // this marks one rather than inventing another partner.
    private function ensureLandingCostSupplier(int $companyId): void
    {
        $already = Partner::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('is_landing_cost_supplier', true)->exists();
        if ($already) {
            $this->command->line('  landing-cost sup· already flagged, skipped');
            return;
        }

        $p = Partner::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereJsonContains('types', 'supplier')->where('is_active', true)->orderBy('id')->first();
        if (! $p) return;

        $p->update(['is_landing_cost_supplier' => true]);
        $this->command->line("  landing-cost sup· flagged {$p->code} — {$p->name_en}");
    }

    // ── Landing-cost clearing sub-account ─────────────────────────
    // The Landing Cost form offers the children of account 146 (Landing
    // Cost Clearing) — the parent itself is not postable, so a company
    // with no sub-account under it has nothing to pick.
    private function ensureClearingAccount(int $companyId): void
    {
        $parent = DB::table('chart_of_accounts')
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where('code', '146')->first();

        if (! $parent) {
            $this->command->warn('  clearing acct   · skipped: no account 146 (Landing Cost Clearing)');
            return;
        }

        $exists = DB::table('chart_of_accounts')->where('company_id', $companyId)
            ->where('parent_id', $parent->id)->exists();
        if ($exists) {
            $this->command->line('  clearing acct   · already present, skipped');
            return;
        }

        DB::table('chart_of_accounts')->insert([
            'company_id'   => $companyId,
            'parent_id'    => $parent->id,
            'code'         => '146001',
            'name_en'      => 'Freight & Clearance Clearing',
            'name_ar'      => 'تسوية الشحن والتخليص',
            // Copied from the parent rather than hard-coded: a clearing
            // sub-account must sit in the same section/class/normal
            // balance as the account it hangs under, or the trial
            // balance stops adding up.
            'account_type'    => $parent->account_type,
            'normal_balance'  => $parent->normal_balance,
            'section'         => $parent->section,
            'class'           => $parent->class,
            'level'           => ($parent->level ?? 2) + 1,
            'is_postable'     => true,
            'allow_currency'  => 0,
            'is_system'       => 0,
            'requires_cost_center' => 0,
            'is_active'       => true,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
        $this->command->line('  clearing acct   · created 146001 under 146');
    }

    // ── Fixed-asset type product ──────────────────────────────────
    // Fixed Asset Receipt's type picker takes products whose nature is
    // fixed_asset — a different catalogue from the stock products above.
    private function ensureFixedAssetProduct(int $companyId): ?Product
    {
        $exists = Product::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('nature', Product::NATURE_FIXED_ASSET)->first();
        if ($exists) {
            $this->command->line('  fixed asset typ · already present, skipped');
            return $exists;
        }

        $account = fn (array $codes) => DB::table('chart_of_accounts')
            ->where('is_postable', true)
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->whereIn('code', $codes)->orderByRaw('company_id IS NULL')->value('id');

        // 111003 Machinery & Equipment, 113003 its accumulated
        // depreciation. Both are required — a fixed asset with no
        // depreciation account cannot be depreciated, and seeding one
        // that silently cannot is worse than seeding none.
        $assetAccount = $account(['111003', '111002', '111001']);
        $accumAccount = $account(['113003', '113002', '113001']);
        if (! $assetAccount || ! $accumAccount) {
            $this->command->warn('  fixed asset typ · skipped: no fixed-asset / accumulated-depreciation account pair');
            return null;
        }

        $uomId = DB::table('units_of_measure')
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))->value('id');

        $categoryId = DB::table('product_categories')->where('company_id', $companyId)->value('id');
        if (! $categoryId) {
            $this->command->warn('  fixed asset typ · skipped: no product category');
            return null;
        }

        $p = Product::withoutGlobalScopes()->create([
            'company_id'  => $companyId,
            'code'        => $this->nextProductCode($companyId),
            'category_id' => $categoryId,
            'name_en'     => 'Test Fixed Asset — Forklift',
            'name_ar'     => 'أصل ثابت تجريبي — رافعة شوكية',
            'description' => self::MARKER,
            'usage_types' => ['fixed_asset'],
            'nature'      => Product::NATURE_FIXED_ASSET,
            'has_stock'   => false,
            'primary_uom_id' => $uomId,
            'fixed_asset_account_id' => $assetAccount,
            'accumulated_depreciation_account_id' => $accumAccount,
            'default_useful_life_months' => 60,
            'default_residual_value' => 0,
            'is_active'   => true,
        ]);
        $this->command->line("  fixed asset typ · created {$p->code} — Forklift");
        return $p;
    }

    // ── PO carrying a fixed-asset line ────────────────────────────
    // Fixed Asset Receipt only offers POs that have at least one
    // fixed-asset line — never an ordinary inventory PO — so the two
    // POs above are invisible to it no matter how many there are.
    private function ensureFixedAssetPurchaseOrder(int $companyId, Partner $supplier, int $currencyId, ?Product $faProduct, User $actor): void
    {
        if (! $faProduct) return;

        $subject = 'Test PO — forklift purchase';
        if (PurchaseOrder::withoutGlobalScopes()->where('company_id', $companyId)->where('subject', $subject)->exists()) {
            $this->command->line('  fixed-asset PO  · already present, skipped');
            return;
        }

        $service = app(PurchaseOrderService::class);
        $po = $service->create($companyId, [
            'partner_id'    => $supplier->id,
            'subject'       => $subject,
            'description'   => self::MARKER,
            'currency_id'   => $currencyId,
            'exchange_rate' => 1,
            'issuance_date' => now()->subDays(15)->toDateString(),
            'start_date'    => now()->subDays(15)->toDateString(),
            'end_date'      => now()->addDays(45)->toDateString(),
            'internal_notes'=> self::MARKER,
            'lines'         => [[
                'product_id'  => $faProduct->id,
                'description' => $faProduct->name_en,
                'quantity'    => 1,
                'uom_id'      => $faProduct->primary_uom_id,
                'unit_price'  => 480000.00,
            ]],
        ], $actor);
        $po = $service->activate($po, $actor);
        $this->command->line("  fixed-asset PO  · created {$po->po_code} (active)");
    }

    // ── Manufactured product + its Bill of Materials ──────────────
    // Job Order and Production Order both filter the catalogue down to
    // nature = manufactured, a third product family separate from the
    // stock and fixed-asset ones. A manufactured product with no BOM is
    // still not usable — Production Order asks for the BOM too — so the
    // two are seeded as one unit.
    private function ensureManufacturedProduct(int $companyId, $components, User $actor): void
    {
        if ($components->isEmpty()) return;

        $existing = Product::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('nature', Product::NATURE_MANUFACTURED)->first();

        if (! $existing) {
            $src = $components->first();
            $existing = Product::withoutGlobalScopes()->create([
                'company_id'  => $companyId,
                'code'        => $this->nextProductCode($companyId),
                'category_id' => $src->category_id,
                'name_en'     => 'Test Manufactured — Welded Frame',
                'name_ar'     => 'منتج مصنّع تجريبي — هيكل ملحوم',
                'description' => self::MARKER,
                'usage_types' => ['saleable'],
                'nature'      => Product::NATURE_MANUFACTURED,
                'has_stock'   => true,
                'primary_uom_id'       => $src->primary_uom_id,
                'revenue_account_id'   => $src->revenue_account_id,
                'cogs_account_id'      => $src->cogs_account_id,
                'inventory_account_id' => $src->inventory_account_id,
                'is_active'   => true,
            ]);
            $this->command->line("  manufactured    · created {$existing->code} — Welded Frame");
        } else {
            $this->command->line('  manufactured    · already present, skipped');
        }

        if (DB::table('bill_of_materials')->where('company_id', $companyId)->where('product_id', $existing->id)->exists()) {
            $this->command->line('  bill of materials· already present, skipped');
            return;
        }

        // Section 5 (BOM Routing) needs an existing Process, Machine
        // (linked to an Overhead Pool), and Labor Category before a
        // BOM's Operation can reference them — none of this test
        // seeder's other data creates these, so a small, idempotent
        // (find-by-name-first) set is created here, once per company.
        $process = DB::table('processes')->where('company_id', $companyId)->where('name', 'Test Machining')->first();
        $process = $process
            ? \App\Models\Process::find($process->id)
            : app(\App\Services\Accounting\ProcessService::class)->create($companyId, ['name' => 'Test Machining'], $actor);

        $overheadPool = DB::table('overhead_pools')->where('company_id', $companyId)->where('name', 'Test Factory Overhead')->first();
        $overheadPool = $overheadPool
            ? \App\Models\OverheadPool::find($overheadPool->id)
            : app(\App\Services\Accounting\OverheadPoolService::class)->create($companyId, ['name' => 'Test Factory Overhead', 'allocation_base' => 'machine_hours'], $actor);

        $machine = DB::table('machines')->where('company_id', $companyId)->where('name', 'Test Machine')->first();
        $machine = $machine
            ? \App\Models\Machine::find($machine->id)
            : app(\App\Services\Accounting\MachineService::class)->create($companyId, [
                'name' => 'Test Machine', 'process_id' => $process->id, 'status' => 'active',
                'overhead_pool_ids' => [$overheadPool->id],
            ], $actor);

        $laborCategory = DB::table('labor_categories')->where('company_id', $companyId)->where('name', 'Test Operator')->first();
        $laborCategory = $laborCategory
            ? \App\Models\LaborCategory::find($laborCategory->id)
            : app(\App\Services\Accounting\LaborCategoryService::class)->create($companyId, ['name' => 'Test Operator'], $actor);

        try {
            $bom = app(BillOfMaterialService::class)->create($companyId, [
                'product_id'      => $existing->id,
                'output_quantity' => 1,
                'effective_date'  => now()->subDays(10)->toDateString(),
                'notes'           => self::MARKER,
                'operations'      => [[
                    'process_id'             => $process->id,
                    'default_machine_id'     => $machine->id,
                    'labor_category_id'      => $laborCategory->id,
                    'standard_labor_hours'   => 2,
                    'standard_machine_hours' => 1,
                ]],
                'lines'           => $components->take(2)->values()->map(fn ($c, $i) => [
                    'component_product_id' => $c->id,
                    'quantity_per_batch'   => [4, 8][$i] ?? 1,
                    'uom_id'               => $c->primary_uom_id,
                ])->all(),
            ], $actor);
            $this->command->line('  bill of materials· created for ' . $existing->code);
        } catch (\Throwable $e) {
            $this->command->warn('  bill of materials· skipped: ' . $e->getMessage());
        }
    }

    // ── Job Order template ────────────────────────────────────────
    // A reusable component list a Job Order can be started from. Its
    // own picker is separate from the BOM one — a template is a job
    // recipe, a BOM is a product recipe — so seeding a BOM does not
    // fill it.
    private function ensureJobOrderTemplate(int $companyId, $components, User $actor): void
    {
        if ($components->isEmpty()) return;

        if (DB::table('job_order_templates')->where('company_id', $companyId)->exists()) {
            $this->command->line('  job template    · already present, skipped');
            return;
        }

        try {
            app(JobOrderTemplateService::class)->create($companyId, [
                'name'      => 'Test Template — Frame assembly',
                'is_active' => true,
                'notes'     => self::MARKER,
                'lines'     => $components->take(2)->values()->map(fn ($c, $i) => [
                    'component_product_id' => $c->id,
                    'quantity_per_unit'    => [2, 6][$i] ?? 1,
                    'uom_id'               => $c->primary_uom_id,
                ])->all(),
            ], $actor);
            $this->command->line('  job template    · created "Frame assembly"');
        } catch (\Throwable $e) {
            $this->command->warn('  job template    · skipped: ' . $e->getMessage());
        }
    }

    // ── Inventory location ────────────────────────────────────────
    private function ensureInventoryLocation(int $companyId): ?InventoryLocation
    {
        $existing = InventoryLocation::withoutGlobalScopes()->where('company_id', $companyId)
            ->orderBy('id')->first();
        if ($existing) return $existing;

        $loc = InventoryLocation::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'code'       => 'LOC-TEST',
            'name_en'    => 'Test Warehouse',
            'name_ar'    => 'مخزن تجريبي',
            'type'       => 'master',
            'level'      => 1,
            'is_active'  => true,
        ]);
        $this->command->line('  location        · created LOC-TEST');
        return $loc;
    }

    // ── Products ──────────────────────────────────────────────────
    private function ensureProducts(int $companyId)
    {
        $existing = Product::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('has_stock', true)->where('is_active', true)->orderBy('id')->limit(2)->get();

        if ($existing->isNotEmpty()) {
            $this->command->line("  products        · using {$existing->count()} existing");
            return $existing;
        }

        // An account is available to a company when it is the company's
        // own or global (company_id null) — the same rule the app's
        // chart of accounts uses.
        $account = fn (array $codes) => DB::table('chart_of_accounts')
            ->where('is_postable', true)
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->whereIn('code', $codes)->orderByRaw('company_id IS NULL')->value('id');

        // Standard chart codes: 135003 Finished Goods, 135001 Raw
        // Materials. If neither is postable for this company there is no
        // defensible place to put received stock, so no product is made.
        $inventoryAccountId = $account(['135003', '135001']);
        if (! $inventoryAccountId) {
            $this->command->warn('  products        · skipped: no postable inventory account (135003 / 135001) for this company');
            return collect();
        }

        $cogsAccountId    = $account(['511', '510']);
        $revenueAccountId = $account(['411', '410']);
        $uomId = DB::table('units_of_measure')
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->whereIn('code', ['PCS', 'EA', 'UNIT'])->value('id')
            ?? DB::table('units_of_measure')
                ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))->value('id');

        if (! $uomId) {
            $this->command->warn('  products        · skipped: no unit of measure available');
            return collect();
        }

        $categoryId = DB::table('product_categories')->where('company_id', $companyId)->value('id')
            ?? DB::table('product_categories')->insertGetId([
                'company_id'               => $companyId,
                'name_en'                  => 'Test Category',
                'name_ar'                  => 'فئة تجريبية',
                'level'                    => 1,
                'finished_goods_account_id'=> $inventoryAccountId,
                'is_active'                => true,
                'created_at'               => now(),
                'updated_at'               => now(),
            ]);

        $made = collect();
        foreach ([['Test Product — Steel Sheet 2mm', 'منتج تجريبي — لوح صلب ٢مم'],
                  ['Test Product — Fixings Set',     'منتج تجريبي — طقم مثبتات']] as $i => [$en, $ar]) {
            $product = Product::withoutGlobalScopes()->create([
                'company_id'           => $companyId,
                'code'                 => $this->nextProductCode($companyId),
                'name_en'              => $en,
                'name_ar'              => $ar,
                'description'          => self::MARKER,
                'category_id'          => $categoryId,
                'usage_types'          => ['saleable'],
                'nature'               => Product::NATURE_TRADABLE,
                'has_stock'            => true,
                'primary_uom_id'       => $uomId,
                'revenue_account_id'   => $revenueAccountId,
                'cogs_account_id'      => $cogsAccountId,
                'inventory_account_id' => $inventoryAccountId,
                'is_active'            => true,
            ]);
            $this->command->line("  product         · created {$product->code} — {$en}");
            $made->push($product);
        }
        return $made;
    }

    private function nextProductCode(int $companyId): string
    {
        $last = Product::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('code', 'like', 'P-%')->orderByDesc('code')->value('code');
        $n = $last ? ((int) substr($last, 2)) + 1 : 1;
        return 'P-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
    }

    // ── Cost centre ───────────────────────────────────────────────
    private function ensureCostCenter(int $companyId, User $actor): ?CostCenter
    {
        $existing = CostCenter::withoutGlobalScopes()->where('company_id', $companyId)->first();
        if ($existing) {
            $this->command->line('  cost centre     · already present, skipped');
            return $existing;
        }

        $cc = CostCenter::withoutGlobalScopes()->create([
            'company_id' => $companyId,
            'code'       => 'CC-TEST',
            'name_en'    => 'Test Cost Centre',
            'name_ar'    => 'مركز تكلفة تجريبي',
            'is_active'  => true,
        ]);
        $this->command->line('  cost centre     · created CC-TEST');
        return $cc;
    }

    // ── Projects ──────────────────────────────────────────────────
    private function ensureProjects(int $companyId, int $currencyId, Partner $partner, User $actor)
    {
        $wanted = [
            ['TEST-PRJ-001', 'Test Project — Warehouse Fit-out', 'مشروع تجريبي — تجهيز مخزن', 750000],
            ['TEST-PRJ-002', 'Test Project — Fleet Renewal',     'مشروع تجريبي — تجديد الأسطول', 1200000],
        ];

        $out = collect();
        foreach ($wanted as [$code, $nameEn, $nameAr, $value]) {
            $p = Project::withoutGlobalScopes()->where('company_id', $companyId)
                ->where('contract_code', $code)->first();

            if (! $p) {
                $p = Project::withoutGlobalScopes()->create([
                    'company_id'     => $companyId,
                    'contract_code'  => $code,
                    'name_en'        => $nameEn,
                    'name_ar'        => $nameAr,
                    'description'    => self::MARKER,
                    'partner_id'     => $partner->id,
                    'contract_value' => $value,
                    'currency_id'    => $currencyId,
                    'exchange_rate'  => 1,
                    'start_date'     => now()->startOfYear()->toDateString(),
                    'end_date'       => now()->endOfYear()->toDateString(),
                    'status'         => 'active',
                    'created_by'     => $actor->id,
                ]);
                $this->command->line("  project         · created {$code}");
            } else {
                $this->command->line("  project         · {$code} already present, skipped");
            }
            $out->push($p);
        }
        return $out;
    }

    // ── Purchase Orders ───────────────────────────────────────────
    private function ensurePurchaseOrders(int $companyId, Partner $supplier, int $currencyId, $products, $projects, User $actor)
    {
        $service = app(PurchaseOrderService::class);

        $wanted = [
            ['Test PO — steel and fixings',   $projects[0]->id, [[400, 320.00], [120, 85.50]]],
            ['Test PO — site consumables',    null,             [[60, 145.00]]],
        ];

        $out = collect();
        foreach ($wanted as $i => [$subject, $projectId, $lineSpecs]) {
            $existing = PurchaseOrder::withoutGlobalScopes()->where('company_id', $companyId)
                ->where('subject', $subject)->first();

            if ($existing) {
                $this->command->line("  purchase order  · \"{$subject}\" already present, skipped");
                $out->push($existing);
                continue;
            }

            $lines = [];
            foreach ($lineSpecs as $n => [$qty, $price]) {
                $product = $products[$n % $products->count()];
                $lines[] = [
                    'product_id'    => $product->id,
                    'description'   => $product->name_en,
                    'quantity'      => $qty,
                    'uom_id'        => $product->primary_uom_id,
                    'unit_price'    => $price,
                    'discount_pct'  => 0,
                    'tolerance_pct' => 0,
                ];
            }

            $po = $service->create($companyId, [
                'project_id'      => $projectId,
                'partner_id'      => $supplier->id,
                'subject'         => $subject,
                'description'     => self::MARKER,
                'currency_id'     => $currencyId,
                'exchange_rate'   => 1,
                'issuance_date'   => now()->subDays(30)->toDateString(),
                'start_date'      => now()->subDays(30)->toDateString(),
                'end_date'        => now()->addDays(60)->toDateString(),
                'payment_term_id' => null,
                'internal_notes'  => self::MARKER,
                'lines'           => $lines,
            ], $actor);

            // A draft PO is invisible to Supplier Bill and GRN — both
            // only offer active ones — so activating is part of making
            // this data useful, not an extra flourish.
            $po = $service->activate($po, $actor);

            $this->command->line("  purchase order  · created {$po->po_code} (active)");
            $out->push($po);
        }
        return $out;
    }

    // ── Goods Receipt Note ────────────────────────────────────────
    private function ensureGoodsReceipt(int $companyId, $pos, Partner $supplier, InventoryLocation $location, $products, User $actor): void
    {
        $service = app(GoodsReceiptNoteService::class);
        $po = $pos->first();

        $already = DB::table('goods_receipt_notes')
            ->where('company_id', $companyId)->where('purchase_order_id', $po->id)->exists();
        if ($already) {
            $this->command->line('  goods receipt   · already present for that PO, skipped');
            return;
        }

        $poLines = DB::table('purchase_order_lines')->where('purchase_order_id', $po->id)->get();

        // Receive 60% of each ordered line, deliberately not all of it:
        // a partially received PO is the more interesting state to test
        // against (it exercises the "Partial" receipt badge, and leaves
        // the PO open so a second GRN can still be made), and it still
        // produces unbilled GRN lines for a Goods supplier bill.
        $lines = [];
        foreach ($poLines as $pl) {
            $lines[] = [
                'purchase_order_line_id' => $pl->id,
                'product_id'             => $pl->product_id,
                'description'            => $pl->description,
                'quantity'               => round($pl->quantity * 0.6, 4),
                'uom_id'                 => $pl->uom_id,
                'unit_cost'              => $pl->unit_price,
                'project_id'             => $po->project_id,
            ];
        }

        try {
            $grn = $service->create($companyId, [
                'purchase_order_id'     => $po->id,
                'partner_id'            => $supplier->id,
                'inventory_location_id' => $location->id,
                'received_date'         => now()->subDays(7)->toDateString(),
                'exchange_rate'         => 1,
                'supplier_reference'    => 'TEST-DN-0001',
                'internal_notes'        => self::MARKER,
                'lines'                 => $lines,
            ], $actor);

            // Only a POSTED GRN has billable lines — a draft one moves
            // no stock and is invisible to the Supplier Bill form, so
            // posting is what actually makes a Goods bill testable.
            $grn = $service->post($grn, $actor);

            $this->command->line("  goods receipt   · created {$grn->grn_code} (posted, 60% received)");
        } catch (\Throwable $e) {
            // Posting touches stock and the ledger, so it can legitimately
            // refuse (no open fiscal period, no inventory account on the
            // product…). Report it instead of aborting the whole seed —
            // the PO and projects above are already useful on their own.
            $this->command->warn('  goods receipt   · skipped: ' . $e->getMessage());
        }
    }
}
