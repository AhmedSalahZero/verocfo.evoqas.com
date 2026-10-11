<?php

namespace App\Services\Accounting;

// ══════════════════════════════════════════════════════════════════
//  FixedAssetService
//
//  Fixed Assets module — Phase 1. Business logic for the Asset
//  Register: creating individual assets on receipt, accumulating
//  cost from any contributing PO/GRN/Bill line, and posting/
//  reversing straight-line monthly depreciation.
//
//  Design confirmed with Mahmoud before building (see conversation):
//    - Fixed Asset purchasing reuses the existing Product/Purchase
//      Order/Goods Receipt Note/Supplier Bill engine — a Product with
//      nature = 'fixed_asset' is what routes a PO line here, exactly
//      the same "classification drives the accounting automatically"
//      philosophy already used for inventory/service lines.
//    - A PO line for "5 laptops" creates 5 separate FixedAsset rows
//      at receipt — never one combined row.
//    - Each asset is created 'capitalizing' (provisional cost) at
//      receipt, not only once a Bill confirms it — mirrors GRNI's
//      existing "goods received, bill not yet in hand" pattern, just
//      pointed at 111xxx instead of 135xxx.
//    - Cost keeps accumulating (freight, installation — from ANY
//      supplier/PO/Bill, not only the one that created the asset)
//      until the asset becomes 'in_service' (depreciation_start_date
//      passes, or the user manually locks it) — after that, further
//      cost is expensed, never capitalized (enforced here, not by the
//      caller).
//    - Depreciation posts automatically but is always reversible; a
//      late-arriving Bill that changes the cost after depreciation
//      already posted triggers a reverse-and-repost correction of
//      every affected period — never a silent edit.
//    - One shared GL account PER CATEGORY (111xxx/113xxx) — individual
//      assets are tracked here, in the Asset Register, never as their
//      own Chart of Accounts row.
//
//  Disposal, impairment and transfer (Audit H17, Sept 2026) live in
//  FixedAssetDisposalService; the last depreciation period here is
//  now net of any impairment (FixedAsset::depreciableBase()).
//
//  NOT in this phase (flagged, not forgotten):
//    - Wiring into GoodsReceiptNoteService::post() / SupplierBillService
//      ::post() and the Vue UI — this service is the engine; the
//      calling side (where a GRN/Bill line's product turns out to be
//      a Fixed Asset) is the next piece of work.
//
//  @layer    Service
//  @depends  FixedAsset, FixedAssetCostLine, FixedAssetDepreciationEntry,
//            Product, ChartOfAccount, GoodsReceiptNoteLine,
//            SupplierBillLine, JournalEntryService, AuditLog
// ══════════════════════════════════════════════════════════════════

