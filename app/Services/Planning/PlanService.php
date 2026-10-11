<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanService
//
//  Setup + Products CRUD — shared by Annual Plan and Business Plan &
//  Valuation (Decision 1: one shared flow, two products, gated by
//  plan_type). AnnualPlanController and BusinessPlanController both
//  call this same service, each always passing its own fixed
//  plan_type — this service never infers plan_type from anything
//  else, so the two controllers can never accidentally cross-create
//  the wrong kind of plan.
//
//  Duration is enforced HERE, not just in the Form Request, since
//  it's a business rule tied to plan_type (Plan::ANNUAL_DURATION_YEARS
//  / BUSINESS_PLAN_MIN_YEARS / BUSINESS_PLAN_MAX_YEARS — spec
//  Decision 8: 3–10 years, not CFO Tools' wider 3–20).
//
//  Adding a product seeds one PlanSalesLine per month (all zero) and
//  one PlanCogsAssumption row up front, so the Sales/COGS wizard
//  steps are always editing existing rows (update-in-place), never
//  scattered insert-or-update logic guessing whether a row exists yet.
//
//  No DB::transaction()/AuditLog::record() needed on read methods
//  (list/find) — Rule 5 governs writes only, same posture Financial
//  Reports services already take (read-only, no audit trail needed).
//
//  @layer    Service
//  @depends  Plan, PlanProduct, PlanSalesLine, PlanCollectionTranche,
//            PlanCogsAssumption, Product, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanCogsAssumption;
use App\Models\PlanCollectionTranche;
use App\Models\PlanProduct;
use App\Models\PlanSalesLine;
use App\Models\Product;
use App\Models\User;
use App\Services\TaxService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlanService
{
    public function __construct(private TaxService $taxService) {}

    public function paginate(int $companyId, string $planType, array $filters = []): LengthAwarePaginator
    {
        return Plan::where('plan_type', $planType)
            ->with(['currency:id,code'])
            ->when($filters['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();
    }

    public function create(int $companyId, string $planType, array $data, User $actor): Plan
    {
        $durationYears = $this->resolveDurationYears($planType, $data['duration_years'] ?? null);

        return DB::transaction(function () use ($companyId, $planType, $data, $durationYears, $actor) {
            $startDate = $data['start_date'];
            $endDate = date('Y-m-t', strtotime("+{$durationYears} years -1 month", strtotime($startDate)));

            $plan = Plan::create([
                'company_id'                    => $companyId,
                'plan_code'                     => Plan::nextCode($companyId, $planType),
                'plan_type'                     => $planType,
                'name'                          => $data['name'],
                'currency_id'                   => $data['currency_id'],
                'start_date'                    => $startDate,
                'duration_years'                => $durationYears,
                'end_date'                      => $endDate,
                'business_type'                 => $data['business_type'],
                'corporate_tax_rate'            => $data['corporate_tax_rate'] ?? 0,
                'wacc_pct'                      => $planType === Plan::TYPE_BUSINESS_PLAN ? ($data['wacc_pct'] ?? 0) : null,
                'perpetual_growth_pct'          => $planType === Plan::TYPE_BUSINESS_PLAN ? ($data['perpetual_growth_pct'] ?? 0) : null,
                'manpower_annual_increase_pct'  => $data['manpower_annual_increase_pct'] ?? 0,
                'valuation_basis'               => $planType === Plan::TYPE_BUSINESS_PLAN ? ($data['valuation_basis'] ?? null) : null,
                'valuation_multiplier'          => $planType === Plan::TYPE_BUSINESS_PLAN ? ($data['valuation_multiplier'] ?? null) : null,
                'opening_balance_source'        => $data['opening_balance_source'] ?? Plan::OB_SOURCE_AUTO,
                'opening_balance_as_of_date'    => $startDate,
                'status'                        => Plan::STATUS_DRAFT,
                'created_by'                    => $actor->id,
            ]);

            AuditLog::record($actor, 'plan.created', $plan, null, $plan->toArray());

            return $plan->fresh(['currency']);
        });
    }

    public function update(Plan $plan, array $data, User $actor): Plan
    {
        $durationYears = $this->resolveDurationYears($plan->plan_type, $data['duration_years'] ?? $plan->duration_years);

        return DB::transaction(function () use ($plan, $data, $durationYears, $actor) {
            $before = $plan->toArray();

            $startDate = $data['start_date'] ?? $plan->start_date->toDateString();
            $endDate = date('Y-m-t', strtotime("+{$durationYears} years -1 month", strtotime($startDate)));

            $plan->update([
                'name'                          => $data['name'] ?? $plan->name,
                'currency_id'                   => $data['currency_id'] ?? $plan->currency_id,
                'start_date'                    => $startDate,
                'duration_years'                => $durationYears,
                'end_date'                      => $endDate,
                'business_type'                 => $data['business_type'] ?? $plan->business_type,
                'corporate_tax_rate'             => $data['corporate_tax_rate'] ?? $plan->corporate_tax_rate,
                'wacc_pct'                      => $plan->isBusinessPlan() ? ($data['wacc_pct'] ?? $plan->wacc_pct) : null,
                'perpetual_growth_pct'          => $plan->isBusinessPlan() ? ($data['perpetual_growth_pct'] ?? $plan->perpetual_growth_pct) : null,
                'manpower_annual_increase_pct'  => $data['manpower_annual_increase_pct'] ?? $plan->manpower_annual_increase_pct,
                'valuation_basis'               => $plan->isBusinessPlan() ? ($data['valuation_basis'] ?? $plan->valuation_basis) : null,
                'valuation_multiplier'          => $plan->isBusinessPlan() ? ($data['valuation_multiplier'] ?? $plan->valuation_multiplier) : null,
                'opening_balance_source'        => $data['opening_balance_source'] ?? $plan->opening_balance_source,
                'opening_balance_as_of_date'    => $startDate,
                'updated_by'                    => $actor->id,
            ]);

            AuditLog::record($actor, 'plan.updated', $plan, $before, $plan->fresh()->toArray());

            return $plan->fresh(['currency']);
        });
    }

    public function delete(Plan $plan, User $actor): void
    {
        if ($plan->status !== Plan::STATUS_DRAFT) {
            throw new \DomainException('Only a draft plan can be deleted.');
        }

        DB::transaction(function () use ($plan, $actor) {
            $before = $plan->toArray();
            $plan->delete();

            AuditLog::record($actor, 'plan.deleted', $plan, $before, null);
        });
    }

    // ── Products ──────────────────────────────────────────────────

    /**
     * Adds a product line — either picked from the real Products
     * table (product_id set) or typed in fresh, plan-only
     * (product_id null — confirmed, Decision 9: never written back
     * to the real Products table). Seeds a zero-filled PlanSalesLine
     * per month and a blank PlanCogsAssumption row so later steps
     * always update existing rows.
     */
    public function addProduct(Plan $plan, array $data, User $actor): PlanProduct
    {
        return DB::transaction(function () use ($plan, $data, $actor) {
            $fields = isset($data['product_id']) && $data['product_id']
                ? $this->resolveRealProductFields($plan, (int) $data['product_id'])
                : [
                    'name'     => $data['name'],
                    'nature'   => $data['nature'],
                    'uom_code' => $data['uom_code'] ?? null,
                    'vat_rate' => $data['vat_rate'] ?? 0,
                    'wht_rate' => $data['wht_rate'] ?? 0,
                ];

            $planProduct = PlanProduct::create([
                'company_id'  => $plan->company_id,
                'plan_id'     => $plan->id,
                'product_id'  => $data['product_id'] ?? null,
                ...$fields,
                'sort_order'  => $data['sort_order'] ?? ($plan->products()->max('sort_order') + 1),
            ]);

            $months = $plan->totalMonths();
            $salesRows = [];
            for ($m = 0; $m < $months; $m++) {
                $salesRows[] = [
                    'company_id'        => $plan->company_id,
                    'plan_product_id'   => $planProduct->id,
                    'period_month'      => $m,
                    'price'             => 0,
                    'volume'            => 0,
                    'local_pct'         => 100,
                    'export_pct'        => 0,
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ];
            }
            PlanSalesLine::insert($salesRows);

            // Default collection policy: 100% collected on the sale
            // itself (0 days) for both markets — same CASH_POLICY
            // default CFO Tools' own engine falls back to when a
            // product has no explicit tranches set yet.
            PlanCollectionTranche::insert([
                [
                    'company_id' => $plan->company_id, 'plan_product_id' => $planProduct->id,
                    'market' => PlanCollectionTranche::MARKET_LOCAL, 'pct' => 100, 'days_after_sale' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ],
                [
                    'company_id' => $plan->company_id, 'plan_product_id' => $planProduct->id,
                    'market' => PlanCollectionTranche::MARKET_EXPORT, 'pct' => 100, 'days_after_sale' => 0,
                    'created_at' => now(), 'updated_at' => now(),
                ],
            ]);

            PlanCogsAssumption::create([
                'company_id'       => $plan->company_id,
                'plan_product_id'  => $planProduct->id,
            ]);

            AuditLog::record($actor, 'plan.product_added', $planProduct, null, $planProduct->toArray());

            return $planProduct->fresh(['product', 'cogsAssumption', 'collectionTranches']);
        });
    }

    public function removeProduct(PlanProduct $planProduct, User $actor): void
    {
        DB::transaction(function () use ($planProduct, $actor) {
            $before = $planProduct->toArray();
            $planProduct->delete(); // cascades sales lines / tranches / cogs assumption

            AuditLog::record($actor, 'plan.product_removed', $planProduct, $before, null);
        });
    }

    /**
     * Resolves the frozen field values for a product picked from the
     * company's real Products table — Name/Nature/UOM/VAT/WHT are
     * copied in at add-time (Decision, September 2026: LOCKED — a
     * plan product picked from a real product always matches it
     * exactly at the moment it's added; the planner cannot override
     * these fields). VAT/WHT are resolved through the same
     * TaxService chain (product override → category override →
     * company default) used everywhere else in the app, so a plan
     * never shows a rate Settings wouldn't actually charge.
     *
     * Used both by the Setup page's live preview (before the planner
     * clicks "Add Product") and by addProduct() itself — the server
     * always re-resolves these independently rather than trusting
     * whatever the browser posts, so the "locked" guarantee can't be
     * bypassed by editing the request.
     */
    public function previewRealProduct(int $companyId, int $productId): array
    {
        $product = Product::where('company_id', $companyId)->findOrFail($productId);

        return [
            'name'     => $product->name_en,
            'nature'   => $this->mapProductNature($product),
            'uom_code' => $product->primaryUom?->code,
            'vat_rate' => $this->taxService->resolveVatRate($companyId, $productId),
            'wht_rate' => $this->taxService->resolveDebitWhtRate($companyId, $productId),
        ];
    }

    private function resolveRealProductFields(Plan $plan, int $productId): array
    {
        if ($plan->products()->where('product_id', $productId)->exists()) {
            throw ValidationException::withMessages(['product_id' => 'This product is already in this plan.']);
        }

        return $this->previewRealProduct($plan->company_id, $productId);
    }

    // Fixed-asset-nature products fall through to the default branch
    // and are rejected — they aren't sellable, so they can't become a
    // plan product (the Setup page's picker already excludes them;
    // this is the server-side backstop).
    private function mapProductNature(Product $product): string
    {
        return match ($product->nature) {
            Product::NATURE_MANUFACTURED => PlanProduct::NATURE_MANUFACTURING,
            Product::NATURE_SERVICE      => PlanProduct::NATURE_SERVICE,
            Product::NATURE_TRADABLE     => PlanProduct::NATURE_TRADING,
            default => throw ValidationException::withMessages(['product_id' => 'This product type cannot be added to a plan.']),
        };
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function resolveDurationYears(string $planType, ?int $requested): int
    {
        if ($planType === Plan::TYPE_ANNUAL) {
            return Plan::ANNUAL_DURATION_YEARS;
        }

        $years = $requested ?? Plan::BUSINESS_PLAN_MIN_YEARS;

        if ($years < Plan::BUSINESS_PLAN_MIN_YEARS || $years > Plan::BUSINESS_PLAN_MAX_YEARS) {
            throw new \DomainException(sprintf(
                'Business Plan & Valuation duration must be between %d and %d years.',
                Plan::BUSINESS_PLAN_MIN_YEARS,
                Plan::BUSINESS_PLAN_MAX_YEARS
            ));
        }

        return $years;
    }
}
