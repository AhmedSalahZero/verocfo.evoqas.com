<?php

namespace App\Services;

// ══════════════════════════════════════════════════════════════════
//  TaxService
//
//  All business logic for Tax Settings management.
//
//  getOrCreateSettings():
//    Returns company tax settings, creating defaults (0%) if none.
//
//  saveSettings():
//    Updates company default VAT and Debit WHT rates.
//
//  saveOverride():
//    Creates or updates a category/product tax override.
//    Validates: category_id XOR product_id (not both, not neither).
//
//  deleteOverride():
//    Removes a tax override row.
//
//  resolveVatRate() / resolveDebitWhtRate():
//    Used by the posting engine (Stream 4) to determine the
//    correct rate for a given product on a transaction.
//    Priority: product → category → company default.
//
//  v1.1 — Added AuditLog::record() to every financial write
//    (saveSettings, saveOverride, deleteOverride) — this service was
//    one of only 2 left in the whole codebase without it (audit
//    finding, Tier 3). deleteOverride() gained a User $actor
//    parameter it didn't have before; its one controller call site
//    updated to pass $request->user().
//
//  @layer    Service
//  @depends  CompanyTaxSetting, TaxOverride, Product, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\CompanyTaxSetting;
use App\Models\Product;
use App\Models\TaxOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TaxService
{
    // ── Get or create settings ────────────────────────────────────

    public function getOrCreateSettings(int $companyId): CompanyTaxSetting
    {
        return CompanyTaxSetting::firstOrCreate(
            ['company_id' => $companyId],
            ['vat_rate' => 0, 'debit_wht_rate' => 0]
        );
    }

    // ── Save company defaults ─────────────────────────────────────

    public function saveSettings(int $companyId, array $data, User $actor): CompanyTaxSetting
    {
        $settings = $this->getOrCreateSettings($companyId);
        $before = $settings->toArray();
        $settings->update([
            'vat_rate'       => bcadd((string)($data['vat_rate'] ?? 0), '0', 2),
            'debit_wht_rate' => bcadd((string)($data['debit_wht_rate'] ?? 0), '0', 2),
        ]);
        AuditLog::record($actor, 'tax_settings.updated', $settings, $before, $settings->fresh()->toArray());
        return $settings;
    }

    // ── Load all overrides ────────────────────────────────────────

    public function overrides(int $companyId): Collection
    {
        return TaxOverride::with(['category', 'product.primaryUom'])
            ->orderByRaw('category_id IS NULL, category_id')
            ->orderBy('product_id')
            ->get();
    }

    // ── Save override ─────────────────────────────────────────────

    public function saveOverride(int $companyId, array $data, User $actor): TaxOverride
    {
        $this->validateOverride($data);

        return DB::transaction(function () use ($companyId, $data, $actor) {
            // Determine lookup key
            $key = isset($data['product_id']) && $data['product_id']
                ? ['company_id' => $companyId, 'product_id'  => $data['product_id']]
                : ['company_id' => $companyId, 'category_id' => $data['category_id']];

            $before = TaxOverride::where($key)->first()?->toArray();

            $override = TaxOverride::updateOrCreate($key, [
                'category_id'    => $data['category_id'] ?? null,
                'product_id'     => $data['product_id']  ?? null,
                'vat_rate'       => isset($data['vat_rate'])       && $data['vat_rate']       !== ''
                                    ? bcadd((string)$data['vat_rate'], '0', 2)       : null,
                'debit_wht_rate' => isset($data['debit_wht_rate']) && $data['debit_wht_rate'] !== ''
                                    ? bcadd((string)$data['debit_wht_rate'], '0', 2) : null,
                'notes'          => $data['notes'] ?? null,
            ]);

            AuditLog::record(
                $actor,
                $before ? 'tax_override.updated' : 'tax_override.created',
                $override,
                $before,
                $override->fresh()->toArray()
            );

            return $override;
        });
    }

    // ── Delete override ───────────────────────────────────────────

    public function deleteOverride(int $companyId, int $overrideId, User $actor): void
    {
        $override = TaxOverride::where('company_id', $companyId)
            ->where('id', $overrideId)
            ->first();

        if (! $override) return;

        $before = $override->toArray();
        $override->delete();
        AuditLog::record($actor, 'tax_override.deleted', $override, $before, null);
    }

    // ── Rate resolution (used by posting engine — Stream 4) ───────

    /**
     * Resolves the VAT rate for a product at transaction time.
     * Priority: product override → category override → company default.
     * Returns string (bcmath compatible).
     */
    public function resolveVatRate(int $companyId, int $productId): string
    {
        // 1. Product-level override
        $override = TaxOverride::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->whereNotNull('vat_rate')
            ->value('vat_rate');

        if ($override !== null) return (string) $override;

        // 2. Category-level override
        $categoryId = Product::find($productId)?->category_id;
        if ($categoryId) {
            $override = TaxOverride::where('company_id', $companyId)
                ->where('category_id', $categoryId)
                ->whereNotNull('vat_rate')
                ->value('vat_rate');
            if ($override !== null) return (string) $override;
        }

        // 3. Company default
        return (string) (CompanyTaxSetting::where('company_id', $companyId)->value('vat_rate') ?? '0.00');
    }

    /**
     * Resolves the Debit WHT rate for a product at transaction time.
     * Same priority chain as resolveVatRate().
     */
    public function resolveDebitWhtRate(int $companyId, int $productId): string
    {
        $override = TaxOverride::where('company_id', $companyId)
            ->where('product_id', $productId)
            ->whereNotNull('debit_wht_rate')
            ->value('debit_wht_rate');

        if ($override !== null) return (string) $override;

        $categoryId = Product::find($productId)?->category_id;
        if ($categoryId) {
            $override = TaxOverride::where('company_id', $companyId)
                ->where('category_id', $categoryId)
                ->whereNotNull('debit_wht_rate')
                ->value('debit_wht_rate');
            if ($override !== null) return (string) $override;
        }

        return (string) (CompanyTaxSetting::where('company_id', $companyId)->value('debit_wht_rate') ?? '0.00');
    }

    // ── Private ───────────────────────────────────────────────────

    private function validateOverride(array $data): void
    {
        $hasCategory = ! empty($data['category_id']);
        $hasProduct  = ! empty($data['product_id']);

        if ($hasCategory && $hasProduct) {
            throw new \DomainException(
                'A tax override must target either a category OR a product — not both.'
            );
        }

        if (! $hasCategory && ! $hasProduct) {
            throw new \DomainException(
                'A tax override must target a category or a product.'
            );
        }

        $hasVat = isset($data['vat_rate'])       && $data['vat_rate']       !== '';
        $hasWht = isset($data['debit_wht_rate']) && $data['debit_wht_rate'] !== '';

        if (! $hasVat && ! $hasWht) {
            throw new \DomainException(
                'At least one rate (VAT or Debit WHT) must be specified in an override.'
            );
        }
    }
}