use App\Models\AuditLog;
use App\Models\ChartOfAccount;
use App\Models\FixedAsset;
use App\Models\FixedAssetCostLine;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\GoodsReceiptNoteLine;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\SupplierBillLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FixedAssetService
{
    public function __construct(
        private JournalEntryService $journalEntryService,
    ) {}

    // ── List / Update ─────────────────────────────────────────────

    public function paginate(int $companyId, array $filters = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        return FixedAsset::with(['product:id,name_en,name_ar,code', 'fixedAssetAccount:id,code,name_en', 'currency:id,code'])
            ->when($filters['search'] ?? null, fn ($q, $v) =>
                // Audit L-18: grouped, so the OR cannot escape the other filters.
                $q->where(fn ($w) => $w->where('asset_code', 'like', "%{$v}%")
                  ->orWhere('name', 'like', "%{$v}%")
                  ->orWhere('serial_number', 'like', "%{$v}%"))
            )
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();
    }

    /**
     * Edits the asset's own register fields — useful life, residual
     * value, depreciation start date, notes. Deliberately does NOT
     * touch cost_basis here — that only ever changes through
     * addCostLine()/confirmCostLine(), so there's always a traceable
     * cost line behind every dollar of it.
     */
    public function update(FixedAsset $asset, array $data, User $actor): FixedAsset
    {
        $before = $asset->toArray();

        $asset->update([
            'name'                     => $data['name'] ?? $asset->name,
            'serial_number'            => $data['serial_number'] ?? $asset->serial_number,
            'residual_value'           => $data['residual_value'] ?? $asset->residual_value,
            'useful_life_months'       => $data['useful_life_months'] ?? $asset->useful_life_months,
            'depreciation_start_date'  => $data['depreciation_start_date'] ?? $asset->depreciation_start_date,
            'inventory_location_id'    => $data['inventory_location_id'] ?? $asset->inventory_location_id,
            'notes'                    => $data['notes'] ?? $asset->notes,
        ]);

        AuditLog::record($actor, 'fixed_asset.updated', $asset, $before, $asset->fresh()->toArray());

        return $asset->fresh();
    }

    /**
     * Posts one month's depreciation for every 'in_service' asset
     * whose depreciation_start_date has passed and hasn't already had
     * that period posted — the bulk "Run Depreciation" action.
     * Skips (never fails) any individual asset that has nothing left
     * to depreciate or already has this period posted, so one bad
     * asset never blocks the rest of the run.
     */
    public function runDepreciationForPeriod(int $companyId, Carbon $forDate, User $actor): array
    {
        $periodDate = $forDate->copy()->startOfMonth();

        $assets = FixedAsset::where('company_id', $companyId)
            ->where('status', FixedAsset::STATUS_IN_SERVICE)
            ->where('depreciation_start_date', '<=', $periodDate->copy()->endOfMonth())
            ->get();

        $posted = [];
        $skipped = [];

        foreach ($assets as $asset) {
            try {
                $entry = $this->postMonthlyDepreciation($asset, $actor, $periodDate);
                $posted[] = ['asset_code' => $asset->asset_code, 'amount' => $entry->amount];
            } catch (\DomainException $e) {
                $skipped[] = ['asset_code' => $asset->asset_code, 'reason' => $e->getMessage()];
            }
        }

        return ['posted' => $posted, 'skipped' => $skipped];
    }

    // ── Receipt: creates one FixedAsset row per unit ────────────────

    /**
     * Splits a Goods Receipt Note line into N individual FixedAsset
     * rows — one per unit of quantity, never one combined row (e.g.
     * "5 laptops" → 5 rows). Each starts 'capitalizing' at the line's
     * per-unit cost, with depreciation_start_date defaulted to
     * received_date + 1 month (editable afterward per asset).
     *
     * Does NOT post any journal entry itself — the caller (GRN
     * posting) aggregates this line's total debit into the same
     * shared journal entry as every other line on that receipt,
     * exactly the way it already aggregates inventory debits. See
     * class header for why this wiring isn't done yet.
     */
    public function receiveFromGrnLine(GoodsReceiptNoteLine $line, User $actor): Collection
    {
        $product = $line->product;

        if (! $product || ! $product->isFixedAsset()) {
            throw new \DomainException('This receipt line is not a Fixed Asset product — cannot register assets from it.');
        }

        if (! $product->fixed_asset_account_id || ! $product->accumulated_depreciation_account_id) {
            throw new \DomainException(
                "Product {$product->code} has no Fixed Asset / Accumulated Depreciation account assigned — cannot receive."
            );
        }

        $quantity = (int) $line->quantity;
        if ($quantity < 1) {
            throw new \DomainException('A Fixed Asset receipt line must have a whole-number quantity of at least 1.');
        }

        $unitCost = (string) $line->unit_cost;
        $receivedDate = $line->goodsReceiptNote->received_date ?? now();
        $depreciationStart = Carbon::parse($receivedDate)->addMonthNoOverflow()->startOfMonth();

        $currencyId = $line->goodsReceiptNote->purchaseOrder->currency_id ?? null;
        if (! $currencyId) {
            throw new \DomainException('Could not determine a currency for this receipt — the linked Purchase Order has none set.');
        }

        return DB::transaction(function () use ($line, $product, $actor, $quantity, $unitCost, $receivedDate, $depreciationStart, $currencyId) {
            $assets = new Collection();

            for ($i = 1; $i <= $quantity; $i++) {
                $asset = FixedAsset::create([
                    'company_id'                          => $line->company_id,
                    'asset_code'                           => FixedAsset::nextCode($line->company_id),
                    'product_id'                           => $product->id,
                    'name'                                 => $quantity > 1 ? "{$product->name_en} ({$i}/{$quantity})" : $product->name_en,
                    'fixed_asset_account_id'               => $product->fixed_asset_account_id,
                    'accumulated_depreciation_account_id'  => $product->accumulated_depreciation_account_id,
                    'currency_id'                          => $currencyId,
                    'inventory_location_id'                => $line->goodsReceiptNote->inventory_location_id,
                    'cost_basis'                           => $unitCost,
                    'residual_value'                       => $product->default_residual_value ?? '0.00',
                    'useful_life_months'                   => $product->default_useful_life_months ?? 60,
                    'depreciation_method'                  => FixedAsset::METHOD_STRAIGHT_LINE,
                    'received_date'                        => $receivedDate,
                    'depreciation_start_date'              => $depreciationStart,
                    'status'                               => FixedAsset::STATUS_CAPITALIZING,
                    'source_type'                          => 'goods_receipt_note_line',
                    'source_id'                            => $line->id,
                    'created_by'                           => $actor->id,
                ]);

                FixedAssetCostLine::create([
                    'company_id'                 => $line->company_id,
                    'fixed_asset_id'             => $asset->id,
                    'goods_receipt_note_line_id' => $line->id,
                    'cost_type'                  => FixedAssetCostLine::TYPE_ASSET_COST,
                    'amount'                     => $unitCost,
                    'is_provisional'             => true,
                    'description'                => "Received via {$line->goodsReceiptNote->grn_code}",
                    'created_by'                 => $actor->id,
                ]);

                AuditLog::record($actor, 'fixed_asset.received', $asset, null, $asset->fresh()->toArray());

                $assets->push($asset->fresh());
            }

            return $assets;
        });
    }

    // ── Cost accumulation ────────────────────────────────────────────

    /**
     * Adds a cost line to an EXISTING asset — from any supplier/PO/
     * Bill, not only the one that originally created it (confirmed
     * with Mahmoud: transportation/installation can come from a
     * different supplier entirely). Blocked once the asset is no
     * longer 'capitalizing' — the caller should expense the cost
     * instead in that case, this method simply refuses to capitalize it.
     */
    public function addCostLine(
        FixedAsset $asset,
        string $amount,
        string $costType,
        User $actor,
        ?GoodsReceiptNoteLine $grnLine = null,
        ?SupplierBillLine $billLine = null,
        bool $isProvisional = true,
        ?string $description = null,
    ): FixedAssetCostLine {
        if (! $asset->canStillCapitalize()) {
            throw new \DomainException(
                "Asset {$asset->asset_code} is already {$asset->status} — further cost must be expensed, not capitalized."
            );
        }

        if (! $grnLine && ! $billLine) {
            throw new \DomainException('A cost line must come from either a Goods Receipt Note line or a Supplier Bill line.');
        }

        return DB::transaction(function () use ($asset, $amount, $costType, $actor, $grnLine, $billLine, $isProvisional, $description) {
            $costLine = FixedAssetCostLine::create([
                'company_id'                 => $asset->company_id,
                'fixed_asset_id'              => $asset->id,
                'goods_receipt_note_line_id'  => $grnLine?->id,
                'supplier_bill_line_id'       => $billLine?->id,
                'cost_type'                   => $costType,
                'amount'                      => $amount,
                'is_provisional'              => $isProvisional,
                'description'                 => $description,
                'created_by'                  => $actor->id,
            ]);

            $before = $asset->toArray();
            $asset->update(['cost_basis' => bcadd((string) $asset->cost_basis, $amount, 2)]);

            AuditLog::record($actor, 'fixed_asset.cost_added', $asset, $before, $asset->fresh()->toArray());

            return $costLine;
        });
    }

    /**
     * Confirms (or corrects) a provisional cost line once its
     * Supplier Bill is matched. If the confirmed amount differs from
     * the provisional one, the asset's cost_basis is adjusted by the
     * difference. If depreciation has already posted using the old
     * cost, nothing posted is reversed or edited (audit M-20, IAS 8):
     * the remaining depreciable amount is spread over the remaining
     * months from the next period on.
     */
    public function confirmCostLine(FixedAssetCostLine $costLine, string $confirmedAmount, User $actor): FixedAssetCostLine
    {
        if (! $costLine->is_provisional) {
            throw new \DomainException('This cost line is already confirmed.');
        }

        return DB::transaction(function () use ($costLine, $confirmedAmount, $actor) {
            $asset = $costLine->fixedAsset;
            $delta = bcsub($confirmedAmount, (string) $costLine->amount, 2);

            $costLine->update([
                'amount'         => $confirmedAmount,
                'is_provisional' => false,
            ]);

            if (bccomp($delta, '0', 2) !== 0) {
                $before = $asset->toArray();
                $asset->update(['cost_basis' => bcadd((string) $asset->cost_basis, $delta, 2)]);
                AuditLog::record($actor, 'fixed_asset.cost_corrected', $asset, $before, $asset->fresh()->toArray());

                $this->reviseDepreciationProspectively($asset->fresh());
            }

            return $costLine->fresh();
        });
    }

    // ── In-service transition ────────────────────────────────────────

    /**
     * Locks the asset's cost basis — no further cost lines can be
     * capitalized onto it afterward (see addCostLine()'s guard). Can
     * be called manually by the user, or automatically once
     * depreciation_start_date has passed (that automatic check is a
     * scheduled job, not built in this phase — see class header).
     */
    public function markInService(FixedAsset $asset, User $actor): FixedAsset
    {
        if (! $asset->canStillCapitalize()) {
            throw new \DomainException("Asset {$asset->asset_code} is already {$asset->status}.");
        }

        $before = $asset->toArray();

        $asset->update([
            'status'       => FixedAsset::STATUS_IN_SERVICE,
            'in_service_at' => now(),
        ]);

        AuditLog::record($actor, 'fixed_asset.in_service', $asset, $before, $asset->fresh()->toArray());

        return $asset->fresh();
    }

    // ── Depreciation ──────────────────────────────────────────────────

    /**
     * Posts one period's straight-line depreciation. Mirrors
     * LeaseAmortizationService::postMonthlyWearDown() — the last
     * period absorbs any rounding residue so the asset always fully
     * depreciates to exactly (cost_basis − residual_value), never a
     * fraction of a cent short or over.
     */
    public function postMonthlyDepreciation(FixedAsset $asset, User $actor, ?Carbon $forDate = null): FixedAssetDepreciationEntry
    {
        if ($asset->status === FixedAsset::STATUS_DISPOSED) {
            throw new \DomainException('A disposed asset cannot have further depreciation posted.');
        }

        $forDate ??= now();
        $totalPeriods = $asset->totalDepreciationPeriods();
        $alreadyPosted = $asset->depreciationEntries()->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)->count();

        if ($alreadyPosted >= $totalPeriods) {
            throw new \DomainException("Asset {$asset->asset_code} is already fully depreciated — nothing left to post.");
        }

        $periodDate = Carbon::parse($forDate)->startOfMonth();

        $alreadyPostedThisPeriod = $asset->depreciationEntries()
            ->where('period_date', $periodDate->toDateString())
            ->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)
            ->exists();

        if ($alreadyPostedThisPeriod) {
            throw new \DomainException("Depreciation for {$periodDate->format('M Y')} is already posted for this asset.");
        }

        $periodNumber = $alreadyPosted + 1;
        $amount = $asset->monthlyDepreciationAmount();

        // Last period absorbs rounding residue — same principle as
        // Leasing's own wear-down schedule.
        if ($periodNumber === $totalPeriods) {
            $postedSoFar = (string) $asset->depreciationEntries()->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)->sum('amount');
            // Audit H17: net of any impairment already written off.
            $depreciableBase = $asset->depreciableBase();
            $amount = bcsub($depreciableBase, $postedSoFar, 2);
        }

        if (bccomp($amount, '0', 2) <= 0) {
            throw new \DomainException("Asset {$asset->asset_code} has nothing left to depreciate this period.");
        }

        $depreciationExpenseAccount = ChartOfAccount::findByCode($asset->company_id, '561');
        if (! $depreciationExpenseAccount) {
            throw new \DomainException('Depreciation expense account (561) is not seeded for this company — cannot post.');
        }

        return DB::transaction(function () use ($asset, $actor, $periodDate, $periodNumber, $amount, $depreciationExpenseAccount) {
            $entry = $this->journalEntryService->post(
                companyId: $asset->company_id,
                entryDate: $periodDate,
                lines: [
                    ['chart_of_account_id' => $depreciationExpenseAccount->id, 'debit' => $amount, 'credit' => '0', 'description' => "Period {$periodNumber} depreciation — {$asset->asset_code} {$asset->name}"],
                    ['chart_of_account_id' => $asset->accumulated_depreciation_account_id, 'debit' => '0', 'credit' => $amount, 'description' => "Period {$periodNumber} depreciation — {$asset->asset_code} {$asset->name}"],
                ],
                sourceType: 'fixed_asset',
                sourceId: $asset->id,
                narration: "Fixed Asset depreciation, period {$periodNumber} — {$asset->asset_code}",
                actor: $actor,
            );

            $depreciationEntry = FixedAssetDepreciationEntry::create([
                'company_id'      => $asset->company_id,
                'fixed_asset_id'  => $asset->id,
                'period_date'     => $periodDate,
                'amount'          => $amount,
                'journal_entry_id' => $entry->id,
                'status'          => FixedAssetDepreciationEntry::STATUS_POSTED,
                'created_by'      => $actor->id,
            ]);

            $asset->update([
                'accumulated_depreciation' => bcadd((string) $asset->accumulated_depreciation, $amount, 2),
            ]);

            AuditLog::record($actor, 'fixed_asset_depreciation.posted', $depreciationEntry, null, $depreciationEntry->fresh()->toArray());

            return $depreciationEntry;
        });
    }

    /**
     * Reverses one depreciation entry — mirrors
     * LeaseAmortizationService::reverseWearDown() exactly. Reduces
     * the asset's running accumulated_depreciation total back down,
     * never silently edits the entry itself.
     */
    public function reverseDepreciationEntry(FixedAssetDepreciationEntry $depreciationEntry, User $actor): FixedAssetDepreciationEntry
    {
        if ($depreciationEntry->status === FixedAssetDepreciationEntry::STATUS_REVERSED) {
            throw new \DomainException('This depreciation entry is already reversed.');
        }

        return DB::transaction(function () use ($depreciationEntry, $actor) {
            if ($depreciationEntry->journal_entry_id) {
                $je = JournalEntry::findOrFail($depreciationEntry->journal_entry_id);
                $this->journalEntryService->reverse($je, $actor, 'Fixed Asset depreciation entry reversed by user');
            }

            $depreciationEntry->update([
                'status'      => FixedAssetDepreciationEntry::STATUS_REVERSED,
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
            ]);

            $asset = $depreciationEntry->fixedAsset;
            $asset->update([
                'accumulated_depreciation' => bcsub((string) $asset->accumulated_depreciation, (string) $depreciationEntry->amount, 2),
            ]);

            AuditLog::record($actor, 'fixed_asset_depreciation.reversed', $depreciationEntry, null, $depreciationEntry->fresh()->toArray());

            return $depreciationEntry->fresh();
        });
    }

    /**
     * Audit M-20 (IAS 8.36 — change in accounting estimate): called by
     * confirmCostLine() when the confirmed cost differs from the
     * provisional one. Periods already posted are NEVER touched — they
     * may sit in closed months. If any depreciation has been posted, the
     * asset is flagged so that FixedAsset::monthlyDepreciationAmount()
     * spreads the remaining depreciable amount over the remaining months
     * from the next period on. With nothing posted yet, the normal
     * formula already uses the corrected cost.
     */
    private function reviseDepreciationProspectively(FixedAsset $asset): void
    {
        $hasPosted = $asset->depreciationEntries()
            ->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)
            ->exists();

        if ($hasPosted) {
            $asset->update(['cost_revised_at' => now()]);
        }
    }
}
