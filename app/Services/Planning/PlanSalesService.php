<?php

namespace App\Services\Planning;

// ══════════════════════════════════════════════════════════════════
//  PlanSalesService
//
//  Sales step — updates the monthly price/volume grid seeded by
//  PlanService::addProduct(), and the per-product collection
//  tranches. Rows always already exist (one per month, per product)
//  by the time this service is used, so every write here is an
//  update, never an insert — no duplicate-row risk.
//
//  @layer    Service
//  @depends  PlanProduct, PlanSalesLine, PlanCollectionTranche, User, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\PlanCollectionTranche;
use App\Models\PlanProduct;
use App\Models\PlanSalesLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PlanSalesService
{
    /**
     * $lines = [['period_month' => int, 'price' => ..., 'volume' => ...,
     * 'local_pct' => ..., 'export_pct' => ...], ...] — one entry per
     * month being updated (the caller can send the whole grid at once
     * or just the changed months).
     */
    public function saveSalesLines(PlanProduct $planProduct, array $lines, User $actor): PlanProduct
    {
        return DB::transaction(function () use ($planProduct, $lines, $actor) {
            foreach ($lines as $line) {
                PlanSalesLine::where('plan_product_id', $planProduct->id)
                    ->where('period_month', $line['period_month'])
                    ->update([
                        'price'      => $line['price'] ?? 0,
                        'volume'     => $line['volume'] ?? 0,
                        'local_pct'  => $line['local_pct'] ?? 100,
                        'export_pct' => $line['export_pct'] ?? 0,
                    ]);
            }

            AuditLog::record($actor, 'plan.sales_lines_saved', $planProduct, null, ['months_updated' => count($lines)]);

            return $planProduct->fresh(['salesLines']);
        });
    }

    /**
     * Replaces every tranche for one (product, market) pair —
     * whole-set replace rather than per-row upsert, since tranches
     * as a set must sum to 100% and are edited together as one
     * schedule, not one row at a time.
     *
     * $tranches = [['pct' => ..., 'days_after_sale' => ...], ...]
     */
    public function saveCollectionTranches(PlanProduct $planProduct, string $market, array $tranches, User $actor): PlanProduct
    {
        $totalPct = array_sum(array_column($tranches, 'pct'));
        if (bccomp((string) $totalPct, '100', 2) !== 0) {
            throw new \DomainException("Collection tranches for {$market} sales must sum to 100% (got {$totalPct}%).");
        }

        return DB::transaction(function () use ($planProduct, $market, $tranches, $actor) {
            PlanCollectionTranche::where('plan_product_id', $planProduct->id)
                ->where('market', $market)
                ->delete();

            $rows = array_map(fn ($t) => [
                'company_id'       => $planProduct->company_id,
                'plan_product_id'  => $planProduct->id,
                'market'           => $market,
                'pct'              => $t['pct'],
                'days_after_sale'  => $t['days_after_sale'] ?? 0,
                'created_at'       => now(),
                'updated_at'       => now(),
            ], $tranches);

            PlanCollectionTranche::insert($rows);

            AuditLog::record($actor, 'plan.collection_tranches_saved', $planProduct, null, ['market' => $market, 'tranches' => $tranches]);

            return $planProduct->fresh(['collectionTranches']);
        });
    }
}
